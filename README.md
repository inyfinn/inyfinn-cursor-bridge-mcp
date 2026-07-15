# Inyfinn Cursor Bridge MCP

**Repo:** https://github.com/inyfinn/inyfinn-cursor-bridge-mcp

Fork [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) **0.5.0** + auto-setup dla **Cursor IDE**.

## Co robi v1.1.0 (zero ręcznej konfiguracji)

Po **aktywacji** wtyczki (WP Admin → Wtyczki → Aktywuj):

1. Kopiuje **mu-plugin loader** do `wp-content/mu-plugins/` (automatycznie)
2. Tworzy **Application Password** dla administratora (automatycznie)
3. Zapisuje **`wp-content/inyfinn-cursor-bridge/cursor-setup.json`** — Cursor czyta ten plik z workspace SFTP
4. Wypełnia **DB_*** z `wp-config` w bundle `.env`
5. Udostępnia **MCP abilities** + zapis plików w `wp-content` (`write-wp-content-file`)

W Cursorze wystarczy napisać:

> **uruchom wtyczkę inyfinn-cursor-bridge-mcp**

Agent: czyta `cursor-setup.json` → zapisuje `.env` i `mcp.json` → pyta tylko o brakujące pola (SSH, ścieżka workspace).

## Wymagania

- WordPress **6.8+** (Abilities API)
- PHP 7.4+
- Cursor + Node (npx) dla `@automattic/mcp-wordpress-remote`

## Instalacja

1. Skopiuj folder do `wp-content/plugins/inyfinn-cursor-bridge-mcp/`
2. **Aktywuj** w panelu WP — reszta dzieje się sama
3. Otwórz `public_html` jako workspace w Cursorze (SFTP)
4. W Cursorze: „uruchom wtyczkę inyfinn-cursor-bridge-mcp”

Opcjonalnie: **Ustawienia → Cursor Bridge** — pola SSH/FTP/workspace.

## Trzy warstwy

| Warstwa | Mechanizm |
|---------|-----------|
| Pliki (SFTP) | Workspace Cursor na `public_html` |
| Pliki (zdalnie) | MCP `write-wp-content-file` / `read-wp-content-file` |
| WordPress / DB | MCP → ta wtyczka |
| SSH / WP-CLI | Terminal + `.env` (wartości z setup bundle) |

## Abilities (`cursor-bridge/*`)

| Ability | Opis |
|---------|------|
| `run-auto-setup` | Pełny bootstrap (hasło, mu-plugin, setup.json) |
| `get-cursor-bundle` | mcp.json + .env + sekrety dla admina |
| `update-connection-settings` | SSH/FTP/workspace |
| `write-wp-content-file` | Zapis pliku w wp-content |
| `get-site-manifest` | Kontekst strony, wtyczki, ścieżki |
| `list-plugins` / `list-themes` / `wc-*` | Audyt |

## Licencja

GPL-2.0-or-later
