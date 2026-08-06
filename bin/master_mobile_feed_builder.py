#!/usr/bin/env python3
"""Build the public Master Mobile feed from a parsed price/stock snapshot."""

from __future__ import annotations

import argparse
import ftplib
import json
import os
import ssl
import sys
import tempfile
import time
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path

from master_mobile_parser import load_image_replacements, load_purchase_prices, merge_parsed_prices_stock


DEFAULT_BASE_URL = "https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml"
DEFAULT_SNAPSHOT = Path("storage/master_mobile/price_stock_snapshot.yml")
DEFAULT_OUTPUT = Path("storage/master_mobile/master_mobile_info.xml")
DEFAULT_SUPPLIER_CODE = "24"


def env(name: str, default: str = "") -> str:
    value = os.environ.get(name)
    return default if value is None else value


def download(url: str, target: Path, timeout: float, insecure: bool) -> None:
    ctx = ssl._create_unverified_context() if insecure else None
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "FeedTools/MasterMobileFeedBuilder"},
    )
    target.parent.mkdir(parents=True, exist_ok=True)
    with urllib.request.urlopen(request, timeout=timeout, context=ctx) as response:
        with target.open("wb") as fh:
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                fh.write(chunk)


def upload_ftp(path: Path, args: argparse.Namespace) -> dict[str, object]:
    host = args.ftp_host or env("MASTER_MOBILE_FTP_HOST")
    user = args.ftp_user or env("MASTER_MOBILE_FTP_USER")
    password = args.ftp_pass or env("MASTER_MOBILE_FTP_PASS")
    remote_dir = args.ftp_dir or env("MASTER_MOBILE_FTP_DIR", "/public_html/xml")
    remote_file = args.ftp_file or env("MASTER_MOBILE_FTP_FILE", "master_mobile_info.xml")
    timeout = args.ftp_timeout
    use_tls = args.ftp_tls or env("MASTER_MOBILE_FTP_TLS") in {"1", "true", "yes"}

    missing = [
        name
        for name, value in {
            "MASTER_MOBILE_FTP_HOST": host,
            "MASTER_MOBILE_FTP_USER": user,
            "MASTER_MOBILE_FTP_PASS": password,
        }.items()
        if not value
    ]
    if missing:
        raise RuntimeError("FTP settings are missing: " + ", ".join(missing))

    cls = ftplib.FTP_TLS if use_tls else ftplib.FTP
    ftp = cls(host=host, timeout=timeout)
    tmp_name = ""
    backup_name = ""
    target_moved = False
    try:
        ftp.login(user=user, passwd=password)
        if use_tls and isinstance(ftp, ftplib.FTP_TLS):
            ftp.prot_p()
        ftp.set_pasv(True)
        ftp.cwd(remote_dir)

        now = int(time.time())
        tmp_prefix = f"{remote_file}.tmp-"
        backup_prefix = f"{remote_file}.bak-"
        cleanup_prefixes = (
            tmp_prefix,
            backup_prefix,
            f".{remote_file}.tmp-",
            f".{remote_file}.bak-",
        )
        try:
            remote_names = ftp.nlst()
        except ftplib.all_errors:
            remote_names = []
        for remote_name in remote_names:
            basename = remote_name.rsplit("/", 1)[-1]
            prefix = next(
                (candidate for candidate in cleanup_prefixes if basename.startswith(candidate)),
                "",
            )
            if not prefix:
                continue
            timestamp_text = basename[len(prefix) :].split("-", 1)[0]
            try:
                is_stale = int(timestamp_text) < now - 3600
            except ValueError:
                is_stale = False
            if is_stale:
                try:
                    ftp.delete(remote_name)
                except ftplib.all_errors:
                    pass

        unique_suffix = f"{now}-{os.getpid()}"
        tmp_name = f"{tmp_prefix}{unique_suffix}"
        backup_name = f"{backup_prefix}{unique_suffix}"
        with path.open("rb") as fh:
            ftp.storbinary(f"STOR {tmp_name}", fh, blocksize=1024 * 1024)

        uploaded_size = ftp.size(tmp_name)
        expected_size = path.stat().st_size
        if uploaded_size != expected_size:
            raise RuntimeError(
                f"Incomplete FTP upload: expected {expected_size} bytes, got {uploaded_size}"
            )

        target_exists = False
        try:
            target_exists = ftp.size(remote_file) is not None
        except ftplib.all_errors:
            pass

        if target_exists:
            ftp.rename(remote_file, backup_name)
            target_moved = True
        try:
            ftp.rename(tmp_name, remote_file)
            tmp_name = ""
        except BaseException:
            if target_moved:
                try:
                    ftp.rename(backup_name, remote_file)
                    target_moved = False
                    backup_name = ""
                except ftplib.all_errors:
                    pass
            raise

        if target_moved:
            target_moved = False
            try:
                ftp.delete(backup_name)
                backup_name = ""
            except ftplib.all_errors:
                pass
    finally:
        if tmp_name:
            try:
                ftp.delete(tmp_name)
            except ftplib.all_errors:
                pass
        if target_moved and backup_name:
            try:
                ftp.rename(backup_name, remote_file)
            except ftplib.all_errors:
                pass
        try:
            ftp.quit()
        except ftplib.all_errors:
            ftp.close()

    return {
        "uploaded": True,
        "ftp_host": host,
        "ftp_dir": remote_dir,
        "ftp_file": remote_file,
    }


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Merge a previously parsed Master Mobile price/stock snapshot "
            "into the full public supplier feed."
        )
    )
    parser.add_argument("--snapshot", type=Path, default=DEFAULT_SNAPSHOT)
    parser.add_argument("--base-feed", type=Path, help="Local full feed to update.")
    parser.add_argument("--base-url", default=DEFAULT_BASE_URL)
    parser.add_argument("-o", "--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--supplier-code", default=DEFAULT_SUPPLIER_CODE)
    parser.add_argument("--zero-missing", action="store_true")
    parser.add_argument("--min-coverage", type=float, default=0.0)
    parser.add_argument(
        "--image-replacements",
        type=Path,
        help="CSV exported by the clean image parser; replaces the first <picture> for matched offers.",
    )
    parser.add_argument(
        "--purchase-prices",
        type=Path,
        help="XLSX/CSV pricelist with article and purchase price columns; updates <price_original>.",
    )
    parser.add_argument("--timeout", type=float, default=120.0)
    parser.add_argument("--insecure", action="store_true")
    parser.add_argument("--upload", action="store_true")
    parser.add_argument("--ftp-host", default="")
    parser.add_argument("--ftp-user", default="")
    parser.add_argument("--ftp-pass", default="")
    parser.add_argument("--ftp-dir", default="")
    parser.add_argument("--ftp-file", default="")
    parser.add_argument("--ftp-timeout", type=float, default=60.0)
    parser.add_argument("--ftp-tls", action="store_true")
    return parser.parse_args(argv)


