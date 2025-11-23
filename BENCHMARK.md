# Dual-Native API Performance Benchmark

**Production Benchmark: galaxybilliard.club**
**Test Date:** November 2025
**Sample Size:** 10 published posts
**Methodology:** JSON-to-JSON comparison (Standard WordPress REST API vs Dual-Native API)

---

## Executive Summary

The Dual-Native API delivers **56% payload reduction** and **56% token savings** compared to the Standard WordPress REST API (`/wp/v2/posts`), while providing structured data and safe write operations.

This benchmark compares the **fair baseline** that internal AI tools actually use: the WordPress REST API (`/wp-json/wp/v2/posts/{id}`) versus the Dual-Native API (`/wp-json/dual-native/v1/posts/{id}`).

**Key Finding:** For AI-driven internal tools (title generation, content analysis, automated editing), the Dual-Native API is **56% more efficient** and provides **safer, cleaner data structures**.

> **📊 Looking for Infrastructure Metrics?** See [PERFORMANCE.md](PERFORMANCE.md) for database queries, memory usage, and server-side response times measured with WordPress Query Monitor profiler.

---

## The Fair Comparison

### Why JSON-to-JSON?

Modern WordPress AI tools don't scrape HTML. They use the **WordPress REST API** (`/wp/v2/posts`), which returns JSON containing:
- `content.rendered`: HTML string with escaped characters
- `_links`: 10+ hypermedia objects
- `excerpt.rendered`: More HTML
- Metadata with redundant fields

**This is the real baseline.** Any AI tool built today uses this endpoint.

### Contenders

| Contender | Endpoint | What It Returns | Who Uses It |
|-----------|----------|-----------------|-------------|
| **Standard API** | `/wp-json/wp/v2/posts/{id}` | JSON with escaped HTML strings, `_links`, metadata | Block Editor, existing AI plugins |
| **Dual-Native API** | `/wp-json/dual-native/v1/posts/{id}` | JSON with structured blocks, pure text, no noise | AI agents, MCP servers |

---

## Benchmark Results

### Performance Metrics

| Metric | Standard API | Dual-Native API | Improvement |
|--------|--------------|-----------------|-------------|
| **Average Payload Size** | 17.94 KB | 8.65 KB | **56% Smaller** |
| **Average Token Count** | 4,593 | 2,214 | **56% Fewer** |
| **Average Response Time** | 2,365 ms | 2,110 ms | **12% Faster** |
| **Data Type** | Escaped HTML String | Structured JSON | Clean Logic |
| **Write Safety** | None (Overwrite) | Optimistic Locking | Prevents Data Loss |
| **Noise** | 10 `_links` objects, ~11 WP classes | 0 | 100% Clean |

### Individual Post Results

| Post ID | Title | Standard (KB) | DNA (KB) | Savings |
|---------|-------|---------------|----------|---------|
| 419 | Problematic code | 10.85 | 1.17 | **89.2%** |
| 434 | Why This is the Definitive Solution | 13.99 | 2.53 | **81.9%** |
| 415 | Example title dummy text | 11.84 | 1.93 | **83.7%** |
| 130 | MCP for Beginners | 21.39 | 8.68 | **59.4%** |
| 1066 | Key Takeaways | 16.02 | 7.89 | **50.8%** |
| 1063 | Tournament Format | 17.18 | 9.55 | **44.4%** |
| 956 | Precision, Pressure, and Performance | 22.38 | 12.65 | **43.5%** |
| 463 | Fixed: Editor Buttons Not Responding | 19.65 | 10.82 | **44.9%** |
| 452 | Google Just Eliminated the AI Infrastructure Headache | 18.96 | 11.69 | **38.3%** |
| 588 | AEGE SIDEBAR MIGRATION | 27.15 | 19.59 | **27.9%** |
| **Average** | | **17.94** | **8.65** | **56.4%** |

---

## Cost Analysis

### AI Processing Costs

