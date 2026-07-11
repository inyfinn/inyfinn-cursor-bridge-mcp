# Changelog

## 1.0.0 — 2026-06-15

### Added
- Fork MCP Adapter 0.5.0 jako jedna wtyczka `inyfinn-cursor-bridge-mcp`
- Abilities `cursor-bridge/*` (manifest, setup guide, hosting profiles)
- Odczyt plików: `read-wp-content-file`, `list-wp-content-dir`
- WooCommerce: `wc-list-products`, `wc-list-orders`
- Auto-loader mu-plugin w `install/mu-plugins/`
- Dokumentacja `AGENTS.md` dla Cursor Agent

### Notes
- Endpoint REST: `/wp-json/mcp/mcp-adapter-default-server` (kompatybilność wsteczna)
- Zastępuje oficjalny `mcp-adapter` — nie instaluj obu naraz
