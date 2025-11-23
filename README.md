# Dual-Native API (WordPress Plugin)

Exposes a clean Machine Representation (MR) and a small catalog for agentic AI tasks inside WordPress. Ideal for block-aware summarization, extraction, and safe block insertion without scraping editor HTML.

## Features
- GET `/wp-json/dual-native/v1/posts/{id}` — JSON MR
  - rid, cid (strong validator), title, status, modified/published, author, featured image
  - categories/tags (sorted by ID), blocks[], core_content_text, word_count
  - links: human_url, api_url, md_url, public_api_url, public_md_url
  - ETag (=cid), Last-Modified, and Content-Digest (RFC 9530) on 200 responses
- GET `/wp-json/dual-native/v1/catalog?since=ISO&status=...&types=...` — small index for zero-fetch
- GET `/wp-json/dual-native/v1/posts/{id}/md` — Markdown MR (text/markdown; ETag over final bytes)
- POST `/wp-json/dual-native/v1/posts/{id}/blocks` — insert one or more blocks
  - Body: `{ insert: "append"|"prepend"|"index", index?: number, block?: {...} or blocks?: [{...}] }`
  - Supported: paragraph, heading(level), list(ordered,items[]), image(url,altText), code, quote
  - Safe-write (optional): send `If-Match: "<cid>"` to prevent stale writes (412 if mismatched)
  - Write responses include the new `ETag` header (current `cid`) so clients can chain edits without an extra read
- GET `/wp-json/dual-native/v1/posts/{id}/ai/suggest` — heuristic (or external) summary + tag suggestions
- Editor sidebar “Dual‑Native AI”: insert at cursor (H2), preview MR, suggest & apply summary, copy MR JSON, open Markdown MR

## Install
1. Copy `wp-dual-native/` into `wp-content/plugins/`
2. Activate in WP Admin → Plugins
3. Open the block editor → find the “Dual‑Native AI” panel (sidebar)

## Security & Permissions
- Authenticated MR/MD and catalog require login (users who can `edit_post`) and a REST nonce
- Write endpoint requires `edit_post` + nonce; CID invalidates on `save_post`
- Public read (optional):
  - GET `/wp-json/dual-native/v1/public/posts/{id}` (MR JSON)
  - GET `/wp-json/dual-native/v1/public/posts/{id}/md` (Markdown)
  - Gate with `dni_can_read_public_mr` (default: published only)

## REST Examples
- Conditional MR:
```
curl -i https://example.com/wp-json/dual-native/v1/posts/123
curl -i -H 'If-None-Match: "sha256-..."' https://example.com/wp-json/dual-native/v1/posts/123  # 304
```
- Safe write with index + If-Match:
```
curl -i -X POST -H 'Content-Type: application/json' -H 'X-WP-Nonce: <nonce>' -H 'If-Match: "sha256-..."' \
  -d '{"insert":"index","index":2,"block":{"type":"core/heading","level":2,"content":"Key Takeaways"}}' \
  https://example.com/wp-json/dual-native/v1/posts/123/blocks
```

## Determinism & Integrity
- CID = `sha256-<hex>` over canonical MR (sorted keys), excluding only `cid` by default
- Taxonomies (categories/tags) are sorted by ID before hashing to ensure deterministic CIDs regardless of DB order
- 200 responses include `Content-Digest: sha-256=:<base64>:` (standard Base64 of SHA‑256 bytes) and `Last-Modified`
- Markdown responses are served with the exact bytes the plugin hashes and emits at serve time to guarantee digest parity with on‑wire content
- To exclude volatile fields (e.g., dates) from CID:
```
add_filter('dni_cid_exclude_keys', function(array $keys){
  // Also exclude 'links' (environment/permalink specific) to keep CIDs portable
  return array_merge($keys, ['modified','published','status','links']);
}, 10, 1);
```

## Filters & Extensibility
- `dni_mr($mr, $post_id)`: mutate/enrich MR
- `dni_blocks($blocks, $post_id, $post)` and `dni_map_block($out, $raw_block)`: extend block mapping
- `dni_render_block_html($html, $block)`: render custom blocks for write API
- `dni_markdown($md, $mr, $req)`: post‑process Markdown
- `dni_catalog_args($args, $req)`: include CPTs/status
- Permissions: `dni_can_read_mr($allow, $id, $req)`, `dni_can_read_public_mr($allow, $id, $req)`
- AI: `dni_ai_suggest($suggestion, $mr, $req)`

##  Agentic Integration (Claude Desktop / MCP)

This plugin includes a production-ready **Model Context Protocol (MCP)** server that allows AI Agents (like Claude Desktop) to read, write, and analyze your WordPress site safely.

### Quick Start

1.  **Configure:** Add this to your Claude Desktop config (`claude_desktop_config.json`):

    ```json
    {
      "mcpServers": {
        "wordpress": {
          "command": "npm",
          "args": ["start"],
          "cwd": "/absolute/path/to/wp-dual-native/tools/mcp-server",
          "env": {
            "WP_URL": "https://your-site.local",
            "WP_USER": "admin",
            "WP_PASSWORD": "your-application-password"
          }
        }
      }
    }
    ```

2.  **Install:**
    ```bash
    cd tools/mcp-server
    npm install
    ```

3.  **Run:** Restart Claude Desktop. You can now ask Claude to:
    *   *"Read the latest post and fix any formatting errors."* (Self-Healing)
    *   *"Analyze the publishing frequency of my last 50 posts."* (Data Science)

For full documentation on the MCP tools and Python integration, see [tools/mcp-server/README.md](tools/mcp-server/README.md).

## FAQ

**Q: Does this work with the Classic Editor?**

**A:** Yes, but with reduced granularity. Classic posts appear in the Machine Representation (MR) as a single `core/freeform` block.

However, the Safe Write API still works. An Agent can append new structured blocks to a Classic post, effectively creating a hybrid post that preserves the original HTML while adding modern, AI-generated blocks.