**Assumptions:**
- GPT-4 Turbo: $3/1M input tokens
- Claude 3.5 Sonnet: $2.50/1M input tokens
- Use case: 10,000 AI operations per year (internal tools)

| Volume | Standard API Tokens | DNA Tokens | Savings (Tokens) |
|--------|---------------------|------------|------------------|
| 1,000 posts | 4,593,000 | 2,214,000 | 2,379,000 (52%) |
| 10,000 posts | 45,930,000 | 22,140,000 | 23,790,000 (52%) |
| 100,000 posts | 459,300,000 | 221,400,000 | 237,900,000 (52%) |

### Annual Cost Savings (10,000 Operations)

| Model | Standard API Cost | DNA Cost | Annual Savings |
|-------|-------------------|----------|----------------|
| **GPT-4 Turbo** | $137.79 | $66.42 | **$71.37** |
| **Claude 3.5 Sonnet** | $114.82 | $55.35 | **$59.47** |

**ROI:** For a site processing 10,000 AI operations annually, the Dual-Native API saves **$60-70 per year** in LLM costs alone.

At scale (1,000,000 operations): **$5,900-7,100 annual savings**.

---

## The "Signal-to-Noise" Problem

### What Makes Standard API Heavy?

#### 1. Hypermedia Links (`_links`)

Every Standard API response includes ~10 hypermedia objects:

```json
{
  "_links": {
    "self": [{"href": "..."}],
    "collection": [{"href": "..."}],
    "about": [{"href": "..."}],
    "author": [{"embeddable": true, "href": "..."}],
    "replies": [{"embeddable": true, "href": "..."}],
    "version-history": [{"count": 12, "href": "..."}],
    "wp:attachment": [{"href": "..."}],
    "wp:term": [{"taxonomy": "category", "embeddable": true, "href": "..."}],
    "curies": [{"name": "wp", "href": "...", "templated": true}]
  }
}
```

**For AI tools:** These links are **never used**. An AI generating a title doesn't need author URLs or attachment endpoints.

**Waste:** ~2-3 KB per post (11-17% of payload).

#### 2. Escaped HTML Strings

```json
{
  "content": {
    "rendered": "<p class=\"wp-block-paragraph\">Content with &lt;escaping&gt; and <span class=\"has-inline-color\">...</span></p><!-- wp:heading -->...",
    "protected": false
  }
}
```

**Problems:**
- HTML must be parsed to extract text
- Escaped characters (`&lt;`, `&gt;`, `&quot;`)
- Layout noise: `class="wp-block-paragraph"`
- WordPress block comments: `<!-- wp:heading -->`

**Measured noise:**
- Average: 11.2 WP class attributes per post
- Range: 1-44 WP classes (Post 588 had 44!)

#### 3. Redundant Fields

- `title.rendered` (when `title.raw` exists)
- `excerpt.rendered` (HTML string)
- `guid.rendered` (not used by AI)

---

### What Makes Dual-Native Clean?

#### 1. Structured Blocks (No HTML)

```json
{
  "blocks": [
    {"type": "core/paragraph", "content": "Pure text content"},
    {"type": "core/heading", "level": 2, "content": "Section Title"},
    {"type": "core/list", "ordered": false, "items": ["Item 1", "Item 2"]}
  ]
}
```

**Benefits:**
- No parsing required
- Semantic structure preserved
- Zero layout noise

#### 2. Pre-Extracted Text

```json
{
  "core_content_text": "All content as pure, unescaped text",
  "word_count": 245
}
```

**Benefits:**
- No HTML stripping needed
- Word count pre-computed
- Ready for AI ingestion

#### 3. Zero Noise

- ❌ No `_links`
- ❌ No HTML escaping
- ❌ No WP class attributes
- ❌ No redundant metadata

**Result:** 100% signal, 0% noise.

---

## Use Case: AI Title Generation

### Scenario

An AI plugin generates SEO-optimized titles based on post content. It needs:
1. Current title
2. Content text
3. Word count (for length estimation)

### Standard API Approach

**1. Fetch Post:**
```http
GET /wp-json/wp/v2/posts/123
```

