#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import gzip
import html
import json
import math
import re
import statistics
from collections import Counter, defaultdict
from dataclasses import dataclass, asdict
from difflib import SequenceMatcher
from pathlib import Path
from typing import Any, Iterable


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DUMP = ROOT / "deploy/local/artifacts_latest/feedtools_20260504_135146_full.sql.gz"
DEFAULT_OUT = ROOT / "analysis/output"

TARGET_SUPPLIER_IDS = {2, 3, 4, 5}
TARGET_SUPPLIERS_ORDER = ["FlyDepo", "FPVmatrix", "Mydrone", "СпортХобби"]
GENERIC_BRANDS = {
    "",
    "flydepo",
    "fpvmatrix",
    "fpv matrix",
    "mydrone",
    "my drone",
    "sporthobby",
    "sport hobby",
    "спорхобби",
    "спортхобби",
    "спорт хобби",
}


PRODUCT_COLUMNS = [
    "id",
    "supplier_id",
    "offer_key",
    "offer_id",
    "raw_hash",
    "sort_order",
    "name",
    "vendor_code",
    "category_id",
    "category_path",
    "ozon_category",
    "wb_category",
    "brand",
    "description_html",
    "count_qty",
    "stock_qty",
    "price_original",
    "pictures_json",
    "params_json",
    "created_at",
    "updated_at",
]

FIELD_COLUMNS = [
    "id",
    "supplier_id",
    "product_id",
    "field_kind",
    "field_name",
    "field_value",
    "sort_order",
    "created_at",
    "updated_at",
]

SUPPLIER_COLUMNS = [
    "id",
    "name",
    "supplier_code",
    "feed_url",
    "is_active",
    "sort_order",
    "notes",
    "created_by",
    "updated_by",
    "created_at",
    "updated_at",
]


FIELD_NAMES_TO_KEEP = {
    "url",
    "available",
    "vendorcode",
    "vendor_code",
    "model",
    "same_model",
    "price",
    "currencyid",
    "purchase_price",
    "brand",
    "vendor",
    "weight",
    "length",
    "width",
    "height",
    "stock",
    "count",
    "quantity",
    "sizeinpackage",
}

PARAM_HINT_RE = re.compile(
    r"(модель|model|верси|version|при[её]мник|receiver|питани|аккум|battery|"
    r"видеоперед|video|раз[ъь]ем|connector|протокол|protocol|размер|size|"
    r"цвет|color|комплектац|kit|gps|kv|cell|s$)",
    re.I,
)


STOPWORDS = {
    "для",
    "под",
    "без",
    "или",
    "тип",
    "на",
    "с",
    "со",
    "в",
    "из",
    "от",
    "до",
    "и",
    "комплект",
    "комплектация",
    "готовый",
    "версия",
    "черный",
    "черная",
    "черное",
    "белый",
    "белая",
    "серый",
    "красный",
    "синий",
    "зеленый",
    "прозрачный",
    "аккумулятор",
    "батарея",
    "товар",
    "набор",
    "шт",
    "пара",
    "fpv",
    "drone",
    "drones",
    "racing",
    "quad",
    "quadcopter",
    "battery",
    "lipo",
    "lihv",
    "lion",
    "liion",
    "for",
    "with",
    "and",
    "the",
    "new",
    "kit",
    "set",
    "pack",
    "pcs",
    "piece",
    "black",
    "white",
    "red",
    "blue",
    "green",
    "gray",
    "grey",
    "transparent",
    "version",
    "original",
    "frame",
    "motor",
    "propeller",
    "propellers",
    "camera",
    "charger",
    "module",
    "cable",
    "adapter",
    "connector",
    "antenna",
}

CATEGORY_STOPWORDS = {
    "аккумулятор",
    "батарея",
    "пропеллер",
    "пропеллеры",
    "пропы",
    "мотор",
    "двигатель",
    "рама",
    "камер",
    "камера",
    "антенна",
    "зарядное",
    "устройство",
    "плата",
    "контроллер",
    "регулятор",
    "квадрокоптер",
    "дрон",
    "очки",
    "приемник",
    "приёмник",
    "передатчик",
    "кабель",
    "разъем",
    "разъём",
}

BRAND_ALIASES = {
    "axisflying": ["axisflying", "axis flying"],
    "betafpv": ["betafpv", "beta fpv", "beta-fpv", "beta"],
    "caddx": ["caddx"],
    "cnhl": ["cnhl", "china hobby line"],
    "darwinfpv": ["darwinfpv", "darwin fpv"],
    "diatone": ["diatone", "mamba"],
    "dji": ["dji"],
    "emax": ["emax"],
    "fatshark": ["fatshark", "fat shark"],
    "flywoo": ["flywoo", "fly woo"],
    "foxeer": ["foxeer"],
    "gaoneng": ["gaoneng", "gnb"],
    "gemfan": ["gemfan", "gem fan"],
    "geprc": ["geprc", "gep rc"],
    "happymodel": ["happymodel", "happy model"],
    "hdzero": ["hdzero", "hd zero"],
    "hglrc": ["hglrc"],
    "holybro": ["holybro", "holy bro", "kakute"],
    "hqprop": ["hqprop", "hq prop", "hq-prop"],
    "iflight": ["iflight", "i flight", "i-flight"],
    "isdt": ["isdt"],
    "jhemcu": ["jhemcu"],
    "jumper": ["jumper"],
    "lumenier": ["lumenier"],
    "matek": ["matek", "mateksys"],
    "radiomaster": ["radiomaster", "radio master"],
    "runcam": ["runcam", "run cam"],
    "rushfpv": ["rushfpv", "rush fpv", "rush"],
    "skyzone": ["skyzone", "sky zone"],
    "speedybee": ["speedybee", "speedy bee"],
    "t-motor": ["t-motor", "tmotor", "t motor"],
    "tattu": ["tattu"],
    "tbs": ["tbs", "team blacksheep", "team black sheep"],
    "toolkitrc": ["toolkitrc", "toolkit rc"],
    "walksnail": ["walksnail", "walk snail", "avatar"],
}

ALIAS_TO_BRAND = {
    alias: brand for brand, aliases in BRAND_ALIASES.items() for alias in aliases
}
BRAND_PHRASES = sorted(ALIAS_TO_BRAND, key=lambda x: (-len(x), x))

HOMOGLYPHS = str.maketrans(
    {
        "а": "a",
        "А": "a",
        "с": "c",
        "С": "c",
        "е": "e",
        "Е": "e",
        "о": "o",
        "О": "o",
        "р": "p",
        "Р": "p",
        "х": "x",
        "Х": "x",
        "к": "k",
        "К": "k",
        "м": "m",
        "М": "m",
        "т": "t",
        "Т": "t",
        "в": "b",
        "В": "b",
        "н": "h",
        "Н": "h",
        "у": "y",
        "У": "y",
    }
)


