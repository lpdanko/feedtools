#!/usr/bin/env python3
"""Build a YML feed with prices and stock from master-mobile.ru."""

from __future__ import annotations

import argparse
import concurrent.futures
import csv
import datetime as dt
import hashlib
import html
import json
import logging
import os
import re
import ssl
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zipfile
from dataclasses import dataclass, field
from http.cookiejar import Cookie, CookieJar
from pathlib import Path
from typing import Iterable


BASE_URL = "https://master-mobile.ru/"
DEFAULT_SITEMAP_URL = "https://master-mobile.ru/sitemap.xml"
DEFAULT_USER_AGENT = "Mozilla/5.0 (compatible; MasterMobileYmlParser/1.0)"
DEFAULT_STORE_ID = "2"
DEFAULT_STORE_NAME = "ТК «Савеловский» Мобильный"
DEFAULT_SUPPLIER_CODE = "24"
DEFAULT_CORRECTED_SOURCE_URL = ""
NOIMAGE_PICTURE_PATH_SUFFIXES = (
    "/local/templates/new/images/noimage-big.webp",
    "/upload/dev2fun.imagecompress/webp/local/templates/new/images/noimage-big.webp",
)
XLSX_NS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
REL_NS = "{http://schemas.openxmlformats.org/package/2006/relationships}"
OFFICE_REL_NS = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}"
STORE_SELECT_URL = (
    "https://master-mobile.ru/local/templates/new/components/"
    "sotbit/regions.choose/location/ajax.php"
)
BITRIX_AJAX_URL = "https://master-mobile.ru/bitrix/services/main/ajax.php"


@dataclass(slots=True)
class Product:
    id: str
    url: str
    name: str
    price: str | None = None
    currency: str = "RUB"
    available: bool = False
    stock: int | None = None
    stock_text: str | None = None
    sku: str | None = None
    offer_id: str | None = None
    vendor: str | None = None
    description: str | None = None
    pictures: list[str] = field(default_factory=list)
    category_path: list[str] = field(default_factory=list)


@dataclass(slots=True)
class RobotsRule:
    pattern: str
    allow: bool


class RobotsRules:
    def __init__(self, user_agent: str) -> None:
        self.user_agent = user_agent.lower()
        self.rules: list[RobotsRule] = []

    @classmethod
    def from_text(cls, text: str, user_agent: str) -> "RobotsRules":
        robots = cls(user_agent)
        groups: list[tuple[list[str], list[RobotsRule]]] = []
        agents: list[str] = []
        rules: list[RobotsRule] = []

        for raw_line in text.splitlines():
            line = raw_line.split("#", 1)[0].strip()
            if not line or ":" not in line:
                continue
            key, value = line.split(":", 1)
            key = key.strip().lower()
            value = value.strip()

            if key == "user-agent":
                if agents and rules:
                    groups.append((agents, rules))
                    agents = []
                    rules = []
                agents.append(value.lower())
                continue

            if key in {"allow", "disallow"} and agents:
                if value:
                    rules.append(RobotsRule(pattern=value, allow=key == "allow"))

        if agents and rules:
            groups.append((agents, rules))

        matching_group = robots._select_group(groups)
        robots.rules = matching_group or []
        return robots

    def can_fetch(self, url: str) -> bool:
        parsed = urllib.parse.urlparse(url)
        target = parsed.path or "/"
        if parsed.query:
            target += "?" + parsed.query

        matched: tuple[int, bool] | None = None
        for rule in self.rules:
            if self._matches(rule.pattern, target):
                score = len(rule.pattern.rstrip("$"))
                if matched is None or score > matched[0] or (
                    score == matched[0] and rule.allow
                ):
                    matched = (score, rule.allow)
        return True if matched is None else matched[1]

    def _select_group(
        self, groups: list[tuple[list[str], list[RobotsRule]]]
    ) -> list[RobotsRule] | None:
        best_score = -1
        best_rules: list[RobotsRule] | None = None
        for agents, rules in groups:
            for agent in agents:
                score = self._agent_score(agent)
                if score > best_score:
                    best_score = score
                    best_rules = rules
        return best_rules

    def _agent_score(self, agent: str) -> int:
        if agent == "*":
            return 0
        if agent and agent in self.user_agent:
            return len(agent)
        return -1

    @staticmethod
    def _matches(pattern: str, target: str) -> bool:
        end_locked = pattern.endswith("$")
        if end_locked:
            pattern = pattern[:-1]
        regex = re.escape(pattern).replace(r"\*", ".*")
        if end_locked:
            regex += r"$"
        return re.match(regex, target) is not None


class HttpClient:
    def __init__(
        self,
        user_agent: str,
        timeout: float,
        delay: float,
        cache_dir: Path | None,
        retries: int,
        respect_robots: bool,
        insecure_tls: bool,
    ) -> None:
        self.timeout = timeout
        self.delay = delay
        self.cache_dir = cache_dir
        self.retries = retries
        self.user_agent = user_agent
        self.respect_robots = respect_robots
        self.insecure_tls = insecure_tls
        self._last_network_request = 0.0
        self._delay_lock = threading.Lock()
        self._robots: RobotsRules | None = None
        self._cookie_jar = CookieJar()
        self._cache_namespace = ""
        handlers: list[urllib.request.BaseHandler] = [
            urllib.request.HTTPCookieProcessor(self._cookie_jar)
        ]
        if self.insecure_tls:
            logging.warning("TLS certificate verification is disabled.")
            handlers.append(
                urllib.request.HTTPSHandler(context=ssl._create_unverified_context())
            )
        self._opener = urllib.request.build_opener(*handlers)
        if self.cache_dir:
            self.cache_dir.mkdir(parents=True, exist_ok=True)

    def load_robots(self, robots_url: str) -> None:
        if not self.respect_robots:
            return
        self._robots = RobotsRules.from_text(self.get_text(robots_url), self.user_agent)

    def can_fetch(self, url: str) -> bool:
        if not self.respect_robots or self._robots is None:
            return True
        return self._robots.can_fetch(url)

    def set_cookie(
        self,
        name: str,
        value: str,
        domain: str = ".master-mobile.ru",
        path: str = "/",
        secure: bool = True,
    ) -> None:
        cookie = Cookie(
            version=0,
            name=name,
            value=value,
            port=None,
            port_specified=False,
            domain=domain,
            domain_specified=True,
            domain_initial_dot=domain.startswith("."),
            path=path,
            path_specified=True,
            secure=secure,
            expires=None,
            discard=True,
            comment=None,
            comment_url=None,
            rest={},
            rfc2109=False,
        )
        self._cookie_jar.set_cookie(cookie)
        self._cache_namespace += f"|cookie:{name}={value}"

    def get_text(self, url: str) -> str:
        return self._request_text(url=url, data=None)

    def post_text(self, url: str, data: dict[str, str | int]) -> str:
        encoded = urllib.parse.urlencode(data).encode("utf-8")
        return self._request_text(
            url=url,
            data=encoded,
            extra_headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "X-Requested-With": "XMLHttpRequest",
            },
            cache_variant=urllib.parse.urlencode(sorted((str(k), str(v)) for k, v in data.items())),
        )

    def post_form_text(self, url: str, data: dict[str, str | int], referer: str = "") -> str:
        encoded = urllib.parse.urlencode(data).encode("utf-8")
        headers = {
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        }
        if referer:
            headers["Referer"] = referer
        return self._request_text(
            url=url,
            data=encoded,
            extra_headers=headers,
            cache_variant=urllib.parse.urlencode(sorted((str(k), str(v)) for k, v in data.items())),
        )

    def login(self, email: str, password: str) -> None:
        email = email.strip()
        if not email or not password:
            raise RuntimeError("Master Mobile login/password are empty.")

        auth_url = urllib.parse.urljoin(BASE_URL, "/auth/")
        cache_dir = self.cache_dir
        self.cache_dir = None
        try:
            page_html = self.get_text(auth_url)
            form_html, action = extract_auth_form(page_html)
            action_url = urllib.parse.urljoin(auth_url, action or "/auth/?login=yes")
            payload = hidden_form_values(form_html)
            payload.update(
                {
                    "AUTH_FORM": "Y",
                    "TYPE": "AUTH",
                    "backurl": "/personal/",
                    "USER_LOGIN": email,
                    "USER_PASSWORD": password,
                    "USER_REMEMBER": "Y",
                    "Login": "Войти",
                }
            )
            response_html = self.post_form_text(action_url, payload, referer=auth_url)
            if not is_logged_in_html(response_html):
                response_html = self.get_text(urllib.parse.urljoin(BASE_URL, "/personal/"))
        finally:
            self.cache_dir = cache_dir

        if not is_logged_in_html(response_html):
            error = clean_text(
                find_first(
                    r'<(?:div|p)[^>]+class=["\'][^"\']*(?:errortext|error|alert)[^"\']*["\'][^>]*>([\s\S]*?)</(?:div|p)>',
                    response_html,
                )
            )
            raise RuntimeError(f"Master Mobile authentication failed{': ' + error if error else ''}.")

        digest = hashlib.sha256(email.lower().encode("utf-8")).hexdigest()[:12]
        self._cache_namespace += f"|auth:{digest}"
        logging.info("Master Mobile account session started for %s", email)

    def bitrix_action(self, action: str, data: dict[str, str | int]) -> str:
        encoded = urllib.parse.urlencode(data).encode("utf-8")
        url = f"{BITRIX_AJAX_URL}?{urllib.parse.urlencode({'action': action})}"
        text = self._request_text(
            url=url,
            data=encoded,
            extra_headers={
                "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                "X-Requested-With": "XMLHttpRequest",
                "Accept": "application/json, text/javascript, */*; q=0.01",
            },
            cache_variant=urllib.parse.urlencode(sorted((str(k), str(v)) for k, v in data.items())),
        )
        try:
            payload = json.loads(text)
        except json.JSONDecodeError as exc:
            raise RuntimeError(f"Bitrix action {action} returned invalid JSON: {text[:300]}") from exc

        if payload.get("status") != "success":
            raise RuntimeError(f"Bitrix action {action} failed: {text[:500]}")

        result = payload.get("data")
        if not isinstance(result, str):
            raise RuntimeError(f"Bitrix action {action} returned non-HTML data: {text[:300]}")
        return result

    def select_store(self, store_id: str, region_id: str = "") -> None:
        if not store_id:
            return
        text = self.post_text(
            STORE_SELECT_URL,
            {"action": "setStore", "storeID": store_id, "region": region_id},
        )
        if '"success":true' not in text.replace(" ", "").lower():
            raise RuntimeError(f"failed to select store {store_id}: {text[:300]}")
        self._cache_namespace += f"|selected-store:{store_id}:{region_id}"

    def _request_text(
        self,
        url: str,
        data: bytes | None,
        extra_headers: dict[str, str] | None = None,
        cache_variant: str = "",
    ) -> str:
        if self.respect_robots and not self.can_fetch(url):
            raise RuntimeError(f"robots.txt blocks {url}")

        method = "POST" if data is not None else "GET"
        cache_key = f"{method}:{url}:{cache_variant}:{self._cache_namespace}"
        cache_path = self._cache_path(cache_key, url)
        if cache_path and cache_path.exists():
            return cache_path.read_text(encoding="utf-8", errors="replace")

        last_error: Exception | None = None
        for attempt in range(1, self.retries + 1):
            try:
                self._wait_before_network_request()
                headers = {
                    "User-Agent": self.user_agent,
                    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                }
                if extra_headers:
                    headers.update(extra_headers)
                request = urllib.request.Request(
                    url,
                    data=data,
                    headers=headers,
                )
                with self._opener.open(request, timeout=self.timeout) as response:
                    body = response.read()
                    charset = response.headers.get_content_charset() or "utf-8"
                    text = body.decode(charset, errors="replace")
                if cache_path:
                    cache_path.write_text(text, encoding="utf-8")
                return text
            except (urllib.error.URLError, TimeoutError, RuntimeError) as exc:
                last_error = exc
                if attempt < self.retries:
                    time.sleep(min(30.0, attempt * 2.0))
        raise RuntimeError(f"failed to fetch {url}: {last_error}") from last_error

    def _wait_before_network_request(self) -> None:
        if self.delay <= 0:
            return
        with self._delay_lock:
            passed = time.monotonic() - self._last_network_request
            if passed < self.delay:
                time.sleep(self.delay - passed)
            self._last_network_request = time.monotonic()

    def _cache_path(self, cache_key: str, url: str) -> Path | None:
        if not self.cache_dir:
            return None
        digest = hashlib.sha256(cache_key.encode("utf-8")).hexdigest()
        suffix = ".xml" if url.endswith(".xml") or "sitemap" in url else ".html"
        return self.cache_dir / f"{digest}{suffix}"