**2. Receive 17.94 KB including:**
- `title.rendered`
- `content.rendered` (HTML string with escaping)
- `_links` (10 objects, unused)
- `excerpt.rendered` (unused)
- 11 WP class attributes

**3. Parse HTML:**
```javascript
// Must parse content.rendered to extract text
const text = stripHtmlTags(post.content.rendered);
const wordCount = text.split(/\s+/).length;
```

**4. Generate Title:**
```javascript
const newTitle = await ai.generateTitle(text);
```

**5. Update (Unsafe):**
```javascript
// No concurrency control - might overwrite another edit
await fetch('/wp-json/wp/v2/posts/123', {
  method: 'POST',
  body: JSON.stringify({ title: newTitle })
});
```

**Total Overhead:** 4,593 tokens, HTML parsing required, unsafe writes.

---

### Dual-Native API Approach

**1. Fetch Post:**
```http
GET /wp-json/dual-native/v1/posts/123
```

**2. Receive 8.65 KB including:**
- `title` (plain string)
- `core_content_text` (pre-extracted)
- `word_count` (pre-computed)
- `cid` (for safe writes)

**3. No Parsing Needed:**
```javascript
const { title, core_content_text, word_count, cid } = post;
```

**4. Generate Title:**
```javascript
const newTitle = await ai.generateTitle(core_content_text);
```

**5. Update (Safe):**
```javascript
// Optimistic locking prevents lost updates
await fetch('/wp-json/dual-native/v1/posts/123', {
  method: 'PATCH',
  headers: { 'If-Match': cid },
  body: JSON.stringify({ title: newTitle })
});
// Returns 412 if another edit happened meanwhile
```

**Total Overhead:** 2,214 tokens (52% less), no parsing, safe writes.

---

## Use Case: Content Analysis

### Scenario

An AI plugin analyzes content structure to suggest improvements.

### Standard API Challenges

1. **Must parse HTML string** to extract structure
2. **No semantic block information** (everything is HTML)
3. **HTML escaping** must be handled
4. **No word count** (must compute)
5. **11+ WP classes** pollute content

### Dual-Native API Advantages

```json
{
  "blocks": [
    {"type": "core/heading", "level": 2, "content": "Introduction"},
    {"type": "core/paragraph", "content": "First paragraph"},
    {"type": "core/list", "ordered": false, "items": ["Point 1", "Point 2"]}
  ],
  "word_count": 245
}
```

**Benefits:**
- Block structure immediately available
- No HTML parsing
- Clean text content
- Pre-computed metrics

**AI can directly:**
- Count headings: `blocks.filter(b => b.type === 'core/heading').length`
- Analyze structure: `blocks.map(b => b.type)`
- Get clean text: `blocks.map(b => b.content)`

---

## Methodology

### Test Environment

- **Site:** https://galaxybilliard.club
- **Server:** Production WordPress with Dual-Native API plugin
- **Posts Tested:** 10 published posts (variety of lengths and complexity)
- **Date:** November 2025
- **Tool:** Python benchmark script with `urllib` (no external dependencies)

### Measurement Process

For each post:

1. **Fetch Standard API:**
   ```
   GET /wp-json/wp/v2/posts/{id}
   ```
   - Measure: Response size (bytes), time (ms)
   - Parse: JSON, count `_links`, WP classes, HTML escaping

2. **Fetch Dual-Native API:**
   ```
   GET /wp-json/dual-native/v1/posts/{id}
   ```
   - Measure: Response size (bytes), time (ms)
   - Parse: JSON structure

3. **Calculate:**
   - Payload size (KB)
   - Token count (bytes ÷ 4, industry heuristic)
   - Savings percentage
   - Speed improvement factor

4. **Aggregate:**
   - Average across all 10 posts
   - Min/max ranges
   - Cost projections

### Validation

All measurements include:
- ✅ **Authentication:** WordPress Application Passwords (same auth for both APIs)
- ✅ **Caching disabled:** Fresh fetches for accurate size measurement
- ✅ **Network conditions:** Same server, same time window (0.3s delay between requests)
- ✅ **Response verification:** JSON parsing confirmed for both responses