def mysql_unescape_char(ch: str) -> str:
    return {
        "0": "\0",
        "b": "\b",
        "n": "\n",
        "r": "\r",
        "t": "\t",
        "Z": "\x1a",
        "\\": "\\",
        "'": "'",
        '"': '"',
    }.get(ch, ch)


def coerce_sql_atom(token: str, quoted: bool) -> Any:
    if quoted:
        return token
    token = token.strip()
    if token.upper() == "NULL":
        return None
    if token == "":
        return ""
    if re.fullmatch(r"-?\d+", token):
        try:
            return int(token)
        except ValueError:
            return token
    if re.fullmatch(r"-?\d+\.\d+", token):
        try:
            return float(token)
        except ValueError:
            return token
    return token


def iter_insert_rows(dump_path: Path, tables: set[str]) -> Iterable[tuple[str, list[Any]]]:
    table: str | None = None
    parsing = False
    in_row = False
    in_string = False
    escaping = False
    token: list[str] = []
    token_quoted = False
    row: list[Any] = []
    insert_re = re.compile(r"INSERT INTO `([^`]+)` VALUES", re.I)

    with gzip.open(dump_path, "rt", encoding="utf-8", errors="replace", newline="") as fh:
        for line in fh:
            start_at = 0
            if not parsing:
                m = insert_re.search(line)
                if not m or m.group(1) not in tables:
                    continue
                table = m.group(1)
                parsing = True
                start_at = m.end()

            for ch in line[start_at:]:
                if in_string:
                    if escaping:
                        token.append(mysql_unescape_char(ch))
                        escaping = False
                    elif ch == "\\":
                        escaping = True
                    elif ch == "'":
                        in_string = False
                    else:
                        token.append(ch)
                    continue

                if in_row:
                    if ch == "'":
                        in_string = True
                        token_quoted = True
                    elif ch == ",":
                        row.append(coerce_sql_atom("".join(token), token_quoted))
                        token = []
                        token_quoted = False
                    elif ch == ")":
                        row.append(coerce_sql_atom("".join(token), token_quoted))
                        yield table or "", row
                        row = []
                        token = []
                        token_quoted = False
                        in_row = False
                    else:
                        token.append(ch)
                    continue

                if ch == "(":
                    in_row = True
                    row = []
                    token = []
                    token_quoted = False
                elif ch == ";":
                    parsing = False
                    table = None


def normalize_loose(text: Any) -> str:
    text = html.unescape(str(text or ""))
    text = re.sub(r"<[^>]+>", " ", text)
    text = text.replace("ё", "е").replace("Ё", "е")
    text = text.replace("×", "x").replace("х", "x")
    text = text.replace("“", '"').replace("”", '"')
    text = re.sub(r"\s+", " ", text)
    return text.strip().lower()


def normalize_for_tokens(text: Any) -> str:
    s = normalize_loose(text)
    s = re.sub(r"(?<=\d)\s*[*xх×]\s*(?=\d)", "x", s, flags=re.I)
    replacements = [
        (r"(\d+(?:[.,]\d+)?)\s*(?:mah|mаh|мач|ма\s*ч|мaч)", r"\1mah"),
        (r"(\d+(?:[.,]\d+)?)\s*(?:kv|кв)\b", r"\1kv"),
        (r"(\d+(?:[.,]\d+)?)\s*(?:mm|мм)\b", r"\1mm"),
        (r"\b([1-8])\s*s\b", r"\1s"),
        (r"\b(\d{1,3})\s*c\b", r"\1c"),
        (r"\b(\d{1,3})\s*a\b", r"\1a"),
        (r"\bxt\s*(30|60|90)\b", r"xt\1"),
        (r"\bbt\s*(2\.0|3\.0)\b", r"bt\1"),
        (r"\bdc\s*5[.,]5\b", r"dc5.5"),
        (r"\belrs\s*2[.,]4\b", r"elrs2.4"),
        (r"\bo\s*3\b", r"o3"),
        (r"\bф\s*722\b", r"f722"),
        (r"\bф\s*405\b", r"f405"),
    ]
    for pattern, repl in replacements:
        s = re.sub(pattern, repl, s, flags=re.I)
    return s.replace(",", ".")


def normalize_mixed_token(token: str) -> str:
    token = token.strip("._-+/")
    if re.search(r"[a-z0-9]", token, re.I):
        token = token.translate(HOMOGLYPHS)
    return token.lower()


def tokenize(text: Any) -> list[str]:
    s = normalize_for_tokens(text)
    raw = re.findall(r"[0-9a-zа-яё]+(?:[._+\-/][0-9a-zа-яё]+)*", s, flags=re.I)
    out: list[str] = []
    for token in raw:
        token = normalize_mixed_token(token)
        if not token or len(token) < 2:
            continue
        if token in STOPWORDS or token in CATEGORY_STOPWORDS:
            continue
        out.append(token)
    return out


def clean_brand(value: Any) -> str:
    s = normalize_loose(value)
    s = s.replace("-", " ")
    s = re.sub(r"[^0-9a-zа-яё ]+", " ", s, flags=re.I)
    s = re.sub(r"\s+", " ", s).strip()
    if s in GENERIC_BRANDS:
        return ""
    if s in ALIAS_TO_BRAND:
        return ALIAS_TO_BRAND[s]
    compact = s.replace(" ", "")
    if compact in ALIAS_TO_BRAND:
        return ALIAS_TO_BRAND[compact]
    return compact if compact not in GENERIC_BRANDS else ""


def detect_brand(text: str, explicit_brand: Any = "") -> str:
    brand = clean_brand(explicit_brand)
    if brand:
        return brand
    s = " " + normalize_for_tokens(text).replace("-", " ") + " "
    for alias in BRAND_PHRASES:
        pattern = r"(?<![0-9a-zа-я])" + re.escape(alias) + r"(?![0-9a-zа-я])"
        if re.search(pattern, s, re.I):
            return ALIAS_TO_BRAND[alias]
    return ""