def clean_text(value: str | None) -> str:
    if not value:
        return ""
    value = re.sub(r"<script[\s\S]*?</script>", " ", value, flags=re.I)
    value = re.sub(r"<style[\s\S]*?</style>", " ", value, flags=re.I)
    value = re.sub(r"<[^>]+>", " ", value)
    value = html.unescape(value)
    return re.sub(r"\s+", " ", value).strip()


def find_first(pattern: str, text: str, flags: int = re.S | re.I) -> str | None:
    match = re.search(pattern, text, flags)
    if not match:
        return None
    return match.group(1)


def first_non_empty(*values: str | None) -> str:
    for value in values:
        cleaned = clean_text(value)
        if cleaned:
            return cleaned
    return ""


def extract_auth_form(page_html: str) -> tuple[str, str]:
    for form_match in re.finditer(r'<form\b[^>]*>[\s\S]*?</form>', page_html, flags=re.I):
        form_html = form_match.group(0)
        if "USER_LOGIN" not in form_html or "USER_PASSWORD" not in form_html:
            continue
        action = find_first(r'\baction=["\']([^"\']*)["\']', form_html, flags=re.I) or ""
        return form_html, html.unescape(action)
    return "", "/auth/?login=yes"


def hidden_form_values(form_html: str) -> dict[str, str]:
    values: dict[str, str] = {}
    for input_html in re.findall(r'<input\b[^>]*>', form_html, flags=re.I):
        input_type = (find_first(r'\btype=["\']([^"\']*)["\']', input_html, flags=re.I) or "").lower()
        if input_type and input_type != "hidden":
            continue
        name = find_first(r'\bname=["\']([^"\']+)["\']', input_html, flags=re.I)
        if not name:
            continue
        value = find_first(r'\bvalue=["\']([^"\']*)["\']', input_html, flags=re.I) or ""
        values[html.unescape(name)] = html.unescape(value)
    return values


def is_logged_in_html(page_html: str) -> bool:
    return bool(
        re.search(r'"USER_ID"\s*:\s*"[1-9]\d*"', page_html)
        or re.search(r"[?&]logout=yes\b", page_html, flags=re.I)
    )


def normalize_price(value: str | int | float | None) -> str | None:
    if value is None:
        return None
    raw = str(value)
    raw = html.unescape(raw)
    raw = raw.replace("\xa0", " ").replace(",", ".")
    raw = re.sub(r"[^\d.]", "", raw)
    if not raw:
        return None
    try:
        return f"{float(raw):.2f}"
    except ValueError:
        return None


def normalize_purchase_price(value: str | int | float | None) -> str | None:
    normalized = normalize_price(value)
    if normalized is None:
        return None
    if normalized.endswith(".00"):
        return normalized[:-3]
    return normalized.rstrip("0").rstrip(".")


def purchase_article_keys(article: str) -> list[str]:
    article = trim_offer_id(article)
    keys = [article]
    if article.isdigit() and len(article) < 6:
        keys.append(article.zfill(6))
    seen: set[str] = set()
    result: list[str] = []
    for key in keys:
        key = key.strip()
        if key and key not in seen:
            seen.add(key)
            result.append(key)
    return result


def absolute_url(url: str | None) -> str | None:
    if not url:
        return None
    return urllib.parse.urljoin(BASE_URL, html.unescape(url))


def is_noimage_picture_url(url: str | None) -> bool:
    url = (url or "").strip()
    if not url:
        return False
    parsed = urllib.parse.urlparse(url)
    path = urllib.parse.unquote(parsed.path or url).rstrip("/").lower()
    return any(path.endswith(suffix) for suffix in NOIMAGE_PICTURE_PATH_SUFFIXES)


def iter_xml_locs(xml_text: str, tag_name: str) -> Iterable[str]:
    root = ET.fromstring(xml_text)
    for node in root.iter():
        if node.tag.rsplit("}", 1)[-1] == tag_name and node.text:
            yield node.text.strip()


def is_product_url(url: str) -> bool:
    parsed = urllib.parse.urlparse(url)
    if parsed.netloc and parsed.netloc != "master-mobile.ru":
        return False
    path = parsed.path.rstrip("/")
    parts = [part for part in path.split("/") if part]
    return len(parts) >= 3 and parts[0] == "catalog" and parts[-1].isdigit()


def is_catalog_section_url(url: str) -> bool:
    parsed = urllib.parse.urlparse(url)
    if parsed.netloc and parsed.netloc != "master-mobile.ru":
        return False
    path = parsed.path.rstrip("/")
    parts = [part for part in path.split("/") if part]
    if not parts or parts[0] != "catalog":
        return False
    if path in {
        "/catalog",
        "/catalog/filtr",
        "/catalog/hit",
        "/catalog/new",
        "/catalog/stock",
        "/catalog/compare",
    }:
        return False
    if len(parts) >= 2 and parts[1] in {"brands", "devices"}:
        return False
    return not (len(parts) >= 3 and parts[-1].isdigit())


def discover_product_urls(client: HttpClient, sitemap_url: str) -> list[str]:
    seen_sitemaps: set[str] = set()
    seen_products: set[str] = set()
    products: list[str] = []

    def visit(url: str) -> None:
        if url in seen_sitemaps:
            return
        seen_sitemaps.add(url)
        logging.info("Reading sitemap: %s", url)
        text = client.get_text(url)
        root = ET.fromstring(text)
        root_name = root.tag.rsplit("}", 1)[-1]

        if root_name == "sitemapindex":
            for child_url in iter_xml_locs(text, "loc"):
                if "master-mobile.ru" in child_url:
                    visit(child_url)
            return

        if root_name != "urlset":
            return

        for loc in iter_xml_locs(text, "loc"):
            if not is_product_url(loc):
                continue
            normalized = loc.rstrip("/") + "/"
            if normalized not in seen_products:
                seen_products.add(normalized)
                products.append(normalized)

    visit(sitemap_url)
    return products