---

## Technical Details

### Standard API Response Structure

```json
{
  "id": 123,
  "date": "2025-11-23T12:00:00",
  "date_gmt": "2025-11-23T12:00:00",
  "guid": {"rendered": "https://..."},
  "modified": "2025-11-23T14:00:00",
  "modified_gmt": "2025-11-23T14:00:00",
  "slug": "post-slug",
  "status": "publish",
  "type": "post",
  "link": "https://...",
  "title": {"rendered": "Post Title"},
  "content": {
    "rendered": "<p class=\"wp-block-paragraph\">Content with HTML...</p>...",
    "protected": false
  },
  "excerpt": {
    "rendered": "<p>Excerpt HTML...</p>",
    "protected": false
  },
  "author": 1,
  "featured_media": 0,
  "comment_status": "open",
  "ping_status": "open",
  "sticky": false,
  "template": "",
  "format": "standard",
  "meta": [],
  "categories": [1],
  "tags": [],
  "_links": {
    "self": [...],
    "collection": [...],
    "about": [...],
    "author": [...],
    "replies": [...],
    "version-history": [...],
    "wp:attachment": [...],
    "wp:term": [...],
    "curies": [...]
  }
}
```

**Size:** ~17.94 KB average
**Tokens:** ~4,593 average
**Noise:** 10 `_links`, 11 WP classes, HTML escaping

---

### Dual-Native API Response Structure

```json
{
  "rid": 123,
  "cid": "sha256-abc123...",
  "title": "Post Title",
  "status": "publish",
  "modified": "2025-11-23T14:00:00+00:00",
  "published": "2025-11-23T12:00:00+00:00",
  "author": {
    "id": 1,
    "name": "John Doe",
    "url": "https://..."
  },
  "image": {
    "id": 456,
    "url": "https://...",
    "alt": "Alt text",
    "width": 1200,
    "height": 800
  },
  "categories": [
    {"id": 1, "name": "News", "slug": "news", "url": "https://..."}
  ],
  "tags": [
    {"id": 5, "name": "AI", "slug": "ai", "url": "https://..."}
  ],
  "word_count": 245,
  "core_content_text": "All content as pure text, no HTML",
  "blocks": [
    {"type": "core/heading", "level": 2, "content": "Introduction"},
    {"type": "core/paragraph", "content": "First paragraph"},
    {"type": "core/list", "ordered": false, "items": ["Point 1", "Point 2"]}
  ],
  "links": {
    "human_url": "https://...",
    "api_url": "https://.../wp-json/dual-native/v1/posts/123",
    "md_url": "https://.../wp-json/dual-native/v1/posts/123/md",
    "public_api_url": "https://.../wp-json/dual-native/v1/public/posts/123",
    "public_md_url": "https://.../wp-json/dual-native/v1/public/posts/123/md"
  }
}
```

**Size:** ~8.65 KB average
**Tokens:** ~2,214 average
**Noise:** 0

---

## Safety: Optimistic Locking

### Problem: Lost Updates

**Standard API (No Concurrency Control):**

```
Time    Agent A                 Agent B                 Server State
0s      GET /posts/123          -                       title: "Old Title"
1s      Generates: "Title A"    GET /posts/123          title: "Old Title"
2s      -                       Generates: "Title B"    title: "Old Title"
3s      POST title="Title A"    -                       title: "Title A"
4s      -                       POST title="Title B"    title: "Title B" ❌
```

**Result:** Agent A's edit is **silently lost**. No error, no warning.

---

**Dual-Native API (Optimistic Locking):**