def classify_category(text: str) -> str:
    s = normalize_for_tokens(text)
    rules = [
        ("drone", r"\b(квадрокоптер|дрон|cinewhoop|whoop|bnf|pnp|ready to fly|rtf)\b"),
        ("battery", r"\b(аккумулятор|батаре|lipo|lihv|li-ion|liion|\d{2,5}mah)\b"),
        ("goggles", r"\b(очки|goggles|goggle|fatshark|skyzone)\b"),
        ("propeller", r"\b(пропеллер|пропеллеры|пропы|propeller|propellers|props|gemfan|hqprop)\b"),
        ("motor", r"\b(мотор|двигател|motor|brushless|\d{3,5}kv)\b"),
        ("frame", r"\b(рама|frame|карбон|carbon)\b"),
        ("esc", r"\b(esc|регулятор|регуляторы|blheli|4in1|4-in-1)\b"),
        ("flight_controller", r"\b(полетн|полётн|flight controller|контроллер|f405|f411|f722|h743|aio)\b"),
        ("stack", r"\b(stack|стек|комбо|combo)\b"),
        ("vtx", r"\b(vtx|видеопередатчик|передатчик|video transmitter|rush tank|o3 air unit|avatar hd)\b"),
        ("receiver", r"\b(приемник|приёмник|receiver|rx|elrs|crossfire|frsky|tbs nano)\b"),
        ("camera", r"\b(камера|camera|caddx|runcam|foxeer|nebula|wasp)\b"),
        ("antenna", r"\b(антенна|antenna|lhcp|rhcp)\b"),
        ("charger", r"\b(зарядн|charger|зарядное|isdt|toolkitrc)\b"),
        ("radio", r"\b(пульт|аппаратура|радиоаппаратура|radiomaster|jumper|transmitter)\b"),
        ("cable_connector", r"\b(кабель|провод|разъем|разъём|connector|adapter|xt30|xt60|dc5.5|jst)\b"),
        ("case_mount", r"\b(кейс|case|креплен|mount|holder|держатель)\b"),
    ]
    for category, pattern in rules:
        if re.search(pattern, s, re.I):
            return category
    return "other"


def safe_json_loads(raw: Any) -> Any:
    if not raw:
        return {}
    try:
        return json.loads(str(raw))
    except Exception:
        return {}


def flatten_params(params: Any) -> dict[str, list[str]]:
    out: dict[str, list[str]] = {}
    if not isinstance(params, dict):
        return out
    for key, value in params.items():
        values: list[str] = []
        if isinstance(value, list):
            values = [str(v) for v in value if str(v).strip()]
        elif value is not None:
            values = [str(value)]
        if values:
            out[str(key)] = values
    return out


def params_text_for_matching(params: dict[str, list[str]]) -> str:
    chunks: list[str] = []
    for key, values in params.items():
        if PARAM_HINT_RE.search(key):
            chunks.append(key)
            chunks.extend(values[:4])
    return " ".join(chunks)


def extract_specs(text: str) -> dict[str, set[str]]:
    s = normalize_for_tokens(text)
    specs: dict[str, set[str]] = defaultdict(set)
    for val in re.findall(r"\b(\d{2,5})mah\b", s):
        specs["capacity_mah"].add(str(int(float(val))))
    for val in re.findall(r"\b([1-8])s\b", s):
        specs["cells"].add(val + "s")
    for val in re.findall(r"\b(\d{1,3})c\b", s):
        specs["c_rate"].add(val + "c")
    for val in re.findall(r"\b(\d{3,5})kv\b", s):
        specs["kv"].add(str(int(float(val))) + "kv")
    for val in re.findall(r"\b(\d{1,3})a\b", s):
        specs["amp"].add(str(int(float(val))) + "a")
    for val in re.findall(r"\b(0?[89]\d{2}|1[0-9]\d{2}|2[0-9]\d{2}|3[0-9]\d{2}|4[0-9]\d{2})\b", s):
        specs["motor_size"].add(val.lstrip("0") or val)
    for val in re.findall(r"\b(\d{1,3}(?:\.\d+)?x\d{1,3}(?:\.\d+)?(?:x\d{1,2})?)\b", s):
        specs["x_size"].add(val)
    for val in re.findall(r"\b(\d+(?:\.\d+)?mm)\b", s):
        specs["mm"].add(val)
    for val in re.findall(r"\b(\d+(?:\.\d+)?)\s*(?:cm|см)\b", s):
        try:
            mm = float(val) * 10
            specs["mm"].add((str(int(mm)) if mm.is_integer() else str(mm)) + "mm")
        except ValueError:
            pass

    connectors = [
        "xt30",
        "xt60",
        "xt90",
        "bt2.0",
        "bt3.0",
        "ph2.0",
        "jst",
        "ec3",
        "ec5",
        "dc5.5",
        "usb-c",
        "usbc",
    ]
    for c in connectors:
        if re.search(r"(?<![0-9a-z])" + re.escape(c) + r"(?![0-9a-z])", s):
            specs["connector"].add(c.replace("usbc", "usb-c"))

    protocols = [
        "elrs",
        "elrs2.4",
        "crossfire",
        "tbs",
        "frsky",
        "sbus",
        "pnp",
        "bnf",
        "dji",
        "o3",
        "vista",
        "walksnail",
        "avatar",
        "hdzero",
        "analog",
        "runcam",
        "caddx",
    ]
    for p in protocols:
        if re.search(r"(?<![0-9a-z])" + re.escape(p) + r"(?![0-9a-z])", s):
            specs["protocol_system"].add(p)

    if re.search(r"(?<![0-9a-z])(rx|receiver|приемник|приёмник|радиоприемник|радиоприёмник)(?![0-9a-z])", s):
        specs["role"].add("rx")
    if re.search(r"(?<![0-9a-z])(tx|transmitter|передатчик)(?![0-9a-z])", s):
        specs["role"].add("tx")
    if re.search(r"(?<![0-9a-z])(analog|аналоговая|аналоговый)(?![0-9a-z])", s):
        specs["video_system"].add("analog")
    if re.search(r"(?<![0-9a-z])(digital|цифровая|цифровой)(?![0-9a-z])", s):
        specs["video_system"].add("digital")

    colors = {
        "black": ["black", "черн", "черный", "черная"],
        "white": ["white", "белый", "белая"],
        "red": ["red", "красн"],
        "blue": ["blue", "син"],
        "green": ["green", "зелен"],
        "yellow": ["yellow", "желт"],
        "orange": ["orange", "оранж"],
        "gray": ["gray", "grey", "сер"],
        "clear": ["clear", "transparent", "прозрач"],
        "purple": ["purple", "фиолет"],
    }
    for canonical, aliases in colors.items():
        if any(re.search(r"(?<![0-9a-zа-я])" + re.escape(a) + r"[0-9a-zа-я]*", s) for a in aliases):
            specs["color"].add(canonical)

    for val in re.findall(r"\bv([1-9]\d?)\b", s):
        specs["version"].add("v" + val)
    for val in re.findall(r"\bmk\s*([1-9]\d?)\b", s):
        specs["version"].add("mk" + val)
    if re.search(r"\bfun\s*fly\b|\bfunfly\b", s):
        specs["battery_series"].add("funfly")
    if re.search(r"\br[\s-]*line\b", s):
        specs["battery_series"].add("r-line")
    if re.search(r"\bblack\s+series\b", s):
        specs["battery_series"].add("black-series")
    if re.search(r"\bspeedy\s+pizza\b|\bpizza\b", s):
        specs["battery_series"].add("pizza")
    if re.search(r"\blava\b", s):
        specs["battery_series"].add("lava")
    if re.search(r"\bv6\b", s) and re.search(r"\btattu\b", s):
        specs["battery_series"].add("tattu-v6")
    if re.search(r"(?<![a-z])male(?![a-z])|\bпапа\b|\bfather\b", s):
        specs["connector_gender"].add("male")
    if re.search(r"(?<![a-z])female(?![a-z])|\bмама\b|\bmother\b", s):
        specs["connector_gender"].add("female")
    if re.search(r"(?<![a-z])ccw(?![a-z])|против\s+час", s):
        specs["rotation"].add("ccw")
    if re.search(r"(?<![a-z])cw(?![a-z])|по\s+час", s):
        specs["rotation"].add("cw")
    return specs


