# Jak sprawdzić, że Cursor ma dostęp (WordPress + baza + pliki)

## W panelu WordPress

**Ustawienia → Cursor Bridge** → sekcja **„Test połączenia”**.

Wszystkie wiersze muszą być ✓ OK:
- WordPress CMS
- Baza danych (wpdb — liczba tabel i postów)
- Pliki (wp-content)
- MCP REST endpoint
- Application Password

## W Cursorze (MCP)

```
cursor-bridge/verify-connection
```

Oczekiwany wynik:
```json
{
  "ok": true,
  "ready_for_cursor": true,
  "layers": {
    "wordpress": true,
    "database": true,
    "files": true,
    "mcp_rest": true,
    "credentials": true
  }
}
```

## Przez REST (bez pełnego MCP)

Z Application Password (Basic auth):

```
GET https://TWOJA-DOMENA/wp-json/cursor-bridge/v1/verify-connection
GET https://TWOJA-DOMENA/wp-json/cursor-bridge/v1/db-tables
POST https://TWOJA-DOMENA/wp-json/cursor-bridge/v1/db-query
Body: {"sql":"SELECT COUNT(*) AS n FROM nzp_posts"}
```

## Co NIE jest potrzebne

- Zdalny **mariadb** MCP — hosting blokuje port 3306 z zewnątrz
- Baza działa przez **wpdb na serwerze** (jak Better Search Replace)

## Problemy na starcie (historia)

| Problem | Przyczyna | Naprawa w wtyczce |
|---------|-----------|-------------------|
| Brak hasła MCP | Hasło tylko w profilu WP, nie w wtyczce | Pole „Hasło aplikacji” + auto-store |
| Application Passwords wyłączone | Filtr HTTPS/proxy | `should_force_application_passwords()` |
| Biała strona przy zapisie | `check_admin_referer` → wp_die | `wp_verify_nonce` + redirect |
| Zły URL MCP | srv112808 vs kubara.pl | `home_url()` w bundle |
| „Brak bazy” | Próba zdalnego MySQL | `db-query` przez wpdb |
| MCP offline w Cursor | Brak restart / env | Jeden serwer w mcp.json + verify |