```
Time    Agent A                 Agent B                 Server State
0s      GET /posts/123          -                       cid: "abc123"
        Receives cid: "abc123"
1s      Generates: "Title A"    GET /posts/123          cid: "abc123"
                                Receives cid: "abc123"
2s      -                       Generates: "Title B"    cid: "abc123"
3s      POST If-Match: "abc123" -                       cid: "xyz789"
        title="Title A"                                 title: "Title A"
        SUCCESS ✅
4s      -                       POST If-Match: "abc123" cid: "xyz789"
                                title="Title B"
                                FAIL: 412 Precondition Failed ✅
5s      -                       GET /posts/123          cid: "xyz789"
                                Receives new state      title: "Title A"
6s      -                       Re-generates based on   cid: "xyz789"
                                new content
7s      -                       POST If-Match: "xyz789" cid: "def456"
                                title="Title B (v2)"    title: "Title B (v2)"
                                SUCCESS ✅
```

**Result:** Both edits are **preserved**. Conflicts are **detected and resolved**.

---

## Production Validation

### Zero-Fetch Test

Both APIs were tested with conditional requests (`If-None-Match`):

| API | ETag Support | 304 Success Rate |
|-----|--------------|------------------|
| **Standard API** | ✅ Yes | Not measured (limited use) |
| **Dual-Native API** | ✅ Yes (CID-based) | 100% (10/10 posts) |

**Dual-Native API CID Benefits:**
- **Strong validators:** SHA-256 over canonical JSON
- **Content-based:** CID changes only when content changes
- **100% cache hit:** All tested posts returned 304 on second fetch

---

## Conclusion

The Dual-Native API provides:

✅ **56% smaller payloads** → Faster responses, lower bandwidth
✅ **56% fewer tokens** → $60-70/year savings per 10,000 operations
✅ **12% faster responses** → Better user experience
✅ **Zero noise** → No HTML parsing, no `_links`, no WP classes
✅ **Structured data** → Blocks instead of HTML strings
✅ **Safe writes** → Optimistic locking prevents lost updates
✅ **Better logic** → Semantic structure for AI reasoning

### For WordPress Plugin Developers

If you're building AI-powered tools for WordPress:
- **Title generators**
- **Content analyzers**
- **Automated editors**
- **SEO optimizers**
- **Translation tools**

The Dual-Native API gives you **cleaner data**, **lower costs**, and **safer operations**.

### For WordPress Site Owners

If you're building internal AI workflows:
- **~56% lower LLM costs** for content processing
- **Faster AI responses** (12% speedup)
- **Safer automated edits** (no lost updates)
- **Better AI accuracy** (structured data vs HTML parsing)

---

## Getting Started

**Install the Dual-Native API plugin:**

```bash
# Clone the repository
git clone https://github.com/antunjurkovic-collab/wp-dual-native.git

# Copy to WordPress plugins directory
cp -r wp-dual-native /path/to/wordpress/wp-content/plugins/

# Activate in WordPress Admin → Plugins
```

**Test it yourself:**

```bash
# Fetch your catalog
curl https://your-site.com/wp-json/dual-native/v1/catalog

# Compare Standard vs Dual-Native
curl https://your-site.com/wp-json/wp/v2/posts/123
curl https://your-site.com/wp-json/dual-native/v1/posts/123
```

**Run the benchmark:**

```bash
# Clone the benchmark tool
cd wp-dual-native/tools/validator

# Run comparison
python benchmark_api_vs_dni.py \
  --base https://your-site.com \
  --user YOUR_USERNAME \
  --app-pass "YOUR_APP_PASSWORD" \
  --limit 10 \
  --out results.csv \
  --json summary.json
```

---

## References

- **Dual-Native Pattern Specification:** https://github.com/antunjurkovic-collab/dual-native-pattern
- **WordPress Plugin Repository:** https://github.com/antunjurkovic-collab/wp-dual-native
- **[PERFORMANCE.md](PERFORMANCE.md):** Infrastructure analysis with database queries, memory usage, and profiler data
- **Model Context Protocol (MCP):** https://modelcontextprotocol.io/
- **Production Deployment:** https://galaxybilliard.club

---

**Document Version:** 1.0
**Last Updated:** November 2025
**Benchmark Tools:** [tools/validator/](tools/validator/) - Python scripts to reproduce these results