def compact_specs(specs: dict[str, set[str]]) -> dict[str, list[str]]:
    return {k: sorted(v) for k, v in specs.items() if v}


@dataclass
class ProductRep:
    id: int
    supplier_id: int
    supplier_name: str
    supplier_code: str
    offer_id: str
    vendor_code: str
    name: str
    category_path: str
    brand_raw: str
    brand: str
    category: str
    price_original: float | None
    stock_qty: int
    count_qty: int
    url: str
    available: str
    params: dict[str, list[str]]
    tokens: list[str]
    token_set: set[str]
    specs: dict[str, set[str]]
    norm_name: str
    match_text: str


def product_repr(row: dict[str, Any], suppliers: dict[int, dict[str, Any]], fields: dict[int, dict[str, list[str]]]) -> ProductRep:
    product_id = int(row["id"])
    params = flatten_params(safe_json_loads(row.get("params_json")))
    useful_params_text = params_text_for_matching(params)
    field_values = fields.get(product_id, {})
    field_text = " ".join(
        value
        for key, values in field_values.items()
        if key.endswith(":model") or key.endswith(":same_model") or key.endswith(":brand")
        for value in values[:3]
    )
    match_text = " ".join(
        [
            str(row.get("name") or ""),
            str(row.get("brand") or ""),
            str(row.get("category_path") or ""),
            useful_params_text,
            field_text,
        ]
    )
    brand = detect_brand(match_text, row.get("brand") or "")
    category = classify_category(match_text)
    toks = tokenize(match_text)
    specs = extract_specs(match_text)
    supplier = suppliers.get(int(row["supplier_id"]), {})
    url = first_field(field_values, "tag:url")
    available = first_field(field_values, "attr:available")
    return ProductRep(
        id=product_id,
        supplier_id=int(row["supplier_id"]),
        supplier_name=str(supplier.get("name") or row["supplier_id"]),
        supplier_code=str(supplier.get("supplier_code") or ""),
        offer_id=str(row.get("offer_id") or ""),
        vendor_code=str(row.get("vendor_code") or ""),
        name=str(row.get("name") or ""),
        category_path=str(row.get("category_path") or ""),
        brand_raw=str(row.get("brand") or ""),
        brand=brand,
        category=category,
        price_original=float(row["price_original"]) if row.get("price_original") not in (None, "") else None,
        stock_qty=int(row.get("stock_qty") or 0),
        count_qty=int(row.get("count_qty") or 0),
        url=url,
        available=available,
        params=params,
        tokens=toks,
        token_set=set(toks),
        specs=specs,
        norm_name=" ".join(tokenize(row.get("name") or "")),
        match_text=normalize_for_tokens(match_text),
    )


def first_field(fields: dict[str, list[str]], key: str) -> str:
    vals = fields.get(key) or []
    return vals[0] if vals else ""


def row_to_product(row: list[Any]) -> dict[str, Any]:
    return dict(zip(PRODUCT_COLUMNS, row))


def row_to_field(row: list[Any]) -> dict[str, Any]:
    return dict(zip(FIELD_COLUMNS, row))


def row_to_supplier(row: list[Any]) -> dict[str, Any]:
    return dict(zip(SUPPLIER_COLUMNS, row))


