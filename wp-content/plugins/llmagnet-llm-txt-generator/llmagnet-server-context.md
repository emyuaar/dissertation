# LLMagnet Server — Context for New Session

## The Project
LLMagnet is a WordPress plugin that generates `llms.txt`, tracks AI bots, and calculates an AI Visibility Score.
- Plugin repo: `navarroido/llmagnet`
- Server repo (new): `navarroido/llmagnet-server`

## Phase 1 — What Already Exists (WordPress Plugin)
`class-mcp.php` is a native MCP server built into the plugin. It exposes tools via `POST /wp-json/llmagnet/mcp/v1` using WordPress Application Password auth.

Available tools: `get_site_info`, `get_visibility_score`, `get_bot_traffic`, `get_top_pages`, `get_bot_stats_table`, `get_recommendations`

Problem: every Claude Desktop user must manually configure MCP with a `url` + `Authorization: Basic` header for each site. Doesn't scale for users with multiple sites.

## Phase 2 — What to Build (`llmagnet-server`)
A Node.js relay server that does:

### 1. User Accounts
- Register / login / API keys for LLMagnet users
- Each user can add multiple WordPress sites

### 2. Multi-Site Management
- User adds a site: URL + WordPress Application Password
- Server stores credentials (encrypted) and manages connection to the plugin's MCP endpoint

### 3. Unified MCP Endpoint
- Claude Desktop connects to one server (llmagnet-server) with one API key
- Server forwards requests to the relevant WordPress sites
- Tool calls receive `site_id` as a parameter → relay → WordPress MCP endpoint

### 4. Architecture
```
Claude Desktop
     |  MCP (HTTP or SSE)
     v
llmagnet-server (Node.js)
     |  POST /wp-json/llmagnet/mcp/v1
     |  Authorization: Basic ...
     v
WordPress Site 1 / Site 2 / Site N
```

## Stack
- Runtime: Node.js + TypeScript
- Framework: Fastify
- DB: SQLite for dev, PostgreSQL for production
- Auth: JWT + API Keys
- MCP: `@modelcontextprotocol/sdk` to build the MCP server for Claude Desktop
- Encryption: Node.js `crypto` (AES-256) for storing WordPress credentials

## What to Build in This Session
1. Scaffold: `package.json`, TypeScript config, Fastify setup
2. DB schema: users, sites, api_keys tables
3. Auth routes: register, login, create API key
4. Sites routes: add site, list sites, test connection
5. MCP Server: receives MCP requests from Claude Desktop, identifies user by API key, forwards to requested site
6. Relay logic: calls WordPress MCP endpoint, returns response

## Important Notes
- The WordPress plugin (PHP) does not change in this phase — the server talks to the existing endpoint
- WordPress credentials are stored encrypted in the server DB
- MCP protocol version: `2024-11-05`
- Every MCP tool from the plugin gets an extra `site_id` parameter in the relay server
