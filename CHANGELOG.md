# Changelog

## 1.2.0 — 2026-07-15

### Added
- **Panel diagnostyczny**: Ustawienia → Cursor Bridge — 12 testów, baner OK/błąd, przyciski **Napraw** per wiersz
- **Health class** + abilities `cursor-bridge/health-check`, `cursor-bridge/repair`
- **Dokumentacja**: `docs/INSTALLATION.md`, `docs/TROUBLESHOOTING.md`, `docs/ABILITIES.md`
- README przepisany — instalacja na nowym WP, checklist weryfikacji
- Link „Diagnostyka” na liście wtyczek
- Instrukcja w panelu: co wpisać w Cursorze żeby sprawdzić działanie

### Changed
- AGENTS.md — health-check, repair, weryfikacja
- Smoke test rozszerzony o blokadę odczytu setup file

## 1.1.2 — 2026-07-15

### Fixed
- **Bezpieczeństwo**: `read-wp-content-file` blokuje odczyt `cursor-setup.json` (sekrety tylko przez `get-cursor-bundle`)
- **Aktywacja**: `ensure_plugin_active()` — wtyczka w `active_plugins`, nie tylko mu-loader
- **configure-profile**: merge z istniejącym profilem zamiast nadpisywania całej opcji

## 1.1.1 — 2026-07-15

### Fixed
- `full_bootstrap()` zwraca prawdziwy `ok` (nie zawsze `true`) + tablica `errors`
- `ensure_application_password()` rotuje hasło gdy decrypt z opcji się nie uda
- `build_cursor_bundle(false)` bez efektów ubocznych (nie tworzy hasła przy odczycie admina)
- Podwójny bootstrap przy aktywacji — transient pomija drugi przebieg na `plugins_loaded`
- `deactivate_conflicting_plugins()` odkładane na `shutdown` podczas aktywacji
- `cursor-setup.json` — `chmod 0600`, `LOCK_EX` przy zapisie
- Ścieżki plików: `File_Reader::sanitize_relative_path()` zamiast `sanitize_text_field()`
- `get-cursor-bundle` — meta bez `readonly: true` (zwraca sekrety)
- Admin UI pokazuje szczegóły błędów bootstrapu
- `maybe_self_heal()` — throttle 1×/godzinę
- Stały `mcp_user_id` w opcji (nie losowy admin przy każdym wywołaniu)

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