def discover_category_urls(client: HttpClient, sitemap_url: str) -> list[str]:
    seen_sitemaps: set[str] = set()
    seen_sections: set[str] = set()
    sections: list[str] = []

    def visit(url: str) -> None:
        if url in seen_sitemaps:
            return
        seen_sitemaps.add(url)
        logging.info("Reading sitemap: %s", url)
        text = client.get_text(url)
        root = ET.fromstring(text)
        root_name = root.tag.rsplit("}", 1)[-1]

        if root_name == "sitemapindex":
            for child_url in iter_xml_locs(text, "loc"):
                if "master-mobile.ru" in child_url:
                    visit(child_url)
            return

        if root_name != "urlset":
            return

        for loc in iter_xml_locs(text, "loc"):
            if not is_catalog_section_url(loc):
                continue
            normalized = loc.rstrip("/") + "/"
            if normalized not in seen_sections:
                seen_sections.add(normalized)
                sections.append(normalized)

    visit(sitemap_url)
    return leaf_category_urls(sections)


def leaf_category_urls(urls: list[str]) -> list[str]:
    paths = {urllib.parse.urlparse(url).path.rstrip("/") + "/" for url in urls}
    leaves: list[str] = []
    for url in urls:
        path = urllib.parse.urlparse(url).path.rstrip("/") + "/"
        has_child = any(other != path and other.startswith(path) for other in paths)
        if not has_child:
            leaves.append(url)
    return leaves


def load_urls_from_file(path: Path) -> list[str]:
    urls: list[str] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        urls.append(urllib.parse.urljoin(BASE_URL, line).rstrip("/") + "/")
    return urls


def parse_json_ld_product(page_html: str) -> dict:
    for match in re.finditer(
        r"<script[^>]+type=[\"']application/ld\+json[\"'][^>]*>([\s\S]*?)</script>",
        page_html,
        flags=re.I,
    ):
        raw = html.unescape(match.group(1).strip())
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            continue
        for item in flatten_json_ld(data):
            item_type = item.get("@type")
            if item_type == "Product" or (
                isinstance(item_type, list) and "Product" in item_type
            ):
                return item
    return {}


def flatten_json_ld(value: object) -> Iterable[dict]:
    if isinstance(value, dict):
        graph = value.get("@graph")
        if isinstance(graph, list):
            for item in graph:
                yield from flatten_json_ld(item)
        yield value
    elif isinstance(value, list):
        for item in value:
            yield from flatten_json_ld(item)


def parse_offer(offers: object) -> dict:
    if isinstance(offers, dict):
        return offers
    if isinstance(offers, list):
        for offer in offers:
            if isinstance(offer, dict):
                return offer
    return {}


def parse_product_id(url: str, page_html: str) -> str:
    from_url = urllib.parse.urlparse(url).path.rstrip("/").split("/")[-1]
    if from_url.isdigit():
        return from_url
    return (
        find_first(r'data-card=["\']detail["\'][^>]*data-(?:actual-)?id=["\'](\d+)["\']', page_html)
        or find_first(r'data-(?:actual-)?id=["\'](\d+)["\'][^>]*data-card=["\']detail["\']', page_html)
        or ""
    )


def parse_product_price(page_html: str, offer_price: object | None = None) -> str | None:
    for pattern in (
        r'<span[^>]+class=["\'][^"\']*m-price-item1[^"\']*["\'][^>]*>([\s\S]*?)</span>',
        r'<span[^>]+class=["\'][^"\']*prices__coast[^"\']*prices__coast--main[^"\']*["\'][^>]*>([\s\S]*?)</span>',
        r'<span[^>]+class=["\'][^"\']*pm-price__new[^"\']*["\'][^>]*>([\s\S]*?)</span>',
        r'<div[^>]+class=["\'][^"\']*payment-price__value--club[^"\']*["\'][^>]*>([\s\S]*?)</div>',
        r'<div[^>]+class=["\'][^"\']*payment-price__value--basic[^"\']*["\'][^>]*>([\s\S]*?)</div>',
    ):
        price = normalize_price(find_first(pattern, page_html))
        if price:
            return price
    return normalize_price(offer_price)


def parse_product_article(page_html: str) -> str | None:
    article = first_non_empty(
        find_first(
            r'<(?:div|span)[^>]+class=["\'][^"\']*product-info-sticker--article[^"\']*["\'][^>]*>[\s\S]*?'
            r'<span[^>]+class=["\'][^"\']*product-info-sticker__value[^"\']*["\'][^>]*>([\s\S]*?)</span>',
            page_html,
        ),
        find_first(
            r'<tr[^>]+data-tr=["\']CML2_ARTICLE["\'][^>]*>[\s\S]*?<td[^>]*>\s*Артикул\s*</td>[\s\S]*?'
            r'<td[^>]+class=["\'][^"\']*list-props[^"\']*["\'][^>]*>([\s\S]*?)</td>',
            page_html,
        ),
        find_first(
            r'<div[^>]+class=["\'][^"\']*preview-item__info-vendor[^"\']*["\'][^>]*>([\s\S]*?)</div>',
            page_html,
        ),
        find_first(
            r'Артикул\s*:\s*</span>[\s\S]{0,600}?'
            r'<span[^>]+class=["\'][^"\']*(?:product-info-sticker__value|[^"\']*value)[^"\']*["\'][^>]*>([\s\S]*?)</span>',
            page_html,
        ),
    )
    article = re.sub(r"^(?:Арт\.?|Артикул)\s*:\s*", "", article, flags=re.I).strip()
    return article or None


def parse_stock(product_id: str, page_html: str, availability: str | None) -> tuple[int | None, str | None, bool]:
    counts: list[int] = []
    if product_id:
        escaped_id = re.escape(product_id)
        store_pattern = (
            rf'data-mquantity-product=["\']{escaped_id}["\'][\s\S]{{0,3000}}?'
            r'data-store-count=["\'](\d+)["\']'
        )
        counts = [int(value) for value in re.findall(store_pattern, page_html, flags=re.I)]
    if not counts:
        counts = [int(value) for value in re.findall(r'data-store-count=["\'](\d+)["\']', page_html, flags=re.I)]
    if not counts:
        counts = [
            int(value)
            for value in re.findall(
                r'Наличие\s+в\s+магазине\s*:\s*<[^>]+>\s*(\d+)\s*шт',
                page_html,
                flags=re.I,
            )
        ]
    if not counts and product_id:
        escaped_id = re.escape(product_id)
        pattern = (
            rf'data-mquantity-product=["\']{escaped_id}["\'][\s\S]{{0,3000}}?'
            r'data-max-count=["\'](\d+)["\']'
        )
        counts = [int(value) for value in re.findall(pattern, page_html, flags=re.I)]
    if not counts:
        counts = [int(value) for value in re.findall(r'data-max-count=["\'](\d+)["\']', page_html, flags=re.I)]

    stock = max(counts) if counts else None
    stock_text = clean_text(
        find_first(r'<span[^>]+class=["\'][^"\']*mstock-status__text[^"\']*["\'][^>]*>([\s\S]*?)</span>', page_html)
    )
    if not stock_text:
        stock_text = clean_text(
            find_first(r'<span[^>]+class=["\'][^"\']*preview-item__available-value[^"\']*["\'][^>]*>([\s\S]*?)</span>', page_html)
        )

    availability_value = (availability or "").lower()
    text_value = (stock_text or "").lower()
    available = (
        (stock is not None and stock > 0)
        or availability_value.endswith("instock")
        or "в наличии" in text_value
        or "осталось" in text_value
    )
    if "outofstock" in availability_value or "нет в наличии" in text_value:
        available = False
        if stock is None:
            stock = 0

    return stock, stock_text or None, available


def parse_breadcrumb_categories(page_html: str, product_name: str) -> list[str]:
    names = [
        clean_text(value)
        for value in re.findall(
            r'<span[^>]*itemprop=["\']name["\'][^>]*>([\s\S]*?)</span>',
            page_html,
            flags=re.I,
        )
    ]
    result: list[str] = []
    seen_consecutive: str | None = None
    for name in names:
        if not name or name == seen_consecutive:
            continue
        seen_consecutive = name
        if name in {"Главная", "Каталог"}:
            continue
        if product_name and name.strip() == product_name.strip():
            continue
        result.append(name)
    return result


