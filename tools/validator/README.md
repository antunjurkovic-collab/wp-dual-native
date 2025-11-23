# Dual-Native API Validation & Benchmark Tools

Python scripts for validating and benchmarking the Dual-Native API WordPress plugin.

## Tools

### 1. `dual_native_validate.py`

Validates a WordPress site's Dual-Native API implementation.

**Tests:**
- ✅ Machine Representation (MR) structure
- ✅ Content Identity (CID) parity
- ✅ Zero-fetch optimization (304 responses)
- ✅ Public endpoints (no auth)
- ✅ Safe write API (optimistic locking)

**Usage:**
```bash
python dual_native_validate.py \
  --base https://your-site.com \
  --user USERNAME \
  --app-pass "APPLICATION PASSWORD" \
  --rid POST_ID
```

---

### 2. `benchmark_api_vs_dni.py`

Fair comparison: **Standard WordPress REST API vs Dual-Native API** (JSON to JSON).

**Measures:**
- Payload size (KB)
- Token count (estimated)
- Response time (ms)
- Signal-to-noise ratio (_links, HTML escaping, WP classes)
- Zero-fetch support

**Outputs:**
- CSV file with per-post results
- JSON summary with aggregate stats

**Usage:**
```bash
python benchmark_api_vs_dni.py \
  --base https://your-site.com \
  --user USERNAME \
  --app-pass "APPLICATION PASSWORD" \
  --limit 10 \
  --out results.csv \
  --json summary.json
```

**Example Summary:**
```json
{
  "site": "https://galaxybilliard.club",
  "posts_tested": 10,
  "standard_api": {
    "avg_payload_kb": 17.94,
    "avg_tokens": 4593,
    "avg_time_ms": 2365
  },
  "dual_native_api": {
    "avg_payload_kb": 8.65,
    "avg_tokens": 2214,
    "avg_time_ms": 2110
  },
  "improvements": {
    "avg_size_savings_pct": 56.4,
    "avg_token_savings_pct": 56.0,
    "avg_speedup_factor": 1.12
  }
}
```

**See:** [BENCHMARK.md](../../BENCHMARK.md) for full results and analysis.

---

### 3. `measure_dni_savings.py`

Measures bandwidth/token savings: **HTML frontend vs Machine Representation (MR)**.

**Measures:**
- HTML page size vs MR size vs Markdown size
- Token usage (raw bytes ÷ 4 heuristic)
- Response times
- Zero-fetch rate

**Outputs:**
- CSV file with per-post results
- JSON summary with aggregate stats

**Usage:**
```bash
python measure_dni_savings.py \
  --base https://your-site.com \
  --user USERNAME \
  --app-pass "APPLICATION PASSWORD" \
  --limit 20 \
  --out results.csv \
  --json summary.json
```

**Example Summary:**
```json
{
  "site": "https://galaxybilliard.club",
  "count": 10,
  "avg_html_kb": 118.4,
  "avg_mr_kb": 8.56,
  "avg_bandwidth_savings_pct": 92.91,
  "avg_token_savings_pct": 92.92,
  "zero_fetch_rate_pct": 100.0,
  "speedup_factor": 1.83
}
```

---

## Requirements

- Python 3.7+
- No external dependencies (uses stdlib only)
- WordPress Application Password (for authenticated requests)

## Generating Application Passwords

1. Log into WordPress Admin
2. Go to **Users → Profile**
3. Scroll to **Application Passwords** section
4. Enter a name (e.g., "Benchmark Tool")
5. Click **Add New Application Password**
6. Copy the generated password

Format: `xxxx xxxx xxxx xxxx xxxx xxxx`

---

## Results

All tools output results in both CSV (for analysis) and JSON (for automation).

**Example workflow:**
```bash
# Validate implementation
python dual_native_validate.py --base https://site.com --user admin --app-pass "xxxx" --rid 123

# Run fair benchmark (Standard API vs DNA)
python benchmark_api_vs_dni.py --base https://site.com --user admin --app-pass "xxxx" --limit 10 --out benchmark.csv --json benchmark_summary.json

# Measure HTML vs MR savings
python measure_dni_savings.py --base https://site.com --user admin --app-pass "xxxx" --limit 20 --out savings.csv --json savings_summary.json
```

---

## Documentation

- **[BENCHMARK.md](../../BENCHMARK.md):** Token/cost analysis and fair API comparison
- **[PERFORMANCE.md](../../PERFORMANCE.md):** Infrastructure metrics (DB queries, memory, profiler data)

---

## License

Same as parent project (Dual-Native API WordPress Plugin)
