# Inyfinn Cursor Bridge MCP — instrukcja dla Cursor Agent

## Jak user sprawdza, że masz dostęp do bazy?

1. **Panel WP:** Ustawienia → Cursor Bridge → „Test połączenia” — wszystkie ✓
2. **MCP:** `cursor-bridge/verify-connection` → `ok:true`, `layers.database:true`
3. **REST:** `GET /wp-json/cursor-bridge/v1/verify-connection` (Basic auth)

Baza = `cursor-bridge/db-query` (wpdb na serwerze). **NIE** zdalny mariadb MCP.

## Co było potrzebne na start projektu

1. Wtyczka aktywna na serwerze
2. Application Password dla usera MCP (np. inyfinn)
3. Jeden serwer MCP w `~/.cursor/mcp.json` → `kubara.pl` endpoint
4. Workspace SFTP `public_html` (opcjonalnie — pliki też przez MCP)
5. **NIE** zdalny MySQL — SEOHost blokuje port 3306 z zewnątrz

## Dlaczego były problemy na początku

| Problem | Fix w wtyczce |
|---------|----------------|
| Zły URL (srv112808 vs kubara.pl) | Bundle używa `home_url()` |
| Brak hasła w wtyczce | Pole w panelu + `store_application_password` |
| App Passwords „wyłączone” | Force enable na HTTPS/proxy |
| wp_die przy zapisie | Nonce bez wp_die |
| „Brak bazy” | db-query przez wpdb, nie remote MySQL |

## Polecenie użytkownika

**„uruchom wtyczkę inyfinn-cursor-bridge-mcp”** → setup.json → mcp.json → test `verify-connection`

## Weryfikacja

| Test | Oczekiwany wynik |
|------|------------------|
| `cursor-bridge/verify-connection` | `ok: true`, wszystkie `layers` = true |
| `cursor-bridge/db-query` | `access_method: wpdb on server` |
| Panel: Test połączenia | 5× ✓ OK |