def parse_product_page(url: str, page_html: str) -> Product:
    json_ld = parse_json_ld_product(page_html)
    offer = parse_offer(json_ld.get("offers"))
    product_id = parse_product_id(url, page_html)

    name = clean_text(str(json_ld.get("name") or ""))
    if not name:
        name = clean_text(find_first(r'<div[^>]+class=["\'][^"\']*card__name[^"\']*["\'][^>]*>([\s\S]*?)</div>', page_html))
    if not name:
        name = f"Product {product_id}"

    brand = json_ld.get("brand")
    vendor = None
    if isinstance(brand, dict):
        vendor = clean_text(str(brand.get("name") or ""))
    elif isinstance(brand, str):
        vendor = clean_text(brand)

    price = parse_product_price(page_html, offer.get("price"))

    images = json_ld.get("image")
    if isinstance(images, str):
        picture_values = [images]
    elif isinstance(images, list):
        picture_values = [str(item) for item in images if item]
    else:
        picture_values = []
    pictures = [
        url
        for url in (absolute_url(value) for value in picture_values)
        if url and not is_noimage_picture_url(url)
    ]

    availability = str(offer.get("availability") or "")
    stock, stock_text, available = parse_stock(product_id, page_html, availability)

    sku = parse_product_article(page_html) or clean_text(str(json_ld.get("sku") or json_ld.get("mpn") or ""))

    description = clean_text(str(json_ld.get("description") or ""))
    currency = clean_text(str(offer.get("priceCurrency") or "RUB")) or "RUB"

    return Product(
        id=product_id,
        url=url,
        name=name,
        price=price,
        currency=currency,
        available=available,
        stock=stock,
        stock_text=stock_text,
        sku=sku or None,
        vendor=vendor or None,
        description=description or None,
        pictures=pictures,
        category_path=parse_breadcrumb_categories(page_html, name),
    )


def split_product_cards(page_html: str) -> Iterable[str]:
    starts = [
        match.start()
        for match in re.finditer(
            r'<div[^>]+class=["\'][^"\']*catalog__type-block__item-container\b',
            page_html,
            flags=re.I,
        )
    ]
    if not starts:
        return
    pagination_start = page_html.find('<div class="catalog__pagination"', starts[-1])
    for index, start in enumerate(starts):
        if index + 1 < len(starts):
            end = starts[index + 1]
        elif pagination_start != -1:
            end = pagination_start
        else:
            end = len(page_html)
        yield page_html[start:end]


def parse_listing_price(card_html: str) -> str | None:
    return parse_product_price(card_html)


def parse_listing_picture(card_html: str) -> str | None:
    for value in re.findall(r'<img[^>]+(?:data-src|src)=["\']([^"\']+)["\']', card_html, flags=re.I):
        if value.startswith("data:"):
            continue
        url = absolute_url(value)
        if url and not is_noimage_picture_url(url):
            return url
    return None


def parse_listing_product(card_html: str, category_path: list[str]) -> Product | None:
    product_id = (
        find_first(r'data-actual-id=["\'](\d+)["\']', card_html)
        or find_first(r'data-id=["\'](\d+)["\']', card_html)
        or ""
    )
    link_match = re.search(
        r'<a\b(?=[^>]*class=["\'][^"\']*preview-item__name[^"\']*["\'])(?=[^>]*href=["\']([^"\']+)["\'])[^>]*>([\s\S]*?)</a>',
        card_html,
        flags=re.I,
    )
    if not product_id or not link_match:
        return None

    url = absolute_url(link_match.group(1)) or ""
    name = clean_text(link_match.group(2))
    if not name:
        return None

    stock_value = find_first(r'data-store-count=["\'](\d+)["\']', card_html)
    stock = int(stock_value) if stock_value is not None else None
    available = bool(stock and stock > 0)
    sku = parse_product_article(card_html)
    picture = parse_listing_picture(card_html)

    status = clean_text(
        find_first(
            r'<(?:span|div)[^>]+class=["\'][^"\']*(?:preview-item__available-value|card__aviable)[^"\']*["\'][^>]*>([\s\S]*?)</(?:span|div)>',
            card_html,
        )
    )
    if stock is not None:
        status = f"{DEFAULT_STORE_NAME}: {stock} шт."

    return Product(
        id=product_id,
        url=url,
        name=name,
        price=parse_listing_price(card_html),
        currency="RUB",
        available=available,
        stock=stock,
        stock_text=status or None,
        sku=sku,
        pictures=[picture] if picture else [],
        category_path=category_path,
    )


def parse_category_page(page_html: str, category_url: str) -> tuple[list[Product], int, str]:
    category_path = parse_breadcrumb_categories(page_html, "")
    products = [
        product
        for card in split_product_cards(page_html)
        if (product := parse_listing_product(card, category_path))
    ]
    max_page = 1
    pagen = "1"
    pagination_input = find_first(
        r'(<input[^>]+name=["\']paginationInput["\'][^>]*>)',
        page_html,
        flags=re.I,
    )
    if pagination_input:
        max_page = int(find_first(r'data-max-count=["\'](\d+)["\']', pagination_input) or "1")
        pagen = find_first(r'data-pagen=["\'](\d+)["\']', pagination_input) or "1"
    else:
        max_page = int(find_first(r'id=["\']activeAutoload["\'][^>]+data-max-page=["\'](\d+)["\']', page_html) or "1")
        pagen = find_first(r'id=["\']activeAutoload["\'][^>]+data-show-more=["\'](\d+)["\']', page_html) or "1"

    if not products:
        logging.debug("No products found on category page: %s", category_url)
    return products, max_page, pagen


def fetch_category_page(client: HttpClient, url: str, page: int, pagen: str = "1") -> str:
    if page <= 1:
        return client.get_text(url)
    return client.post_text(url, {f"PAGEN_{pagen}": page})


def category_ids(products: list[Product]) -> dict[tuple[str, ...], int]:
    ids: dict[tuple[str, ...], int] = {}
    next_id = 1
    for product in products:
        for depth in range(1, len(product.category_path) + 1):
            key = tuple(product.category_path[:depth])
            if key not in ids:
                ids[key] = next_id
                next_id += 1
    return ids


def add_text(parent: ET.Element, tag: str, value: object | None) -> ET.Element | None:
    if value is None or value == "":
        return None
    node = ET.SubElement(parent, tag)
    node.text = str(value)
    return node


def build_yml(products: list[Product]) -> ET.ElementTree:
    now = dt.datetime.now().strftime("%Y-%m-%d %H:%M")
    root = ET.Element("yml_catalog", {"date": now})
    shop = ET.SubElement(root, "shop")
    add_text(shop, "name", "Master Mobile")
    add_text(shop, "company", "MASTER MOBILE")
    add_text(shop, "url", BASE_URL)

    currencies = ET.SubElement(shop, "currencies")
    ET.SubElement(currencies, "currency", {"id": "RUB", "rate": "1"})

    ids = category_ids(products)
    categories = ET.SubElement(shop, "categories")
    for key, category_id in ids.items():
        attrs = {"id": str(category_id)}
        if len(key) > 1:
            attrs["parentId"] = str(ids[key[:-1]])
        category = ET.SubElement(categories, "category", attrs)
        category.text = key[-1]

    offers = ET.SubElement(shop, "offers")
    for product in products:
        attrs = {"id": product.offer_id or product.id or stable_offer_id(product.url)}
        attrs["available"] = "true" if product.available else "false"
        offer = ET.SubElement(offers, "offer", attrs)
        add_text(offer, "url", product.url)
        add_text(offer, "price", product.price)
        add_text(offer, "price_original", product.price)
        add_text(offer, "currencyId", product.currency)
        if product.category_path:
            add_text(offer, "categoryId", ids[tuple(product.category_path)])
        add_text(offer, "name", product.name)
        add_text(offer, "vendor", product.vendor)
        add_text(offer, "vendorCode", product.sku)
        for picture in product.pictures[:10]:
            add_text(offer, "picture", picture)
        add_text(offer, "description", product.description)
        if product.stock is not None:
            add_text(offer, "stock", product.stock)
            add_text(offer, "count", product.stock)
            stock_param = ET.SubElement(offer, "param", {"name": "Остаток"})
            stock_param.text = str(product.stock)
        if product.stock_text:
            stock_text_param = ET.SubElement(offer, "param", {"name": "Статус наличия"})
            stock_text_param.text = product.stock_text

    return ET.ElementTree(root)


def stable_offer_id(url: str) -> str:
    return hashlib.sha1(url.encode("utf-8")).hexdigest()[:12]


def write_xml(tree: ET.ElementTree, output: Path) -> None:
    ET.indent(tree, space="  ")
    output.parent.mkdir(parents=True, exist_ok=True)
    tmp = output.with_name(f".{output.name}.tmp")
    tree.write(tmp, encoding="utf-8", xml_declaration=True)
    tmp.replace(output)


def normalized_product_url(value: str) -> str:
    value = (value or "").strip()
    return value.rstrip("/") + "/" if value else ""


def article_map_from_csv(path: Path) -> dict[str, str]:
    mapping: dict[str, str] = {}
    if not path.is_file():
        return mapping
    with path.open("r", encoding="utf-8-sig", newline="") as fh:
        reader = csv.DictReader(fh)
        for row in reader:
            url = normalized_product_url(row.get("url") or row.get("product_url") or "")
            article = (
                row.get("new_article")
                or row.get("new_sticker_article")
                or row.get("vendor_code")
                or row.get("vendorCode")
                or ""
            ).strip()
            if url and article:
                mapping[url] = article
    return mapping


def article_map_from_feed(path: Path) -> dict[str, str]:
    mapping: dict[str, str] = {}
    if not path.is_file():
        return mapping
    for _event, offer in ET.iterparse(path, events=("end",)):
        if offer.tag != "offer":
            continue
        url = normalized_product_url(child_text(offer, "url"))
        article = param_text(offer, "Артикул стикер") or child_text(offer, "vendorCode")
        if url and article:
            mapping[url] = article
        offer.clear()
    return mapping


