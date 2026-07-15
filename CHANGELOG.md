# Changelog

## 1.1.0 — 2026-07-15

### Added
- **Auto-setup przy aktywacji**: mu-plugin loader, Application Password, `cursor-setup.json`
- **Credentials**: DB z wp-config → bundle `.env` (MYSQL_* + DB_*)
- **Admin**: Ustawienia → Cursor Bridge (SSH, FTP, workspace, przycisk bootstrap)
- **Abilities**: `run-auto-setup`, `get-cursor-bundle`, `update-connection-settings`, `write-wp-content-file`
- Szyfrowane przechowywanie FTP pass i app password (AUTH_KEY)
- Workflow Cursor: „uruchom wtyczkę inyfinn-cursor-bridge-mcp” (AGENTS.md)

### Changed
- Hosting profiles: kroki bez ręcznego Application Password
- Site manifest: sekcja `auto_setup` ze ścieżką setup file

## 1.0.0 — 2026-06-15

### Added
- Fork MCP Adapter 0.5.0 jako jedna wtyczka `inyfinn-cursor-bridge-mcp`
- Abilities `cursor-bridge/*` (manifest, setup guide, hosting profiles)
- Odczyt plików: `read-wp-content-file`, `list-wp-content-dir`
- WooCommerce: `wc-list-products`, `wc-list-orders`
- Dokumentacja `AGENTS.md` dla Cursor Agent

### Notes
- Endpoint REST: `/wp-json/mcp/mcp-adapter-default-server`
- Zastępuje oficjalny `mcp-adapter` — nie instaluj obu naraz
