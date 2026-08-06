#!/usr/bin/env python3
"""Build old-to-new Master Mobile article mapping by product URL."""

from __future__ import annotations

import argparse
import csv
import ssl
import tempfile
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path

from master_mobile_parser import base_offer_id, child_text, param_text


DEFAULT_NEW_SOURCE_URL = "https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml"
DEFAULT_CURRENT_SOURCE_URL = "https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml"


def download(url: str, timeout: float, insecure: bool) -> Path:
    ctx = ssl._create_unverified_context() if insecure else None
    target = Path(tempfile.mkdtemp(prefix="master_mobile_articles_")) / "feed.xml"
    request = urllib.request.Request(url, headers={"User-Agent": "FeedTools/MasterMobileArticleMapping"})
    try:
        with urllib.request.urlopen(request, timeout=timeout, context=ctx) as response:
            with target.open("wb") as fh:
                while True:
                    chunk = response.read(1024 * 1024)
                    if not chunk:
                        break
                    fh.write(chunk)
    except Exception:
        cleanup_download(target)
        raise
    return target


def cleanup_download(path: Path) -> None:
    if not path.parent.name.startswith("master_mobile_articles_"):
        return
    try:
        path.unlink(missing_ok=True)
        path.parent.rmdir()
    except OSError:
        pass


def is_downloaded_source(path: Path) -> bool:
    return path.parent.name.startswith("master_mobile_articles_")


def source_path(value: str, timeout: float, insecure: bool) -> Path:
    if urllib.parse.urlparse(value).scheme in {"http", "https"}:
        return download(value, timeout, insecure)
    return Path(value)


def normalize_url(value: str) -> str:
    value = value.strip()
    return value.rstrip("/") + "/" if value else ""


def read_offers(path: Path, supplier_code: str) -> dict[str, dict[str, str]]:
    offers: dict[str, dict[str, str]] = {}
    for _event, offer in ET.iterparse(path, events=("end",)):
        if offer.tag != "offer":
            continue
        url = normalize_url(child_text(offer, "url"))
        if not url:
            offer.clear()
            continue

        raw_id = base_offer_id(offer.get("id") or "", supplier_code)
        vendor_code = child_text(offer, "vendorCode")
        sticker = param_text(offer, "Артикул стикер")
        offers[url] = {
            "article": raw_id or vendor_code,
            "vendor_code": vendor_code,
            "sticker": sticker,
            "name": child_text(offer, "name"),
        }
        offer.clear()
    return offers


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build Master Mobile old/new article mapping by <url>.")
    parser.add_argument("--current", default=DEFAULT_CURRENT_SOURCE_URL)
    parser.add_argument("--new", default=DEFAULT_NEW_SOURCE_URL)
    parser.add_argument("--output", type=Path, default=Path("storage/master_mobile/article_mapping_changed.csv"))
    parser.add_argument("--all-output", type=Path, default=Path("storage/master_mobile/article_mapping_all.csv"))
    parser.add_argument(
        "--simple-output",
        type=Path,
        default=Path("storage/master_mobile/article_mapping_changed_simple.csv"),
    )
    parser.add_argument("--supplier-code", default="24")
    parser.add_argument("--timeout", type=float, default=180.0)
    parser.add_argument("--insecure", action="store_true")
    return parser.parse_args()


def write_csv(path: Path, rows: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    columns = [
        "current_article",
        "new_article",
        "url",
        "current_vendorCode",
        "new_vendorCode",
        "new_sticker_article",
        "current_name",
        "new_name",
    ]
    with path.open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=columns)
        writer.writeheader()
        writer.writerows(rows)


def write_simple_csv(path: Path, rows: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=["current_article", "new_article"])
        writer.writeheader()
        for row in rows:
            writer.writerow({
                "current_article": row["current_article"],
                "new_article": row["new_article"],
            })


def main() -> int:
    args = parse_args()
    cleanup: list[Path] = []
    try:
        current_path = source_path(args.current, args.timeout, args.insecure)
        if is_downloaded_source(current_path):
            cleanup.append(current_path)
        new_path = source_path(args.new, args.timeout, args.insecure)
        if is_downloaded_source(new_path):
            cleanup.append(new_path)
        current = read_offers(current_path, args.supplier_code)
        new = read_offers(new_path, args.supplier_code)

        rows: list[dict[str, str]] = []
        changed: list[dict[str, str]] = []
        for url, old_offer in current.items():
            new_offer = new.get(url)
            if not new_offer:
                continue
            current_article = old_offer["article"]
            new_article = new_offer["sticker"] or new_offer["vendor_code"] or new_offer["article"]
            row = {
                "current_article": current_article,
                "new_article": new_article,
                "url": url,
                "current_vendorCode": old_offer["vendor_code"],
                "new_vendorCode": new_offer["vendor_code"],
                "new_sticker_article": new_offer["sticker"],
                "current_name": old_offer["name"],
                "new_name": new_offer["name"],
            }
            rows.append(row)
            if current_article != new_article:
                changed.append(row)

        write_csv(args.all_output, rows)
        write_csv(args.output, changed)
        write_simple_csv(args.simple_output, changed)
        print(
            "current={current} new={new} matched={matched} changed={changed} "
            "all_output={all_output} output={output} simple_output={simple_output}".format(
                current=len(current),
                new=len(new),
                matched=len(rows),
                changed=len(changed),
                all_output=args.all_output,
                output=args.output,
                simple_output=args.simple_output,
            )
        )
        return 0
    finally:
        for path in cleanup:
            cleanup_download(path)


if __name__ == "__main__":
    raise SystemExit(main())