def extract_data(dump_path: Path, out_dir: Path) -> tuple[list[dict[str, Any]], dict[int, dict[str, Any]], dict[int, dict[str, list[str]]]]:
    out_dir.mkdir(parents=True, exist_ok=True)
    suppliers: dict[int, dict[str, Any]] = {}
    fields: dict[int, dict[str, list[str]]] = defaultdict(lambda: defaultdict(list))
    products: list[dict[str, Any]] = []
    counts = Counter()

    for table, row in iter_insert_rows(
        dump_path,
        {"feedtools_suppliers", "feedtools_supplier_products", "feedtools_supplier_product_fields"},
    ):
        counts[table] += 1
        if table == "feedtools_suppliers":
            item = row_to_supplier(row)
            sid = int(item["id"])
            if sid in TARGET_SUPPLIER_IDS:
                suppliers[sid] = item
        elif table == "feedtools_supplier_product_fields":
            item = row_to_field(row)
            sid = int(item["supplier_id"])
            if sid not in TARGET_SUPPLIER_IDS:
                continue
            name_norm = normalize_mixed_token(str(item["field_name"] or ""))
            keep = name_norm.lower() in FIELD_NAMES_TO_KEEP or PARAM_HINT_RE.search(str(item["field_name"] or ""))
            if not keep:
                continue
            key = f"{item['field_kind']}:{item['field_name']}"
            val = str(item.get("field_value") or "").strip()
            if val:
                fields[int(item["product_id"])][key].append(val)
        elif table == "feedtools_supplier_products":
            item = row_to_product(row)
            sid = int(item["supplier_id"])
            if sid not in TARGET_SUPPLIER_IDS:
                continue
            item.pop("description_html", None)
            item.pop("pictures_json", None)
            products.append(item)

    suppliers_json = {str(k): v for k, v in suppliers.items()}
    fields_json = {str(pid): dict(vals) for pid, vals in fields.items()}
    (out_dir / "suppliers.json").write_text(json.dumps(suppliers_json, ensure_ascii=False, indent=2), encoding="utf-8")
    (out_dir / "fields_selected.json").write_text(json.dumps(fields_json, ensure_ascii=False), encoding="utf-8")
    with (out_dir / "products_raw.jsonl").open("w", encoding="utf-8") as fh:
        for product in products:
            fh.write(json.dumps(product, ensure_ascii=False) + "\n")

    summary = {
        "dump": str(dump_path),
        "rows_seen": dict(counts),
        "target_product_count": len(products),
        "target_field_product_count": len(fields),
        "target_suppliers": suppliers_json,
        "target_counts_by_supplier_id": Counter(str(p["supplier_id"]) for p in products),
    }
    summary["target_counts_by_supplier_id"] = dict(summary["target_counts_by_supplier_id"])
    (out_dir / "extraction_summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    return products, suppliers, fields


def load_extracted(out_dir: Path) -> tuple[list[dict[str, Any]], dict[int, dict[str, Any]], dict[int, dict[str, list[str]]]]:
    suppliers = {int(k): v for k, v in json.loads((out_dir / "suppliers.json").read_text(encoding="utf-8")).items()}
    fields = {
        int(pid): {k: list(v) for k, v in vals.items()}
        for pid, vals in json.loads((out_dir / "fields_selected.json").read_text(encoding="utf-8")).items()
    }
    products = [json.loads(line) for line in (out_dir / "products_raw.jsonl").read_text(encoding="utf-8").splitlines() if line]
    return products, suppliers, fields


def token_idf(reps: list[ProductRep]) -> dict[str, float]:
    df = Counter()
    for rep in reps:
        df.update(rep.token_set)
    n = len(reps)
    return {tok: math.log((n + 1) / (freq + 1)) + 1.0 for tok, freq in df.items()}


def important_tokens(rep: ProductRep, df: Counter[str]) -> set[str]:
    out = set()
    for tok in rep.token_set:
        if tok in STOPWORDS or tok in CATEGORY_STOPWORDS:
            continue
        if len(tok) < 3 and not re.search(r"\d", tok):
            continue
        if df[tok] > 650:
            continue
        if re.fullmatch(r"\d+", tok):
            continue
        if tok in {"rub", "руб", "шт", "one", "ten", "false", "true"}:
            continue
        out.add(tok)
    return out


def build_candidate_pairs(reps: list[ProductRep]) -> tuple[set[tuple[int, int]], Counter[str]]:
    by_id = {r.id: r for r in reps}
    df = Counter()
    for rep in reps:
        df.update(rep.token_set)
    buckets: dict[str, list[int]] = defaultdict(list)
    for rep in reps:
        tokens = important_tokens(rep, df)
        for tok in tokens:
            if df[tok] <= 120:
                buckets[f"tok:{tok}"].append(rep.id)
            if rep.brand and df[tok] <= 240:
                buckets[f"brand:{rep.brand}:{tok}"].append(rep.id)
            if rep.category != "other" and df[tok] <= 180:
                buckets[f"cat:{rep.category}:{tok}"].append(rep.id)
        for key in ("capacity_mah", "cells", "kv", "motor_size", "x_size"):
            for val in rep.specs.get(key, set()):
                if rep.brand:
                    buckets[f"spec:{rep.brand}:{key}:{val}"].append(rep.id)
                if rep.category != "other":
                    buckets[f"spec:{rep.category}:{key}:{val}"].append(rep.id)
        if rep.vendor_code:
            buckets[f"vendor_code:{rep.vendor_code}"].append(rep.id)

    pairs: set[tuple[int, int]] = set()
    for ids in buckets.values():
        ids = sorted(set(ids))
        if len(ids) < 2 or len(ids) > 260:
            continue
        for i, a_id in enumerate(ids[:-1]):
            a = by_id[a_id]
            for b_id in ids[i + 1 :]:
                b = by_id[b_id]
                if a.supplier_id == b.supplier_id:
                    continue
                pairs.add((min(a_id, b_id), max(a_id, b_id)))
    return pairs, df


def weighted_jaccard(a: set[str], b: set[str], idf: dict[str, float]) -> float:
    union = a | b
    if not union:
        return 0.0
    inter = a & b
    num = sum(idf.get(t, 1.0) for t in inter)
    den = sum(idf.get(t, 1.0) for t in union)
    return num / den if den else 0.0


CRITICAL_BY_CATEGORY = {
    "battery": ["capacity_mah", "cells", "c_rate", "version", "battery_series"],
    "motor": ["kv", "motor_size"],
    "propeller": ["x_size", "color", "rotation"],
    "esc": ["amp", "x_size"],
    "flight_controller": ["x_size", "video_system"],
    "stack": ["amp", "x_size", "video_system"],
    "drone": ["protocol_system", "video_system", "cells", "version"],
    "receiver": ["protocol_system", "role"],
    "camera": ["protocol_system", "video_system"],
    "vtx": ["protocol_system", "video_system"],
    "goggles": ["protocol_system", "video_system"],
    "radio": ["protocol_system", "role"],
    "cable_connector": ["connector", "connector_gender"],
}


def set_overlap(a: set[str], b: set[str]) -> bool:
    return bool(a and b and (a & b))


def spec_comparison(a: ProductRep, b: ProductRep) -> tuple[int, int, list[str], list[str]]:
    matches = 0
    mismatches = 0
    match_bits: list[str] = []
    mismatch_bits: list[str] = []
    all_keys = sorted(set(a.specs) | set(b.specs))
    for key in all_keys:
        av = a.specs.get(key, set())
        bv = b.specs.get(key, set())
        if not av or not bv:
            continue
        if av & bv:
            matches += 1
            match_bits.append(f"{key}={','.join(sorted(av & bv))}")
        else:
            important = key in {"capacity_mah", "cells", "kv", "motor_size", "x_size", "amp", "protocol_system", "version"}
            if important:
                mismatches += 1
                mismatch_bits.append(f"{key}: {','.join(sorted(av))} vs {','.join(sorted(bv))}")
    return matches, mismatches, match_bits, mismatch_bits


def category_compatible(a: str, b: str) -> bool:
    if a == b:
        return True
    combos = {
        frozenset({"flight_controller", "stack"}),
        frozenset({"esc", "stack"}),
        frozenset({"vtx", "camera"}),
        frozenset({"cable_connector", "case_mount"}),
    }
    return frozenset({a, b}) in combos or "other" in {a, b}


def critical_conflicts(a: ProductRep, b: ProductRep) -> list[str]:
    categories = {a.category, b.category}
    keys = set()
    for cat in categories:
        keys.update(CRITICAL_BY_CATEGORY.get(cat, []))
    conflicts: list[str] = []
    for key in keys:
        av = a.specs.get(key, set())
        bv = b.specs.get(key, set())
        if av and bv and not (av & bv):
            if key == "protocol_system" and {"dji", "o3"} <= (av | bv):
                continue
            conflicts.append(f"{key}: {','.join(sorted(av))} vs {','.join(sorted(bv))}")
        elif av and bv and av != bv and key in {
            "capacity_mah",
            "kv",
            "motor_size",
            "x_size",
            "amp",
            "c_rate",
            "version",
            "battery_series",
            "connector_gender",
            "rotation",
        }:
            conflicts.append(f"ambiguous {key}: {','.join(sorted(av))} vs {','.join(sorted(bv))}")
    for conflict in model_identifier_conflicts(a, b):
        conflicts.append(conflict)
    if accessory_base_conflict(a, b):
        conflicts.append("accessory/base conflict: camera-for vs full unit")
    if mini_size_conflict(a, b):
        conflicts.append("variant conflict: mini vs full-size board")
    return conflicts


MODEL_ID_EXCLUDE_RE = re.compile(
    r"^(\d+mah|\d+s|\d+c|\d+a|\d+kv|xt\d+|bt\d+(?:\.\d+)?|ph\d+(?:\.\d+)?|"
    r"dc\d+(?:\.\d+)?|\d+(?:\.\d+)?mm|\d+(?:\.\d+)?x\d+(?:\.\d+)?(?:x\d+)?|"
    r"elrs\d*(?:\.\d+)?|o3|usb-c|jst|jstgh\d*p?|ec\d+|aiii[a-z0-9]*|emx\d+|mr\d+)$"
)


def model_identifier_groups(rep: ProductRep) -> dict[str, set[str]]:
    groups: dict[str, set[str]] = defaultdict(set)
    for tok in rep.token_set:
        variants = {tok.lower().strip()}
        if "-" in tok or "_" in tok:
            compact = re.sub(r"[-_]+", "", tok.lower().strip())
            variants.add(compact)
            variants.update(part for part in re.split(r"[-_]+", tok.lower().strip()) if part)
        for t in variants:
            if MODEL_ID_EXCLUDE_RE.fullmatch(t):
                continue
            if ("-" in t or "_" in t) and re.match(r"[a-z]{3,}", t):
                prefix = re.match(r"[a-z]+", t)
                compact = re.sub(r"[-_]+", "", t)
                if prefix and len(compact) >= len(prefix.group(0)) + 2 and not MODEL_ID_EXCLUDE_RE.fullmatch(compact):
                    groups[prefix.group(0)].add(compact)
            if re.fullmatch(r"[a-z]{1,12}[0-9][a-z0-9.]*", t):
                prefix = re.match(r"[a-z]+", t)
                if prefix:
                    groups[prefix.group(0)].add(t)
            elif re.fullmatch(r"[a-z][0-9][a-z]?", t):
                groups[t[0]].add(t)
    return groups


def accessory_base_conflict(a: ProductRep, b: ProductRep) -> bool:
    if a.brand != "dji" or b.brand != "dji":
        return False
    an = normalize_for_tokens(a.name)
    bn = normalize_for_tokens(b.name)
    if "o3" not in an or "o3" not in bn or "air unit" not in (an + " " + bn):
        return False
    a_camera_for = bool(re.search(r"\bкамера\s+для\b|\bcamera\s+for\b", an))
    b_camera_for = bool(re.search(r"\bкамера\s+для\b|\bcamera\s+for\b", bn))
    return a_camera_for != b_camera_for


def mini_size_conflict(a: ProductRep, b: ProductRep) -> bool:
    if not ({a.category, b.category} & {"flight_controller", "esc", "stack"}):
        return False
    an = normalize_for_tokens(a.name)
    bn = normalize_for_tokens(b.name)
    a_mini = bool(re.search(r"(?<![0-9a-z])mini(?![0-9a-z])|мини", an))
    b_mini = bool(re.search(r"(?<![0-9a-z])mini(?![0-9a-z])|мини", bn))
    if a_mini == b_mini:
        return False
    a_full = bool((a.specs.get("x_size") or set()) & {"30x30", "30.5x30.5"})
    b_full = bool((b.specs.get("x_size") or set()) & {"30x30", "30.5x30.5"})
    return a_full or b_full


def model_identifier_conflicts(a: ProductRep, b: ProductRep) -> list[str]:
    if a.brand and b.brand and a.brand != b.brand:
        return []
    if not category_compatible(a.category, b.category):
        return []
    ga = model_identifier_groups(a)
    gb = model_identifier_groups(b)
    conflicts: list[str] = []
    flat_a = set().union(*ga.values()) if ga else set()
    flat_b = set().union(*gb.values()) if gb else set()
    strict_categories = {
        "motor",
        "esc",
        "flight_controller",
        "stack",
        "drone",
        "receiver",
        "radio",
        "camera",
        "vtx",
        "goggles",
        "charger",
        "cable_connector",
    }
    if flat_a and flat_b and not (flat_a & flat_b) and ({a.category, b.category} & strict_categories):
        return [f"model_id no shared exact id: {','.join(sorted(flat_a)[:4])} vs {','.join(sorted(flat_b)[:4])}"]
    for prefix in sorted(set(ga) & set(gb)):
        av = ga[prefix]
        bv = gb[prefix]
        if av and bv and not (av & bv):
            conflicts.append(f"model_id {prefix}: {','.join(sorted(av))} vs {','.join(sorted(bv))}")
    return conflicts[:3]


def pair_score(a: ProductRep, b: ProductRep, idf: dict[str, float], df: Counter[str]) -> dict[str, Any]:
    a_imp = important_tokens(a, df)
    b_imp = important_tokens(b, df)
    token_score = weighted_jaccard(a_imp, b_imp, idf)
    name_ratio = SequenceMatcher(None, a.norm_name, b.norm_name).ratio() if a.norm_name and b.norm_name else 0.0
    match_count, mismatch_count, spec_matches, spec_mismatches = spec_comparison(a, b)
    conflicts = critical_conflicts(a, b)
    brand_score = 0.0
    brand_reason = ""
    if a.brand and b.brand:
        if a.brand == b.brand:
            brand_score = 1.0
            brand_reason = f"brand={a.brand}"
        else:
            brand_score = -1.0
            brand_reason = f"brand mismatch: {a.brand} vs {b.brand}"
    elif a.brand or b.brand:
        brand_score = 0.15
    category_score = 1.0 if a.category == b.category else (0.35 if category_compatible(a.category, b.category) else -0.45)
    spec_score = 0.0
    if match_count or mismatch_count:
        spec_score = (match_count - 1.35 * mismatch_count) / max(1, match_count + mismatch_count)
    rare_overlap = sorted((a_imp & b_imp), key=lambda t: (df[t], t))[:10]
    rare_score = min(1.0, sum(1 for t in rare_overlap if df[t] <= 80) / 4)

    score = (
        0.38 * token_score
        + 0.17 * name_ratio
        + 0.15 * max(0.0, brand_score)
        + 0.10 * max(0.0, category_score)
        + 0.15 * max(-0.5, spec_score)
        + 0.12 * rare_score
    )
    if brand_score < 0:
        score -= 0.20
    if category_score < 0:
        score -= 0.12
    if conflicts:
        score -= 0.18 * len(conflicts)
    if mismatch_count >= 2:
        score -= 0.12
    if a.vendor_code and b.vendor_code and a.vendor_code == b.vendor_code:
        score += 0.16
    if a.offer_id and b.offer_id and a.offer_id == b.offer_id:
        score += 0.12
    if a.brand and a.brand == b.brand and match_count >= 2 and rare_overlap:
        score += 0.10
    if name_ratio > 0.82 and token_score > 0.35:
        score += 0.08
    score = max(0.0, min(1.0, score))

    if score >= 0.80 and not conflicts:
        confidence = "high"
    elif score >= 0.70 and not conflicts:
        confidence = "medium"
    else:
        confidence = "low"

    reasons = []
    if brand_reason:
        reasons.append(brand_reason)
    if a.category == b.category:
        reasons.append(f"category={a.category}")
    elif category_compatible(a.category, b.category):
        reasons.append(f"close category={a.category}/{b.category}")
    if spec_matches:
        reasons.append("specs: " + "; ".join(spec_matches[:5]))
    if rare_overlap:
        reasons.append("tokens: " + ", ".join(rare_overlap[:8]))
    if spec_mismatches:
        reasons.append("mismatch: " + "; ".join(spec_mismatches[:4]))
    if conflicts:
        reasons.append("critical conflict: " + "; ".join(conflicts[:4]))

    return {
        "a_id": a.id,
        "b_id": b.id,
        "score": round(score, 4),
        "confidence": confidence,
        "token_score": round(token_score, 4),
        "name_ratio": round(name_ratio, 4),
        "brand_score": brand_score,
        "category_score": category_score,
        "spec_score": round(spec_score, 4),
        "spec_matches": spec_matches,
        "spec_mismatches": spec_mismatches,
        "critical_conflicts": conflicts,
        "overlap_tokens": rare_overlap,
        "reason": " | ".join(reasons),
    }


class UnionFind:
    def __init__(self) -> None:
        self.parent: dict[int, int] = {}

    def find(self, x: int) -> int:
        self.parent.setdefault(x, x)
        if self.parent[x] != x:
            self.parent[x] = self.find(self.parent[x])
        return self.parent[x]

    def union(self, a: int, b: int) -> None:
        ra, rb = self.find(a), self.find(b)
        if ra != rb:
            self.parent[rb] = ra


def cluster_matches(pairs: list[dict[str, Any]], reps_by_id: dict[int, ProductRep]) -> list[dict[str, Any]]:
    uf = UnionFind()
    for pair in pairs:
        if pair["confidence"] == "high":
            uf.union(int(pair["a_id"]), int(pair["b_id"]))
    components: dict[int, list[int]] = defaultdict(list)
    for pair in pairs:
        if pair["confidence"] == "high":
            components[uf.find(int(pair["a_id"]))].append(int(pair["a_id"]))
            components[uf.find(int(pair["b_id"]))].append(int(pair["b_id"]))

    pair_score_map = {(min(int(p["a_id"]), int(p["b_id"])), max(int(p["a_id"]), int(p["b_id"]))): p for p in pairs}
    clusters = []
    group_no = 1
    for ids in components.values():
        uniq = sorted(set(ids))
        supplier_count = len({reps_by_id[i].supplier_id for i in uniq})
        if supplier_count < 2:
            continue
        scores = []
        reasons = []
        for i, a_id in enumerate(uniq[:-1]):
            for b_id in uniq[i + 1 :]:
                p = pair_score_map.get((min(a_id, b_id), max(a_id, b_id)))
                if p and p["confidence"] == "high":
                    scores.append(float(p["score"]))
                    if p.get("reason"):
                        reasons.append(p["reason"])
        clusters.append(
            {
                "group_id": group_no,
                "product_ids": uniq,
                "supplier_count": supplier_count,
                "product_count": len(uniq),
                "score_avg": round(statistics.mean(scores), 4) if scores else 0.0,
                "score_min": round(min(scores), 4) if scores else 0.0,
                "reason": reasons[0] if reasons else "",
            }
        )
        group_no += 1
    clusters.sort(key=lambda c: (-c["supplier_count"], -c["score_avg"], c["group_id"]))
    for idx, cluster in enumerate(clusters, 1):
        cluster["group_id"] = idx
    return clusters


def enforce_mutual_best(pairs: list[dict[str, Any]], reps_by_id: dict[int, ProductRep], tolerance: float = 0.001) -> list[dict[str, Any]]:
    best: dict[tuple[int, int], float] = defaultdict(float)
    for pair in pairs:
        a = reps_by_id[int(pair["a_id"])]
        b = reps_by_id[int(pair["b_id"])]
        score = float(pair["score"])
        best[(a.id, b.supplier_id)] = max(best[(a.id, b.supplier_id)], score)
        best[(b.id, a.supplier_id)] = max(best[(b.id, a.supplier_id)], score)

    adjusted: list[dict[str, Any]] = []
    for pair in pairs:
        pair = dict(pair)
        if pair["confidence"] == "high":
            a = reps_by_id[int(pair["a_id"])]
            b = reps_by_id[int(pair["b_id"])]
            score = float(pair["score"])
            mutual = (
                score >= best[(a.id, b.supplier_id)] - tolerance
                and score >= best[(b.id, a.supplier_id)] - tolerance
            )
            if not mutual:
                pair["confidence"] = "medium"
                pair["reason"] = (pair.get("reason") or "") + " | downgraded: not mutual best"
        adjusted.append(pair)
    return adjusted


def supplier_cell(rep: ProductRep) -> str:
    parts = []
    article = rep.vendor_code or rep.offer_id
    if article:
        parts.append(f"арт. {article}")
    parts.append(rep.name)
    if rep.price_original is not None:
        parts.append(f"цена {rep.price_original:g}")
    if rep.url:
        parts.append(rep.url)
    return "\n".join(parts)


def write_csvs(
    out_dir: Path,
    reps: list[ProductRep],
    pairs: list[dict[str, Any]],
    clusters: list[dict[str, Any]],
) -> None:
    reps_by_id = {r.id: r for r in reps}
    suppliers_by_name = {r.supplier_name for r in reps}
    ordered_suppliers = [s for s in TARGET_SUPPLIERS_ORDER if s in suppliers_by_name]
    ordered_suppliers += sorted(suppliers_by_name - set(ordered_suppliers))

    groups_path = out_dir / "matched_groups.csv"
    group_rows: list[dict[str, Any]] = []
    with groups_path.open("w", encoding="utf-8-sig", newline="") as fh:
        fieldnames = [
            "group_id",
            "confidence",
            "supplier_count",
            "product_count",
            "score_avg",
            "score_min",
            "canonical_brand",
            "category",
            "shared_specs",
            "reason",
            *ordered_suppliers,
        ]
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        for cluster in clusters:
            ids = cluster["product_ids"]
            products = [reps_by_id[i] for i in ids]
            brands = [p.brand for p in products if p.brand]
            categories = [p.category for p in products if p.category != "other"]
            specs_counter = Counter()
            for p in products:
                for k, vals in compact_specs(p.specs).items():
                    for v in vals:
                        specs_counter[f"{k}={v}"] += 1
            shared_specs = "; ".join(k for k, cnt in specs_counter.items() if cnt >= 2)
            row = {
                "group_id": cluster["group_id"],
                "confidence": "high",
                "supplier_count": cluster["supplier_count"],
                "product_count": cluster["product_count"],
                "score_avg": cluster["score_avg"],
                "score_min": cluster["score_min"],
                "canonical_brand": Counter(brands).most_common(1)[0][0] if brands else "",
                "category": Counter(categories).most_common(1)[0][0] if categories else "",
                "shared_specs": shared_specs,
                "reason": cluster["reason"],
            }
            by_supplier: dict[str, list[str]] = defaultdict(list)
            for p in products:
                by_supplier[p.supplier_name].append(supplier_cell(p))
            for supplier in ordered_suppliers:
                row[supplier] = "\n---\n".join(by_supplier.get(supplier, []))
            writer.writerow(row)
            group_rows.append(row)
    (out_dir / "matched_groups.json").write_text(json.dumps(group_rows, ensure_ascii=False, indent=2), encoding="utf-8")

    pairs_path = out_dir / "candidate_pairs.csv"
    candidate_rows: list[dict[str, Any]] = []
    with pairs_path.open("w", encoding="utf-8-sig", newline="") as fh:
        fieldnames = [
            "confidence",
            "score",
            "a_supplier",
            "a_article",
            "a_name",
            "a_brand",
            "a_category",
            "a_price",
            "b_supplier",
            "b_article",
            "b_name",
            "b_brand",
            "b_category",
            "b_price",
            "reason",
        ]
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        for pair in pairs:
            if pair["confidence"] == "low":
                continue
            a = reps_by_id[int(pair["a_id"])]
            b = reps_by_id[int(pair["b_id"])]
            row = {
                "confidence": pair["confidence"],
                "score": pair["score"],
                "a_supplier": a.supplier_name,
                "a_article": a.vendor_code or a.offer_id,
                "a_name": a.name,
                "a_brand": a.brand,
                "a_category": a.category,
                "a_price": a.price_original,
                "b_supplier": b.supplier_name,
                "b_article": b.vendor_code or b.offer_id,
                "b_name": b.name,
                "b_brand": b.brand,
                "b_category": b.category,
                "b_price": b.price_original,
                "reason": pair["reason"],
            }
            writer.writerow(row)
            candidate_rows.append(row)
    (out_dir / "candidate_pairs.json").write_text(json.dumps(candidate_rows, ensure_ascii=False, indent=2), encoding="utf-8")

    products_path = out_dir / "normalized_products.csv"
    with products_path.open("w", encoding="utf-8-sig", newline="") as fh:
        fieldnames = [
            "id",
            "supplier",
            "article",
            "name",
            "brand_raw",
            "brand",
            "category",
            "category_path",
            "price_original",
            "stock_qty",
            "available",
            "url",
            "specs",
            "tokens",
        ]
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        for p in sorted(reps, key=lambda r: (r.supplier_name, r.name, r.id)):
            writer.writerow(
                {
                    "id": p.id,
                    "supplier": p.supplier_name,
                    "article": p.vendor_code or p.offer_id,
                    "name": p.name,
                    "brand_raw": p.brand_raw,
                    "brand": p.brand,
                    "category": p.category,
                    "category_path": p.category_path,
                    "price_original": p.price_original,
                    "stock_qty": p.stock_qty,
                    "available": p.available,
                    "url": p.url,
                    "specs": json.dumps(compact_specs(p.specs), ensure_ascii=False),
                    "tokens": " ".join(sorted(p.token_set)[:80]),
                }
            )


def analyze(out_dir: Path) -> None:
    products, suppliers, fields = load_extracted(out_dir)
    reps = [product_repr(row, suppliers, fields) for row in products]
    idf = token_idf(reps)
    reps_by_id = {r.id: r for r in reps}
    candidate_pairs, df = build_candidate_pairs(reps)

    scored: list[dict[str, Any]] = []
    for idx, (a_id, b_id) in enumerate(sorted(candidate_pairs), 1):
        pair = pair_score(reps_by_id[a_id], reps_by_id[b_id], idf, df)
        if pair["score"] >= 0.60:
            scored.append(pair)

    scored.sort(key=lambda p: (-float(p["score"]), p["a_id"], p["b_id"]))
    scored = enforce_mutual_best(scored, reps_by_id)
    clusters = cluster_matches(scored, reps_by_id)
    write_csvs(out_dir, reps, scored, clusters)

    summary = {
        "product_count": len(reps),
        "counts_by_supplier": dict(Counter(r.supplier_name for r in reps)),
        "candidate_pair_count": len(candidate_pairs),
        "scored_pair_count_score_ge_0_60": len(scored),
        "high_pair_count": sum(1 for p in scored if p["confidence"] == "high"),
        "medium_pair_count": sum(1 for p in scored if p["confidence"] == "medium"),
        "high_cluster_count": len(clusters),
        "high_cluster_products": sum(c["product_count"] for c in clusters),
        "high_cluster_supplier_distribution": dict(Counter(str(c["supplier_count"]) for c in clusters)),
    }
    (out_dir / "analysis_summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    (out_dir / "scored_pairs.jsonl").write_text(
        "\n".join(json.dumps(p, ensure_ascii=False) for p in scored) + "\n",
        encoding="utf-8",
    )
    reps_json = []
    for rep in reps:
        d = asdict(rep)
        d["token_set"] = sorted(rep.token_set)
        d["specs"] = compact_specs(rep.specs)
        d.pop("params", None)
        d.pop("match_text", None)
        reps_json.append(d)
    (out_dir / "normalized_products.json").write_text(json.dumps(reps_json, ensure_ascii=False), encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dump", type=Path, default=DEFAULT_DUMP)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT)
    parser.add_argument("--skip-extract", action="store_true")
    args = parser.parse_args()
    if not args.skip_extract:
        extract_data(args.dump, args.out)
    analyze(args.out)


if __name__ == "__main__":
    main()
