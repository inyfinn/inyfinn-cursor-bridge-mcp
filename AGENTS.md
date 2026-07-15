# Inyfinn Cursor Bridge MCP — instrukcja dla Cursor Agent

## Polecenie użytkownika

Gdy user pisze: **„uruchom wtyczkę inyfinn-cursor-bridge-mcp”** — wykonaj ten workflow:

### Krok A — bez MCP (workspace SFTP)

1. Przeczytaj `wp-content/inyfinn-cursor-bridge/cursor-setup.json` z workspace `public_html`
2. Z pliku weź: `env_file_content`, `mcp_json_content`, `app_password`, `missing_fields`
3. Zapisz `.env` w root workspace (`public_html/.env`) — **gitignore**
4. Scal `mcp_json` do `~/.cursor/mcp.json` (serwer z `mcp_server_name`)
5. Zapytaj usera **tylko** o pola z `missing_fields` (np. `WORKSPACE_PUBLIC_HTML`, `SSH_HOST`)
6. Wywołaj `cursor-bridge/update-connection-settings` z uzupełnionymi polami (gdy MCP już działa)

### Krok B — przez MCP (po pierwszym połączeniu)

1. `cursor-bridge/run-auto-setup` (rotate_password: false)
2. `cursor-bridge/get-cursor-bundle` (include_secrets: true)
3. `cursor-bridge/ping`
4. `cursor-bridge/get-site-manifest`
5. Usuń `cursor-setup.json` po sukcesie (opcjonalnie)

## Auto-setup (v1.1.0)

Wtyczka **sama**:
- instaluje mu-plugin loader
- tworzy Application Password
- generuje `cursor-setup.json` z DB z wp-config
- dezaktywuje konfliktowy `mcp-adapter`

User **nie** tworzy Application Password ręcznie.

## Edycja plików

| Metoda | Kiedy |
|--------|-------|
| Workspace SFTP | Preferowane dla dużych plików |
| `write-wp-content-file` | Gdy brak SFTP, ścieżka w wp-content |
| `read-wp-content-file` | Odczyt max 512 KB |

To nie jest protokół FTP — to zapis na serwerze przez PHP (ten sam efekt co SFTP).

## .env — auto-wypełniane

Z wp-config / WordPress:
- `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`, `DB_TABLE_PREFIX`
- `WP_SITE_URL`, `WP_MCP_API_URL`, `WP_MCP_USERNAME`, `WP_MCP_APP_PASSWORD`

User / agent uzupełnia tylko:
- `SSH_*`, `WORKSPACE_PUBLIC_HTML`, `FTP_*` (jeśli potrzebne)

## Panel WP

**Ustawienia → Cursor Bridge** — pola połączenia + przycisk „Uruchom auto-setup”.

## Bezpieczeństwo

- `cursor-setup.json` chroniony `.htaccess` w `wp-content/inyfinn-cursor-bridge/`
- Hasła w setup file — usuń po konfiguracji Cursora
- `get-cursor-bundle` tylko dla `manage_options`