def products_from_article_feed(path: Path, supplier_code: str) -> list[Product]:
    products: list[Product] = []
    seen_urls: set[str] = set()
    if not path.is_file():
        return products

    for _event, offer in ET.iterparse(path, events=("end",)):
        if offer.tag != "offer":
            continue

        url = normalized_product_url(child_text(offer, "url"))
        product_id = product_id_from_url(url)
        if not url or not product_id or url in seen_urls:
            offer.clear()
            continue

        article = (
            param_text(offer, "Артикул стикер")
            or child_text(offer, "vendorCode")
            or base_offer_id(offer.get("id", ""), supplier_code)
        ).strip()
        article = base_offer_id(article, supplier_code)
        name = child_text(offer, "name") or article or product_id
        products.append(
            Product(
                id=product_id,
                url=url,
                name=name,
                price=None,
                currency=child_text(offer, "currencyId") or "RUB",
                available=False,
                sku=article or None,
                offer_id=article or None,
                vendor=child_text(offer, "vendor") or None,
            )
        )
        seen_urls.add(url)
        offer.clear()
    return products


def download_article_feed(source: str, timeout: float, insecure: bool) -> Path:
    target = Path(tempfile.mkdtemp(prefix="master_mobile_articles_")) / "source.xml"
    ctx = ssl._create_unverified_context() if insecure else None
    request = urllib.request.Request(
        source,
        headers={"User-Agent": DEFAULT_USER_AGENT},
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout, context=ctx) as response:
            with target.open("wb") as fh:
                while True:
                    chunk = response.read(1024 * 1024)
                    if not chunk:
                        break
                    fh.write(chunk)
    except Exception:
        cleanup_downloaded_article_feed(target)
        raise
    return target


def cleanup_downloaded_article_feed(path: Path) -> None:
    if not path.parent.name.startswith("master_mobile_articles_"):
        return
    try:
        path.unlink(missing_ok=True)
        path.parent.rmdir()
    except OSError:
        pass


def load_article_map(source: str, timeout: float, insecure: bool) -> dict[str, str]:
    source = (source or "").strip()
    if not source:
        return {}
    is_remote = urllib.parse.urlparse(source).scheme in {"http", "https"}
    path = download_article_feed(source, timeout, insecure) if is_remote else Path(source)
    try:
        if path.suffix.lower() == ".csv":
            return article_map_from_csv(path)
        return article_map_from_feed(path)
    finally:
        if is_remote:
            cleanup_downloaded_article_feed(path)


def load_article_feed_products(
    source: str,
    timeout: float,
    insecure: bool,
    supplier_code: str,
) -> list[Product]:
    source = (source or "").strip()
    if not source:
        raise RuntimeError("--source feed-products requires --article-feed")
    is_remote = urllib.parse.urlparse(source).scheme in {"http", "https"}
    path = download_article_feed(source, timeout, insecure) if is_remote else Path(source)
    try:
        return products_from_article_feed(path, supplier_code)
    finally:
        if is_remote:
            cleanup_downloaded_article_feed(path)


def apply_article_map(products: list[Product], article_map: dict[str, str]) -> tuple[int, int]:
    mapped = 0
    missing = 0
    for product in products:
        article = article_map.get(normalized_product_url(product.url), "").strip()
        if not article:
            missing += 1
            continue
        product.sku = article
        product.offer_id = article
        mapped += 1
    return mapped, missing


def child_text(parent: ET.Element, tag: str) -> str:
    node = parent.find(tag)
    return "" if node is None or node.text is None else node.text.strip()


def param_text(parent: ET.Element, name: str) -> str:
    for node in parent.findall("param"):
        if (node.get("name") or "").strip() == name:
            return "" if node.text is None else node.text.strip()
    return ""


MASTER_MOBILE_PARAM_RENAMES = {
    "качество": "Уровень качества запчасти",
}


def normalize_master_mobile_param_names(offer: ET.Element) -> int:
    renamed = 0
    for node in offer.findall("param"):
        raw_name = (node.get("name") or "").strip()
        new_name = MASTER_MOBILE_PARAM_RENAMES.get(raw_name.casefold())
        if new_name and raw_name != new_name:
            node.set("name", new_name)
            renamed += 1
    return renamed


def product_id_from_url(url: str) -> str:
    path = urllib.parse.urlparse(url).path.rstrip("/")
    value = path.rsplit("/", 1)[-1] if path else ""
    return value if value.isdigit() else ""


def base_offer_id(offer_id: str, supplier_code: str = "") -> str:
    offer_id = trim_offer_id(offer_id)
    if not offer_id:
        return ""
    if supplier_code:
        offer_id = re.sub(
            rf"\s*__\s*{re.escape(supplier_code)}\s*$",
            "",
            offer_id,
            flags=re.I,
        )
    return re.sub(r"\s*__\s*[^_]+$", "", offer_id).strip()


def trim_offer_id(value: str | None) -> str:
    return (value or "").strip()


def supplier_offer_id(article: str, supplier_code: str) -> str:
    article = trim_offer_id(article)
    if not article:
        return ""
    if not supplier_code:
        return article
    if re.search(rf"\s*__\s*{re.escape(supplier_code)}\s*$", article, flags=re.I):
        return article
    return f"{article}__{supplier_code}"


def merge_keys(offer: ET.Element, supplier_code: str) -> list[str]:
    raw_id = trim_offer_id(offer.get("id"))
    keys = [
        product_id_from_url(child_text(offer, "url")),
        param_text(offer, "Артикул стикер"),
        child_text(offer, "vendorCode"),
        base_offer_id(raw_id, supplier_code),
        raw_id,
    ]
    seen: set[str] = set()
    result: list[str] = []
    for key in keys:
        key = key.strip()
        if key and key not in seen:
            seen.add(key)
            result.append(key)
    return result


def purchase_price_keys(offer: ET.Element, supplier_code: str) -> list[str]:
    raw_id = trim_offer_id(offer.get("id"))
    articles = [
        param_text(offer, "Артикул стикер"),
        child_text(offer, "vendorCode"),
        child_text(offer, "model"),
        base_offer_id(raw_id, supplier_code),
    ]
    seen: set[str] = set()
    result: list[str] = []
    for article in articles:
        article = base_offer_id(article, supplier_code)
        for key in purchase_article_keys(article):
            if key and key not in seen:
                seen.add(key)
                result.append(key)
    return result


def normalize_offer_article_from_param(
    offer: ET.Element,
    supplier_code: str,
    param_name: str = "Артикул стикер",
) -> bool:
    article = param_text(offer, param_name)
    if not article:
        return False

    changed = False
    new_id = supplier_offer_id(article, supplier_code)
    if new_id and trim_offer_id(offer.get("id")) != new_id:
        offer.set("id", new_id)
        changed = True

    vendor_node = offer.find("vendorCode")
    if vendor_node is not None:
        if (vendor_node.text or "").strip() != article:
            vendor_node.text = article
            changed = True
    else:
        if set_child_text(offer, "vendorCode", article, after="name"):
            changed = True

    return changed


def set_param_text(parent: ET.Element, name: str, value: str) -> bool:
    for node in parent.findall("param"):
        if (node.get("name") or "").strip() == name:
            changed = (node.text or "") != value
            node.text = value
            return changed

    node = ET.Element("param", {"name": name})
    node.text = value
    parent.append(node)
    return True


def source_offer_article(source: ET.Element, supplier_code: str) -> str:
    article = (
        param_text(source, "Артикул стикер")
        or child_text(source, "vendorCode")
        or child_text(source, "model")
    )
    article = base_offer_id(article, supplier_code)
    if article:
        return article

    raw_id = base_offer_id(source.get("id") or "", supplier_code)
    product_id = product_id_from_url(child_text(source, "url"))
    if raw_id and raw_id != product_id:
        return raw_id
    return ""


def apply_source_article(
    offer: ET.Element,
    source: ET.Element,
    supplier_code: str,
) -> bool:
    article = source_offer_article(source, supplier_code)
    if not article:
        return False

    changed = False
    new_id = supplier_offer_id(article, supplier_code)
    if new_id and trim_offer_id(offer.get("id")) != new_id:
        offer.set("id", new_id)
        changed = True

    if set_child_text(offer, "vendorCode", article, after="name"):
        changed = True
    if set_child_text(offer, "model", article, after="vendorCode"):
        changed = True
    if set_param_text(offer, "Артикул стикер", article):
        changed = True

    return changed


def load_image_replacements(path: Path | None) -> dict[str, str]:
    if path is None or not path.is_file():
        return {}

    mapping: dict[str, str] = {}
    with path.open("r", encoding="utf-8-sig", newline="") as fh:
        reader = csv.DictReader(fh)
        for row in reader:
            replacement = (
                row.get("replacement_picture_url")
                or row.get("clean_image_url")
                or row.get("new_picture_url")
                or ""
            ).strip()
            if not replacement or is_noimage_picture_url(replacement):
                continue

            product_url = (row.get("product_url") or "").strip()
            keys = [
                row.get("offer_id") or "",
                row.get("offer_base_id") or "",
                row.get("vendor_code") or "",
                row.get("site_id") or "",
                product_id_from_url(product_url),
            ]
            for key in keys:
                key = key.strip()
                if key:
                    mapping.setdefault(key, replacement)
    return mapping


