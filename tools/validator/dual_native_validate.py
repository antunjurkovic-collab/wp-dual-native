#!/usr/bin/env python3
"""
Dual-Native Validator

Validates MR JSON, CID/ETag parity, and zero-fetch behavior for the
WordPress Dual-Native internal endpoints.

Usage:
  python tools/validator/dual_native_validate.py \
    --base https://example.com \
    --post 956 \
    --user USERNAME \
    --app-pass APPLICATION_PASSWORD \
    [--exclude modified,published,status]

Notes:
  - Requires 'requests' (pip install requests)
  - Does not modify content.
  - For published posts, you may also test public routes with --public.
"""

import argparse
import base64
import hashlib
import json
import sys
from typing import Any, Dict, List

try:
    import requests  # type: ignore
except Exception as e:
    print("ERROR: Missing dependency 'requests'. Install with: pip install requests", file=sys.stderr)
    sys.exit(2)


def canonicalize(obj: Any) -> Any:
    if isinstance(obj, dict):
        return {k: canonicalize(obj[k]) for k in sorted(obj.keys())}
    if isinstance(obj, list):
        return [canonicalize(v) for v in obj]
    return obj


def compute_cid(mr: Dict[str, Any], exclude: List[str]) -> str:
    clean = {k: v for k, v in mr.items() if k not in set(exclude)}
    canon = canonicalize(clean)
    data = json.dumps(canon, ensure_ascii=False, separators=(",", ":"))
    h = hashlib.sha256(data.encode("utf-8")).hexdigest()
    return f"sha256-{h}"


def b64_basic(user: str, pw: str) -> str:
    raw = f"{user}:{pw}".encode("utf-8")
    return base64.b64encode(raw).decode("ascii")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", required=True, help="Site base URL e.g., https://yoursite")
    ap.add_argument("--post", type=int, required=True, help="Post ID to validate")
    ap.add_argument("--user", help="WP username (for Application Password auth)")
    ap.add_argument("--app-pass", dest="app_pass", help="WP Application Password")
    ap.add_argument("--exclude", default="cid", help="Comma list of MR keys to exclude in CID check (default: cid)")
    ap.add_argument("--public", action="store_true", help="Also validate public read-only routes (published posts)")
    args = ap.parse_args()

    base = args.base.rstrip("/")
    pid = args.post
    exclude_keys = [k.strip() for k in args.exclude.split(",") if k.strip()]
    sess = requests.Session()
    headers = {"Accept": "application/json"}
    if args.user and args.app_pass:
        headers["Authorization"] = f"Basic {b64_basic(args.user, args.app_pass)}"

    def get(path: str, extra_headers: Dict[str, str] = None):
        h = dict(headers)
        if extra_headers:
            h.update(extra_headers)
        url = f"{base}{path}"
        return sess.get(url, headers=h, timeout=20)

    ok = True
    print(f"\n== MR JSON (/dual-native/v1/posts/{pid}) ==")
    r = get(f"/wp-json/dual-native/v1/posts/{pid}")
    print(f"HTTP {r.status_code}")
    if r.status_code != 200:
        print("FAIL: Expected 200 for MR JSON")
        sys.exit(1)
    etag = (r.headers.get("ETag") or r.headers.get("Etag") or "").strip().strip('"')
    try:
        mr = r.json()
    except Exception:
        print("FAIL: MR response not JSON")
        sys.exit(1)
    for key in ["rid","title","status","blocks","word_count","cid"]:
        if key not in mr:
            print(f"FAIL: Missing MR key: {key}")
            ok = False
    if not mr.get("blocks"):
        print("WARN: MR blocks[] is empty")
    cid = mr.get("cid","")
    if not cid.startswith("sha256-"):
        print("FAIL: CID format invalid")
        ok = False
    if etag and etag != cid:
        print(f"FAIL: ETag != CID ({etag} vs {cid})")
        ok = False
    # CID recompute
    try:
        recomputed = compute_cid({k:v for k,v in mr.items()}, exclude_keys)
        if recomputed != cid:
            print(f"WARN: Local CID recompute mismatch (server {cid} vs local {recomputed}).\n      If you exclude additional keys server-side via dni_cid_exclude_keys, pass --exclude to match.")
        else:
            print("OK: CID recompute matches")
    except Exception as e:
        print(f"WARN: CID recompute error: {e}")

    # Zero-fetch check
    print("\n-- Zero-fetch with If-None-Match --")
    r2 = get(f"/wp-json/dual-native/v1/posts/{pid}", {"If-None-Match": f'"{cid}"'})
    print(f"HTTP {r2.status_code} (expected 304)")
    if r2.status_code != 304:
        print("FAIL: Expected 304 when ETag matches")
        ok = False

    # Markdown MR
    print(f"\n== Markdown MR (/dual-native/v1/posts/{pid}/md) ==")
    rmd = get(f"/wp-json/dual-native/v1/posts/{pid}/md")
    print(f"HTTP {rmd.status_code}")
    if rmd.status_code != 200:
        print("FAIL: Expected 200 for Markdown MR")
        ok = False
    ctype = rmd.headers.get("Content-Type","")
    if "text/markdown" not in ctype:
        print(f"WARN: Content-Type not text/markdown (got {ctype})")
    etag_md = (rmd.headers.get("ETag") or rmd.headers.get("Etag") or "").strip().strip('"')
    # Zero-fetch for MD
    rmd2 = get(f"/wp-json/dual-native/v1/posts/{pid}/md", {"If-None-Match": f'"{etag_md}"'})
    print(f"HTTP {rmd2.status_code} (expected 304 for Markdown)")
    if rmd2.status_code != 304:
        print("FAIL: Expected 304 for Markdown when ETag matches")
        ok = False

    # Catalog
    print("\n== Catalog (/dual-native/v1/catalog) ==")
    rc = get("/wp-json/dual-native/v1/catalog")
    print(f"HTTP {rc.status_code}")
    if rc.status_code == 200:
        try:
            data = rc.json()
            cnt = int(data.get("count", 0))
            print(f"OK: Catalog count={cnt}")
        except Exception:
            print("WARN: Catalog not JSON")
    else:
        print("WARN: Catalog not accessible (auth?)")

    # Public routes
    if args.public:
        print("\n== Public MR routes (no auth) ==")
        rpub = requests.get(f"{base}/wp-json/dual-native/v1/public/posts/{pid}", timeout=20)
        print(f"HTTP {rpub.status_code} (MR public)")
        rpubmd = requests.get(f"{base}/wp-json/dual-native/v1/public/posts/{pid}/md", timeout=20)
        print(f"HTTP {rpubmd.status_code} (MD public)")

    print("\nSummary:")
    print("PASS" if ok else "FAIL")
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()

