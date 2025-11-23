# WordPress MCP Server (Dual‑Native)

This MCP server exposes your Dual‑Native WordPress endpoints (MR JSON/Markdown, catalog, safe write) as Claude‑accessible tools.

## Run
- From this folder:
```
npm install
npm start
```
- Env (set via shell or .env): `WP_URL`, `WP_USER`, `WP_PASSWORD` (Application Password).

## Tools
- read_mr / read_md / list_posts
- append_block / append_blocks / insert_at_index / insert_blocks_at_index (all accept `if_match`; auto‑send latest cached ETag unless `force=true`)
- apply_excerpt, set_title, set_slug, set_status
- set_tags, set_categories (IDs), set_tags_by_names, set_categories_by_names
- create_post
- self_test (validates ETag=CID, 304s, Content‑Digest parity)

## Data Science Demo
- Start this server (above).
- Start the official Python MCP (for code execution) using uvx:
```
uvx mcp-server-python --stdio
```
- Configure Claude Desktop to include both servers. Then ask Claude to:
  - Use `list_posts` / `read_mr` to fetch content
  - Use the Python tools to analyze the catalog and plot stats

This keeps the project lean and aligns with upstream MCP servers, rather than bundling multiple servers into one repo.
