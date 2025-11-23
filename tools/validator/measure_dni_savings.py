#!/usr/bin/env python3
"""
DNI Savings Measurement Tool

Measures bandwidth, token usage, and speed differences between HTML and MR
for WordPress sites using the Dual-Native API plugin.

Usage:
  python measure_dni_savings.py \
    --base https://example.com \
    --user USERNAME \
    --app-pass "APPLICATION PASSWORD" \
    --limit 20 \
    --out results.csv \
    --json summary.json
"""

import argparse
import base64
import csv
import json
import sys
import time
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError


def fetch(url, headers=None, timeout=20):
    """Fetch URL and return (status, headers_dict, body_bytes, elapsed_ms)"""
    req = Request(url)
    if headers:
        for k, v in headers.items():
            req.add_header(k, v)

    start = time.time()
    try:
        with urlopen(req, timeout=timeout) as resp:
            status = resp.getcode()
            body = resp.read()
            elapsed = (time.time() - start) * 1000  # ms
            hdrs = {k.lower(): v for k, v in resp.headers.items()}
            return status, hdrs, body, elapsed
    except HTTPError as e:
        elapsed = (time.time() - start) * 1000
        hdrs = {k.lower(): v for k, v in (e.headers.items() if e.headers else [])}
        body = e.read() if hasattr(e, 'read') else b""
        return e.code, hdrs, body, elapsed
    except URLError as e:
        elapsed = (time.time() - start) * 1000
        return 0, {}, b"", elapsed


def b64_basic(user, pw):
    raw = f"{user}:{pw}".encode("utf-8")
    return base64.b64encode(raw).decode("ascii")


def kb(nbytes):
    return round((nbytes or 0) / 1024.0, 2)


def estimate_tokens_raw(nbytes):
    # Heuristic: ~1 token per 4 bytes
    return int(round((nbytes or 0) / 4.0))