def _zip_name(zip_file: zipfile.ZipFile, wanted: str) -> str | None:
    wanted = wanted.lower()
    for name in zip_file.namelist():
        if name.lower() == wanted:
            return name
    return None


def _xlsx_shared_strings(zip_file: zipfile.ZipFile) -> list[str]:
    name = _zip_name(zip_file, "xl/sharedStrings.xml")
    if not name:
        return []
    root = ET.fromstring(zip_file.read(name))
    strings: list[str] = []
    for item in root.findall(f"{XLSX_NS}si"):
        strings.append("".join(text.text or "" for text in item.findall(f".//{XLSX_NS}t")))
    return strings


def _xlsx_first_sheet_path(zip_file: zipfile.ZipFile) -> str:
    workbook_name = _zip_name(zip_file, "xl/workbook.xml")
    rels_name = _zip_name(zip_file, "xl/_rels/workbook.xml.rels")
    if workbook_name and rels_name:
        workbook = ET.fromstring(zip_file.read(workbook_name))
        rels = ET.fromstring(zip_file.read(rels_name))
        rel_targets = {
            rel.get("Id", ""): rel.get("Target", "")
            for rel in rels.findall(f"{REL_NS}Relationship")
        }
        first_sheet = workbook.find(f".//{XLSX_NS}sheet")
        if first_sheet is not None:
            rel_id = first_sheet.get(f"{OFFICE_REL_NS}id", "")
            target = rel_targets.get(rel_id, "")
            if target:
                path = target.lstrip("/")
                if not path.startswith("xl/"):
                    path = f"xl/{path}"
                matched = _zip_name(zip_file, path)
                if matched:
                    return matched

    fallback = _zip_name(zip_file, "xl/worksheets/sheet1.xml")
    if not fallback:
        raise RuntimeError("XLSX worksheet not found")
    return fallback


def _xlsx_column_index(cell_ref: str) -> int | None:
    match = re.match(r"([A-Z]+)", cell_ref or "")
    if not match:
        return None
    index = 0
    for char in match.group(1):
        index = index * 26 + ord(char) - ord("A") + 1
    return index - 1


def _xlsx_cell_text(cell: ET.Element, shared_strings: list[str]) -> str:
    cell_type = cell.get("t", "")
    if cell_type == "inlineStr":
        return "".join(text.text or "" for text in cell.findall(f".//{XLSX_NS}t"))

    value = cell.find(f"{XLSX_NS}v")
    raw = "" if value is None or value.text is None else value.text
    if cell_type == "s":
        try:
            index = int(float(raw))
        except ValueError:
            return raw
        if 0 <= index < len(shared_strings):
            return shared_strings[index]
    return raw


def iter_xlsx_rows(path: Path) -> Iterable[list[str]]:
    with zipfile.ZipFile(path) as zip_file:
        shared_strings = _xlsx_shared_strings(zip_file)
        sheet_path = _xlsx_first_sheet_path(zip_file)
        root = ET.fromstring(zip_file.read(sheet_path))
        for row in root.findall(f".//{XLSX_NS}sheetData/{XLSX_NS}row"):
            values: dict[int, str] = {}
            max_index = -1
            for cell in row.findall(f"{XLSX_NS}c"):
                index = _xlsx_column_index(cell.get("r", ""))
                if index is None:
                    index = max_index + 1
                values[index] = _xlsx_cell_text(cell, shared_strings).strip()
                max_index = max(max_index, index)
            yield [values.get(index, "") for index in range(max_index + 1)]


def normalize_header(value: str) -> str:
    value = value.lower().replace("ё", "е")
    return re.sub(r"[^0-9a-zа-я]+", "", value)


def _find_purchase_columns(row: list[str]) -> tuple[int, int] | None:
    article_index: int | None = None
    price_index: int | None = None
    for index, value in enumerate(row):
        header = normalize_header(value)
        if not header:
            continue
        if article_index is None and "артикул" in header:
            article_index = index
        if price_index is None and (
            "ценаруб" in header
            or header in {"цена", "price", "purchaseprice", "priceoriginal"}
            or ("закуп" in header and "цен" in header)
        ):
            price_index = index
    if article_index is None or price_index is None:
        return None
    return article_index, price_index


def _purchase_row_without_headers(row: list[str]) -> tuple[str, str] | None:
    if not row:
        return None

    article = row[0].strip()
    if not article:
        return None

    article_header = normalize_header(article)
    if "артикул" in article_header or article_header in {"article", "vendorcode"}:
        return None

    for value in row[1:]:
        price = normalize_purchase_price(value)
        if price is not None:
            return article, price
    return None


def _add_purchase_price(mapping: dict[str, str], article: str, price: str) -> None:
    for key in purchase_article_keys(article):
        mapping.setdefault(key, price)


def load_purchase_prices(path: Path | None) -> dict[str, str]:
    if path is None or not path.is_file():
        return {}

    mapping: dict[str, str] = {}
    suffix = path.suffix.lower()
    if suffix == ".xlsx":
        article_index: int | None = None
        price_index: int | None = None
        for row in iter_xlsx_rows(path):
            columns = _find_purchase_columns(row)
            if columns is not None:
                article_index, price_index = columns
                continue
            if article_index is None or price_index is None:
                fallback = _purchase_row_without_headers(row)
                if fallback is not None:
                    _add_purchase_price(mapping, fallback[0], fallback[1])
                continue
            if article_index >= len(row) or price_index >= len(row):
                continue
            article = row[article_index].strip()
            price = normalize_purchase_price(row[price_index])
            if article and price is not None:
                _add_purchase_price(mapping, article, price)
                continue
        return mapping

    with path.open("r", encoding="utf-8-sig", newline="") as fh:
        reader = csv.DictReader(fh)
        if not reader.fieldnames:
            return mapping
        normalized_fields = {normalize_header(name): name for name in reader.fieldnames}
        article_field = (
            normalized_fields.get("артикулстикер")
            or normalized_fields.get("артикул")
            or normalized_fields.get("vendorcode")
            or normalized_fields.get("vendor_code")
            or normalized_fields.get("article")
        )
        price_field = (
            normalized_fields.get("priceoriginal")
            or normalized_fields.get("purchaseprice")
            or normalized_fields.get("закупочнаяцена")
            or normalized_fields.get("цена")
        )
        if not article_field or not price_field:
            raise RuntimeError(f"Purchase price columns not found in {path}")
        for row in reader:
            article = (row.get(article_field) or "").strip()
            price = normalize_purchase_price(row.get(price_field))
            if article and price is not None:
                _add_purchase_price(mapping, article, price)
    return mapping


def parsed_offer_map(parsed_tree: ET.ElementTree, supplier_code: str) -> dict[str, ET.Element]:
    mapping: dict[str, ET.Element] = {}
    for offer in parsed_tree.getroot().findall(".//offer"):
        for key in merge_keys(offer, supplier_code):
            mapping.setdefault(key, offer)
    return mapping


def set_child_text(parent: ET.Element, tag: str, value: object, after: str | None = None) -> bool:
    value_text = str(value)
    node = parent.find(tag)
    if node is not None:
        changed = (node.text or "") != value_text
        node.text = value_text
        return changed

    node = ET.Element(tag)
    node.text = value_text
    if after:
        children = list(parent)
        for index, child in enumerate(children):
            if child.tag == after:
                parent.insert(index + 1, node)
                return True
    parent.append(node)
    return True


def apply_first_picture_replacement(
    offer: ET.Element,
    supplier_code: str,
    image_replacements: dict[str, str],
) -> bool:
    replacement = ""
    for key in merge_keys(offer, supplier_code):
        replacement = image_replacements.get(key, "")
        if replacement:
            break
    if not replacement or is_noimage_picture_url(replacement):
        return False

    node = offer.find("picture")
    if node is not None:
        changed = (node.text or "") != replacement
        node.text = replacement
        return changed

    return set_child_text(offer, "picture", replacement, after="vendorCode")


def remove_noimage_pictures(offer: ET.Element) -> int:
    removed = 0
    for node in list(offer.findall("picture")):
        if is_noimage_picture_url(node.text):
            offer.remove(node)
            removed += 1
    return removed


def parse_stock_value(value: str) -> int | None:
    value = value.strip()
    if value == "":
        return None
    match = re.search(r"-?\d+", value)
    if not match:
        return None
    return max(0, int(match.group(0)))


def feed_number(value: str | None) -> str | None:
    normalized = normalize_price(value)
    if normalized is None:
        return None
    if normalized.endswith(".00"):
        return normalized[:-3]
    return normalized.rstrip("0").rstrip(".")