def cleanup_temp_feed(path: Path) -> None:
    if not path.parent.name.startswith("master_mobile_base_"):
        return
    try:
        path.unlink(missing_ok=True)
        path.parent.rmdir()
    except OSError:
        pass


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    if not args.snapshot.is_file():
        raise RuntimeError(f"Snapshot not found: {args.snapshot}")

    cleanup: list[Path] = []
    try:
        if args.base_feed:
            base_feed = args.base_feed
        else:
            tmp = Path(tempfile.mkdtemp(prefix="master_mobile_base_")) / "base.xml"
            cleanup.append(tmp)
            download(args.base_url, tmp, args.timeout, args.insecure)
            base_feed = tmp

        parsed_tree = ET.parse(args.snapshot)
        image_replacements = load_image_replacements(args.image_replacements) if args.image_replacements else {}
        purchase_prices = load_purchase_prices(args.purchase_prices) if args.purchase_prices else {}
        stats = merge_parsed_prices_stock(
            base_feed=base_feed,
            parsed_tree=parsed_tree,
            output=args.output,
            supplier_code=args.supplier_code,
            zero_missing=args.zero_missing,
            min_coverage=args.min_coverage,
            image_replacements=image_replacements,
            purchase_prices=purchase_prices,
        )

        if args.upload:
            stats.update(upload_ftp(args.output, args))

        print(json.dumps(stats, ensure_ascii=False, indent=2))
        return 0
    finally:
        for path in cleanup:
            cleanup_temp_feed(path)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)
