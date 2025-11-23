# WordPress MCP Server (Dual-Native)

This MCP server exposes your WordPress site as a set of Agentic Tools for Claude Desktop. It leverages the Dual-Native API to provide safe, structured reading and writing capabilities.

## Quick Start

### 1. Installation

Navigate to this folder and install dependencies:

```bash
cd tools/mcp-server
npm install
```

### 2. Configure Claude Desktop

Add the following to your `claude_desktop_config.json`.

- **Mac:** `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows:** `%APPDATA%\Claude\claude_desktop_config.json`

**Mac/Linux:**
```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "tsx", "/absolute/path/to/your/wp-dual-native/tools/mcp-server/src/index.ts"],
      "env": {
        "WP_URL": "https://your-site.com",
        "WP_USER": "your-username",
        "WP_PASSWORD": "your-application-password"
      }
    }
  }
}
```

**Windows:**
```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "tsx", "C:\\Users\\YourName\\Desktop\\wp-dual-native\\tools\\mcp-server\\src\\index.ts"],
      "env": {
        "WP_URL": "https://your-site.com",
        "WP_USER": "your-username",
        "WP_PASSWORD": "your-application-password"
      }
    }
  }
}
```

## Available Tools

### Reading (Machine Representation)

- **`list_posts`**: Get a catalog of recent posts (supports status/since filters).
- **`read_mr`**: Fetch the Machine Representation (JSON) of a post. Optimized for AI context (saves ~60% tokens vs HTML).
- **`read_md`**: Fetch the raw Markdown content.

### Writing (Safe Mutations)

All write tools support **Optimistic Locking**. If you don't provide an `if_match` ETag, the server automatically uses the latest cached version (unless `force=true`).

- **`append_block`** / **`append_blocks`**: Add content to the end of a post.
- **`insert_at_index`** / **`insert_blocks_at_index`**: Inject content at a specific position.
- **`create_post`**: Create a new draft.

### Metadata & Management

- **`set_title`**, **`set_slug`**, **`set_status`**, **`apply_excerpt`**
- **`set_tags`**, **`set_categories`** (by ID)
- **`set_tags_by_names`**, **`set_categories_by_names`** (by Name)

### Diagnostics

- **`self_test`**: Validates the API connection, ETag parity, and Content-Digest integrity.

## Data Science Demo (Python Integration)

To enable **Code Execution** (allowing Claude to analyze your site data with Python), run the official Python MCP server alongside this one.

### 1. Add the Python Server to your Config:

```json
"python": {
  "command": "uvx",
  "args": ["mcp-server-python"]
}
```

### 2. Example Prompt:

*"Use list_posts to fetch my site content. Then, use the Python tool to analyze the distribution of post titles and plot a graph of publishing frequency."*