def merge_parsed_prices_stock(
    base_feed: Path,
    parsed_tree: ET.ElementTree,
    output: Path,
    supplier_code: str,
    zero_missing: bool,
    min_coverage: float,
    image_replacements: dict[str, str] | None = None,
    purchase_prices: dict[str, str] | None = None,
) -> dict[str, object]:
    base = ET.parse(base_feed)
    root = base.getroot()
    offers = root.findall(".//offer")
    articles_updated = 0
    params_renamed = 0
    for offer in offers:
        if normalize_offer_article_from_param(offer, supplier_code):
            articles_updated += 1
        params_renamed += normalize_master_mobile_param_names(offer)

    parsed = parsed_offer_map(parsed_tree, supplier_code)
    image_replacements = image_replacements or {}
    purchase_prices = purchase_prices or {}
    matched = 0
    prices_updated = 0
    purchase_prices_updated = 0
    purchase_prices_applied = 0
    stocks_updated = 0
    pictures_updated = 0
    noimage_pictures_removed = 0
    zeroed_missing = 0
    unmatched_parsed_keys = set(parsed.keys())

    for offer in offers:
        noimage_pictures_removed += remove_noimage_pictures(offer)

        source = None
        for key in merge_keys(offer, supplier_code):
            source = parsed.get(key)
            if source is not None:
                unmatched_parsed_keys.discard(key)
                break

        if image_replacements and apply_first_picture_replacement(offer, supplier_code, image_replacements):
            pictures_updated += 1

        purchase_price = ""
        if purchase_prices:
            for key in purchase_price_keys(offer, supplier_code):
                purchase_price = purchase_prices.get(key, "")
                if purchase_price:
                    break

        if source is None:
            if zero_missing:
                if set_child_text(offer, "stock", 0, after="price_original"):
                    stocks_updated += 1
                if offer.get("available") != "false":
                    offer.set("available", "false")
                zeroed_missing += 1
        else:
            matched += 1
            if apply_source_article(offer, source, supplier_code):
                articles_updated += 1

            price = feed_number(child_text(source, "price_original") or child_text(source, "price"))
            if price is not None:
                if set_child_text(offer, "price_original", price, after="price"):
                    prices_updated += 1

            stock = parse_stock_value(child_text(source, "stock") or child_text(source, "count"))
            if stock is not None:
                if set_child_text(offer, "stock", stock, after="price_original"):
                    stocks_updated += 1
                available = "true" if stock > 0 else "false"
                if offer.get("available") != available:
                    offer.set("available", available)

        if purchase_price:
            purchase_prices_applied += 1
            if set_child_text(offer, "price_original", purchase_price, after="price"):
                purchase_prices_updated += 1

    existing_count = len(offers)
    parsed_offer_count = len(parsed_tree.getroot().findall(".//offer"))
    coverage = (matched / existing_count) if existing_count else 0.0
    if min_coverage > 0 and coverage < min_coverage:
        raise RuntimeError(
            f"merge coverage {coverage:.2%} is below required {min_coverage:.2%} "
            f"({matched}/{existing_count})"
        )

    root.set("updated", dt.datetime.now().strftime("%Y-%m-%d %H:%M"))
    ET.indent(base, space="  ")
    output.parent.mkdir(parents=True, exist_ok=True)
    tmp = output.with_name(f".{output.name}.tmp")
    base.write(tmp, encoding="utf-8", xml_declaration=True)
    tmp.replace(output)

    return {
        "base_feed": str(base_feed),
        "output": str(output),
        "existing_offers": existing_count,
        "parsed_offers": parsed_offer_count,
        "matched": matched,
        "coverage": round(coverage, 6),
        "prices_updated": prices_updated,
        "purchase_prices_loaded": len(purchase_prices),
        "purchase_prices_applied": purchase_prices_applied,
        "purchase_prices_updated": purchase_prices_updated,
        "stocks_updated": stocks_updated,
        "articles_updated": articles_updated,
        "params_renamed": params_renamed,
        "image_replacements_loaded": len(image_replacements),
        "pictures_updated": pictures_updated,
        "noimage_pictures_removed": noimage_pictures_removed,
        "zeroed_missing": zeroed_missing,
        "unmatched_existing": existing_count - matched,
        "unmatched_parsed_keys": max(0, parsed_offer_count - matched),
    }


def collect_products(
    client: HttpClient,
    urls: list[str],
    include_unavailable: bool,
    workers: int,
) -> list[Product]:
    products: list[Product] = []
    total = len(urls)

    def parse_one(index_url: tuple[int, str]) -> Product | None:
        index, url = index_url
        try:
            logging.info("[%s/%s] Product: %s", index, total, url)
            page_html = client.get_text(url)
            product = parse_product_page(url, page_html)
            if not product.price:
                logging.warning("No price parsed for %s", url)
            return product if include_unavailable or product.available else None
        except Exception as exc:
            logging.exception("Failed to parse %s: %s", url, exc)
            return None

    if workers <= 1:
        for item in map(parse_one, enumerate(urls, start=1)):
            if item is not None:
                products.append(item)
        return products

    with concurrent.futures.ThreadPoolExecutor(max_workers=max(1, workers)) as executor:
        futures = [executor.submit(parse_one, item) for item in enumerate(urls, start=1)]
        for future in concurrent.futures.as_completed(futures):
            product = future.result()
            if product is not None:
                products.append(product)
    return products


def enrich_products_from_buy_blocks(
    client: HttpClient,
    products: list[Product],
    include_unavailable: bool,
    workers: int,
    fetch_product_prices: bool = False,
) -> list[Product]:
    enriched_products: list[Product] = []
    total = len(products)
    warning_lock = threading.Lock()
    missing_stock_count = 0
    missing_stock_examples: list[str] = []
    missing_account_price_count = 0
    missing_account_price_examples: list[str] = []

    def remember_missing_stock(url: str) -> None:
        nonlocal missing_stock_count
        with warning_lock:
            missing_stock_count += 1
            if len(missing_stock_examples) < 10:
                missing_stock_examples.append(url)

    def remember_missing_account_price(url: str) -> None:
        nonlocal missing_account_price_count
        with warning_lock:
            missing_account_price_count += 1
            if len(missing_account_price_examples) < 10:
                missing_account_price_examples.append(url)

    def log_warning_summary() -> None:
        if missing_stock_count > 0:
            logging.warning(
                "No data-store-count parsed: %s products. Examples: %s",
                missing_stock_count,
                "; ".join(missing_stock_examples),
            )
        if missing_account_price_count > 0:
            logging.warning(
                "No account price parsed: %s products. Examples: %s",
                missing_account_price_count,
                "; ".join(missing_account_price_examples),
            )

    def enrich_one(index_product: tuple[int, Product]) -> Product | None:
        index, product = index_product
        try:
            logging.info("[%s/%s] Buy block: %s", index, total, product.url)
            buy_html = client.bitrix_action(
                "sp:tools.CatalogController.buy",
                {"id": product.id, "type": "block"},
            )
            stock, stock_text, available = parse_stock(product.id, buy_html, None)
            if stock is None:
                remember_missing_stock(product.url)
                return None
            if fetch_product_prices:
                page_html = client.get_text(product.url)
                price = parse_product_price(page_html)
                if price:
                    product.price = price
                else:
                    remember_missing_account_price(product.url)
                article = parse_product_article(page_html)
                if article:
                    product.sku = article
                    product.offer_id = article
            product.stock = stock
            product.stock_text = stock_text or f"{DEFAULT_STORE_NAME}: {stock} шт."
            product.available = available
            return product if include_unavailable or product.available else None
        except Exception as exc:
            logging.exception("Failed to parse buy block for %s: %s", product.url, exc)
            return None

    if workers <= 1:
        for item in map(enrich_one, enumerate(products, start=1)):
            if item is not None:
                enriched_products.append(item)
        log_warning_summary()
        return enriched_products

    with concurrent.futures.ThreadPoolExecutor(max_workers=max(1, workers)) as executor:
        futures = [executor.submit(enrich_one, item) for item in enumerate(products, start=1)]
        for future in concurrent.futures.as_completed(futures):
            product = future.result()
            if product is not None:
                enriched_products.append(product)
    log_warning_summary()
    return enriched_products


def add_product(
    products_by_id: dict[str, Product],
    product: Product,
    include_unavailable: bool,
) -> None:
    if not include_unavailable and not product.available:
        return
    if product.id not in products_by_id:
        products_by_id[product.id] = product