def estimate_tokens_policy(kbytes, rate_per_kb):
    return int(round((kbytes or 0.0) * rate_per_kb))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", required=True, help="WordPress base URL (e.g., https://example.com)")
    ap.add_argument("--user", required=True, help="WordPress username")
    ap.add_argument("--app-pass", dest="app_pass", required=True, help="WordPress Application Password")
    ap.add_argument("--limit", type=int, default=20, help="Max number of posts to test")
    ap.add_argument("--out", required=True, help="CSV output path")
    ap.add_argument("--json", required=True, help="Summary JSON output path")
    ap.add_argument("--delay", type=float, default=0.5, help="Delay between requests (seconds)")
    ap.add_argument("--status", default="publish", help="Post status filter (publish, draft, any)")
    args = ap.parse_args()

    base = args.base.rstrip("/")
    auth_header = f"Basic {b64_basic(args.user, args.app_pass)}"
    headers = {"Authorization": auth_header, "Accept": "application/json"}

    # Fetch catalog
    print(f"Fetching catalog from {base}/wp-json/dual-native/v1/catalog...")
    catalog_url = f"{base}/wp-json/dual-native/v1/catalog?status={args.status}"
    st, hdrs, body, elapsed = fetch(catalog_url, headers)

    if st != 200:
        print(f"ERROR: Catalog fetch failed with HTTP {st}")
        sys.exit(1)

    try:
        catalog = json.loads(body.decode("utf-8"))
    except Exception as e:
        print(f"ERROR: Invalid catalog JSON: {e}")
        sys.exit(1)

    items = catalog.get("items", [])
    total = len(items)
    print(f"Found {total} posts in catalog")

    sample = items[:args.limit] if args.limit > 0 else items
    print(f"Testing {len(sample)} posts...\n")

    rows = []
    n_304 = 0
    total_html_ms = 0
    total_mr_ms = 0
    total_md_ms = 0

    for idx, item in enumerate(sample, 1):
        rid = item.get("rid")
        cid = item.get("cid", "")
        title = item.get("title", "")
        status_val = item.get("status", "")

        if not rid:
            continue

        print(f"[{idx}/{len(sample)}] Post {rid}: {title[:50]}")

        # Construct URLs
        # For HTML, we need to fetch the actual permalink
        # First get MR to find the human_url
        mr_url = f"{base}/wp-json/dual-native/v1/posts/{rid}"
        md_url = f"{base}/wp-json/dual-native/v1/posts/{rid}/md"

        # Fetch MR (JSON) - first time
        st_mr, h_mr, b_mr, time_mr_initial = fetch(mr_url, headers)

        if st_mr != 200:
            print(f"  WARN: MR fetch failed with HTTP {st_mr}")
            continue

        # Parse MR to get human_url
        try:
            mr_data = json.loads(b_mr.decode("utf-8"))
            human_url = mr_data.get("links", {}).get("human_url", "")
        except Exception:
            print(f"  WARN: Invalid MR JSON")
            continue

        if not human_url:
            print(f"  WARN: No human_url found")
            continue

        # Fetch HTML (no auth needed for published posts)
        st_html, h_html, b_html, time_html = fetch(human_url, {})

        # Fetch Markdown
        st_md, h_md, b_md, time_md = fetch(md_url, headers)

        # Test zero-fetch with If-None-Match
        got_304 = False
        etag = h_mr.get("etag", "").strip().strip('"')
        if etag:
            st_cg, h_cg, b_cg, time_mr_304 = fetch(mr_url, {**headers, "If-None-Match": f'"{etag}"'})
            if st_cg == 304:
                got_304 = True
                n_304 += 1

        # Calculate sizes
        html_kb = kb(len(b_html)) if st_html == 200 else 0.0
        mr_kb = kb(len(b_mr)) if st_mr == 200 else 0.0
        md_kb = kb(len(b_md)) if st_md == 200 else 0.0

        # Calculate tokens
        html_tokens_raw = estimate_tokens_raw(len(b_html)) if st_html == 200 else 0
        mr_tokens_raw = estimate_tokens_raw(len(b_mr)) if st_mr == 200 else 0
        md_tokens_raw = estimate_tokens_raw(len(b_md)) if st_md == 200 else 0

        html_tokens_policy = estimate_tokens_policy(html_kb, 750)
        mr_tokens_policy = estimate_tokens_policy(mr_kb, 400)
        md_tokens_policy = estimate_tokens_policy(md_kb, 400)

        # Calculate savings
        bandwidth_savings_pct = round(((html_kb - mr_kb) / html_kb * 100), 2) if html_kb > 0 else 0.0
        token_savings_pct = round(((html_tokens_raw - mr_tokens_raw) / html_tokens_raw * 100), 2) if html_tokens_raw > 0 else 0.0

        total_html_ms += time_html
        total_mr_ms += time_mr_initial
        total_md_ms += time_md

        print(f"  HTML: {html_kb:.2f} KB ({time_html:.0f}ms) | MR: {mr_kb:.2f} KB ({time_mr_initial:.0f}ms) | MD: {md_kb:.2f} KB ({time_md:.0f}ms)")
        print(f"  Savings: {bandwidth_savings_pct:.1f}% bandwidth, {token_savings_pct:.1f}% tokens | 304: {got_304}")

        rows.append([
            rid,
            title,
            status_val,
            human_url,
            mr_url,
            f"{html_kb:.2f}",
            f"{mr_kb:.2f}",
            f"{md_kb:.2f}",
            html_tokens_raw,
            mr_tokens_raw,
            md_tokens_raw,
            html_tokens_policy,
            mr_tokens_policy,
            md_tokens_policy,
            f"{bandwidth_savings_pct:.2f}",
            f"{token_savings_pct:.2f}",
            f"{time_html:.0f}",
            f"{time_mr_initial:.0f}",
            f"{time_md:.0f}",
            cid,
            str(got_304).lower(),
        ])

        time.sleep(args.delay)

    # Write CSV
    print(f"\nWriting results to {args.out}...")
    with open(args.out, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow([
            "rid",
            "title",
            "status",
            "human_url",
            "mr_url",
            "html_kb",
            "mr_kb",
            "md_kb",
            "html_tokens_raw",
            "mr_tokens_raw",
            "md_tokens_raw",
            "html_tokens_policy",
            "mr_tokens_policy",
            "md_tokens_policy",
            "bandwidth_savings_pct",
            "token_savings_pct",
            "time_html_ms",
            "time_mr_ms",
            "time_md_ms",
            "cid",
            "got_304",
        ])
        w.writerows(rows)

    # Calculate summary statistics
    def avg(vals):
        vals = [v for v in vals if isinstance(v, (int, float))]
        return round(sum(vals) / len(vals), 2) if vals else 0.0

    html_kbs = [float(r[5]) for r in rows]
    mr_kbs = [float(r[6]) for r in rows]
    md_kbs = [float(r[7]) for r in rows]
    html_tokens = [int(r[8]) for r in rows]
    mr_tokens = [int(r[9]) for r in rows]
    md_tokens = [int(r[10]) for r in rows]
    bandwidth_savings = [float(r[14]) for r in rows]
    token_savings = [float(r[15]) for r in rows]
    html_times = [float(r[16]) for r in rows]
    mr_times = [float(r[17]) for r in rows]
    md_times = [float(r[18]) for r in rows]

    total_tested = len(rows)
    summary = {
        "site": args.base,
        "count": total_tested,
        "avg_html_kb": avg(html_kbs),
        "avg_mr_kb": avg(mr_kbs),
        "avg_md_kb": avg(md_kbs),
        "avg_html_tokens_raw": avg(html_tokens),
        "avg_mr_tokens_raw": avg(mr_tokens),
        "avg_md_tokens_raw": avg(md_tokens),
        "avg_bandwidth_savings_pct": avg(bandwidth_savings),
        "avg_token_savings_pct": avg(token_savings),
        "avg_time_html_ms": avg(html_times),
        "avg_time_mr_ms": avg(mr_times),
        "avg_time_md_ms": avg(md_times),
        "zero_fetch_rate_pct": round((n_304 / total_tested) * 100.0, 2) if total_tested else 0.0,
        "speedup_factor": round(avg(html_times) / avg(mr_times), 2) if avg(mr_times) > 0 else 0.0,
    }

    print(f"Writing summary to {args.json}...")
    with open(args.json, "w", encoding="utf-8") as jf:
        json.dump(summary, jf, ensure_ascii=False, indent=2)

    print("\n" + "="*60)
    print("MEASUREMENT SUMMARY")
    print("="*60)
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    print("="*60)


if __name__ == "__main__":
    sys.exit(main())