def collect_products_from_categories(
    client: HttpClient,
    category_urls: list[str],
    include_unavailable: bool,
    limit: int | None,
    workers: int,
) -> list[Product]:
    products_by_id: dict[str, Product] = {}

    if limit is not None:
        total_categories = len(category_urls)
        for category_index, category_url in enumerate(category_urls, start=1):
            logging.info("[%s/%s] Category: %s", category_index, total_categories, category_url)
            try:
                page_html = fetch_category_page(client, category_url, 1)
                products, max_page, pagen = parse_category_page(page_html, category_url)
                for product in products:
                    add_product(products_by_id, product, include_unavailable)
                    if len(products_by_id) >= limit:
                        return list(products_by_id.values())[:limit]

                for page in range(2, max_page + 1):
                    logging.info("Category page %s/%s: %s", page, max_page, category_url)
                    page_html = fetch_category_page(client, category_url, page, pagen)
                    products, _, _ = parse_category_page(page_html, category_url)
                    for product in products:
                        add_product(products_by_id, product, include_unavailable)
                        if len(products_by_id) >= limit:
                            return list(products_by_id.values())[:limit]
            except Exception as exc:
                logging.exception("Failed to parse category %s: %s", category_url, exc)
        return list(products_by_id.values())[:limit]

    def fetch_first(category_url: str) -> tuple[str, int, str, list[Product]]:
        page_html = fetch_category_page(client, category_url, 1)
        products, max_page, pagen = parse_category_page(page_html, category_url)
        return category_url, max_page, pagen, products

    page_tasks: list[tuple[str, int, str]] = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=max(1, workers)) as executor:
        futures = [executor.submit(fetch_first, url) for url in category_urls]
        for future in concurrent.futures.as_completed(futures):
            try:
                category_url, max_page, pagen, products = future.result()
                logging.info("Category parsed: %s (%s pages)", category_url, max_page)
                for product in products:
                    add_product(products_by_id, product, include_unavailable)
                page_tasks.extend((category_url, page, pagen) for page in range(2, max_page + 1))
            except Exception as exc:
                logging.exception("Failed to parse category first page: %s", exc)

        def fetch_later(task: tuple[str, int, str]) -> list[Product]:
            category_url, page, pagen = task
            page_html = fetch_category_page(client, category_url, page, pagen)
            products, _, _ = parse_category_page(page_html, category_url)
            return products

        futures = [executor.submit(fetch_later, task) for task in page_tasks]
        for index, future in enumerate(concurrent.futures.as_completed(futures), start=1):
            try:
                logging.info("Category pagination parsed: %s/%s", index, len(futures))
                for product in future.result():
                    add_product(products_by_id, product, include_unavailable)
            except Exception as exc:
                logging.exception("Failed to parse category page: %s", exc)

    return list(products_by_id.values())


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Parse master-mobile.ru products and write a Yandex Market YML feed."
    )
    parser.add_argument("-o", "--output", type=Path, default=Path("master_mobile.yml"))
    parser.add_argument("--sitemap", default=DEFAULT_SITEMAP_URL)
    parser.add_argument(
        "--source",
        choices=("feed-products", "categories", "products", "category-products"),
        default="category-products",
        help=(
            "feed-products reads product URLs from the corrected XML feed; "
            "categories reads site categories; products reads sitemap product pages; "
            "category-products discovers current products from categories, then reads buy-block AJAX."
        ),
    )
    parser.add_argument("--url-file", type=Path, help="Text file with product URLs, one URL per line.")
    parser.add_argument("--limit", type=int, help="Limit products for test runs.")
    parser.add_argument("--offset", type=int, default=0, help="Skip first N products.")
    parser.add_argument("--delay", type=float, help="Delay between network requests, seconds.")
    parser.add_argument("--workers", type=int, default=8, help="Parallel workers.")
    parser.add_argument(
        "--store-id",
        default=DEFAULT_STORE_ID,
        help="Master Mobile internal store ID. 2 is ТК «Савеловский» Мобильный.",
    )
    parser.add_argument(
        "--store-region-id",
        default="",
        help="Optional region ID for the Master Mobile store selector.",
    )
    parser.add_argument(
        "--no-store-cookie",
        "--no-store-select",
        action="store_true",
        help="Do not select a Master Mobile store before scraping.",
    )
    parser.add_argument("--timeout", type=float, default=30.0)
    parser.add_argument("--retries", type=int, default=3)
    parser.add_argument("--cache-dir", type=Path, default=Path(".cache/master-mobile"))
    parser.add_argument("--no-cache", action="store_true")
    parser.add_argument("--user-agent", default=DEFAULT_USER_AGENT)
    parser.add_argument(
        "--login",
        default=os.getenv("MASTER_MOBILE_LOGIN", ""),
        help="Master Mobile account email. Defaults to MASTER_MOBILE_LOGIN.",
    )
    parser.add_argument(
        "--password",
        default=os.getenv("MASTER_MOBILE_PASSWORD", ""),
        help="Master Mobile account password. Defaults to MASTER_MOBILE_PASSWORD.",
    )
    parser.add_argument(
        "--no-login",
        action="store_true",
        help="Do not authenticate even if MASTER_MOBILE_LOGIN/PASSWORD are set.",
    )
    parser.add_argument(
        "--fetch-product-prices",
        action="store_true",
        help="In feed-products mode, fetch product pages and parse account-visible prices.",
    )
    parser.add_argument(
        "--article-map",
        type=Path,
        help="CSV with url/new_article columns; overrides product article before writing snapshot.",
    )
    parser.add_argument(
        "--article-feed",
        default=DEFAULT_CORRECTED_SOURCE_URL,
        help=(
            "Corrected source XML used to map product URLs to <param name=\"Артикул стикер\">. "
            "Pass an empty value to disable."
        ),
    )
    parser.add_argument("--article-timeout", type=float, default=180.0)
    parser.add_argument("--ignore-robots", action="store_true", help="Do not check robots.txt.")
    parser.add_argument(
        "--insecure",
        action="store_true",
        help="Disable TLS certificate verification if local Python certificates are broken.",
    )
    parser.add_argument(
        "--only-available",
        action="store_true",
        help="Skip products that are not available.",
    )
    parser.add_argument("-v", "--verbose", action="store_true")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(levelname)s: %(message)s",
    )

    source = "products" if args.url_file else args.source
    delay = args.delay
    if delay is None:
        delay = 0.0 if source == "categories" else 0.05

    client = HttpClient(
        user_agent=args.user_agent,
        timeout=args.timeout,
        delay=delay,
        cache_dir=None if args.no_cache else args.cache_dir,
        retries=args.retries,
        respect_robots=not args.ignore_robots,
        insecure_tls=args.insecure,
    )
    client.load_robots(urllib.parse.urljoin(BASE_URL, "/robots.txt"))
    if not args.no_store_cookie and args.store_id:
        client.set_cookie("BITRIX_SM_region_store_id", str(args.store_id))

    if not args.no_login:
        login = str(args.login or "").strip()
        password = str(args.password or "")
        if login or password:
            if not login or not password:
                raise RuntimeError("Both Master Mobile login and password are required for authentication.")
            client.login(login, password)

    if not args.no_store_cookie and args.store_id:
        client.select_store(str(args.store_id), str(args.store_region_id or ""))
        logging.info("Store selected: storeID=%s", args.store_id)

    if source == "feed-products":
        feed_products = load_article_feed_products(
            source=str(args.article_feed),
            timeout=args.article_timeout,
            insecure=args.insecure,
            supplier_code=DEFAULT_SUPPLIER_CODE,
        )
        feed_products = feed_products[args.offset :]
        if args.limit is not None:
            feed_products = feed_products[: args.limit]
        logging.info("Product URLs selected from corrected feed: %s", len(feed_products))
        if not feed_products and not args.only_available:
            raise RuntimeError("No products were loaded from the article feed.")
        products = enrich_products_from_buy_blocks(
            client=client,
            products=feed_products,
            include_unavailable=not args.only_available,
            workers=args.workers,
            fetch_product_prices=args.fetch_product_prices,
        )
        if feed_products and not products and not args.only_available:
            raise RuntimeError(
                "No products were enriched from buy-block AJAX. "
                "For the feed-products mode, add --ignore-robots."
            )
    elif source == "products":
        if args.url_file:
            urls = load_urls_from_file(args.url_file)
        else:
            urls = discover_product_urls(client, args.sitemap)

        urls = urls[args.offset :]
        if args.limit is not None:
            urls = urls[: args.limit]
        logging.info("Product URLs selected: %s", len(urls))

        products = collect_products(
            client=client,
            urls=urls,
            include_unavailable=not args.only_available,
            workers=args.workers,
        )
    elif source == "category-products":
        category_urls = discover_category_urls(client, args.sitemap)
        logging.info("Category URLs selected: %s", len(category_urls))
        listing_limit = None
        if args.limit is not None:
            listing_limit = args.limit + max(0, args.offset)
        listing_products = collect_products_from_categories(
            client=client,
            category_urls=category_urls,
            include_unavailable=True,
            limit=listing_limit,
            workers=args.workers,
        )
        listing_products = listing_products[args.offset :]
        if args.limit is not None:
            listing_products = listing_products[: args.limit]
        logging.info("Current products selected from categories: %s", len(listing_products))
        if not listing_products and not args.only_available:
            raise RuntimeError("No products were selected from site categories.")
        products = enrich_products_from_buy_blocks(
            client=client,
            products=listing_products,
            include_unavailable=not args.only_available,
            workers=args.workers,
            fetch_product_prices=args.fetch_product_prices,
        )
        if listing_products and not products and not args.only_available:
            raise RuntimeError(
                "No products were enriched from buy-block AJAX. "
                "For the fast category-products mode, add --ignore-robots."
            )
    else:
        urls = discover_category_urls(client, args.sitemap)
        urls = urls[args.offset :]
        logging.info("Category URLs selected: %s", len(urls))
        products = collect_products_from_categories(
            client=client,
            category_urls=urls,
            include_unavailable=not args.only_available,
            limit=args.limit,
            workers=args.workers,
        )
    logging.info("Products parsed: %s", len(products))

    article_map: dict[str, str] = {}
    if args.article_map:
        article_map = article_map_from_csv(args.article_map)
        logging.info("Article map loaded from CSV: %s rows", len(article_map))
    elif source != "feed-products" and str(args.article_feed or "").strip():
        article_map = load_article_map(str(args.article_feed), args.article_timeout, args.insecure)
        logging.info("Article map loaded from corrected source: %s rows", len(article_map))
    if article_map:
        mapped, missing = apply_article_map(products, article_map)
        logging.info("Product articles normalized: mapped=%s missing=%s", mapped, missing)

    tree = build_yml(products)
    write_xml(tree, args.output)
    logging.info("YML written: %s", args.output.resolve())
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
