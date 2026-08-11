# Inyfinn Cursor Bridge MCP — instrukcja dla Cursor Agent

## Model dostępu (zawsze przez jeden MCP WordPress)

| Warstwa | Jak | Ability MCP |
|---------|-----|-------------|
| **WordPress** | MCP remote | `cursor-bridge/ping`, `health-check`, `list-plugins`, … |
| **Baza danych** | wpdb na serwerze (jak BSR) | `cursor-bridge/db-query`, `db-list-tables`, `db-info` |
| **Pliki** | SFTP workspace LUB MCP | `read/write-wp-content-file`, `list-wp-content-dir` |
| **SSH** | Opcjonalny terminal | tylko WP-CLI — nie wymagany |

**NIE używaj** zdalnego `mariadb` MCP do produkcyjnej bazy — hosting blokuje port 3306 z zewnątrz.
WordPress i ta wtyczka używają **tej samej bazy** przez PHP na serwerze.

## Polecenie użytkownika

Gdy user pisze: **„uruchom wtyczkę inyfinn-cursor-bridge-mcp”**:

1. Przeczytaj `wp-content/inyfinn-cursor-bridge/cursor-setup.json`
2. Zapisz `.env` i scal **jeden** serwer MCP do `~/.cursor/mcp.json`
3. Test: `cursor-bridge/ping` → `cursor-bridge/db-query` z `SELECT 1`
4. Test plików: `cursor-bridge/list-wp-content-dir` lub workspace SFTP

## Weryfikacja

| Test | Oczekiwany wynik |
|------|------------------|
| `cursor-bridge/ping` | `ok: true` |
| `cursor-bridge/db-query` | `ok: true`, `access_method: wpdb on server` |
| `cursor-bridge/health-check` | `healthy: true`, wiersz „Baza danych” = OK |
| discover-abilities | ≥22 × `cursor-bridge/*` |

## REST fallback (bez pełnego MCP)

- `GET /wp-json/cursor-bridge/v1/ping`
- `GET /wp-json/cursor-bridge/v1/db-info`
- `GET /wp-json/cursor-bridge/v1/db-tables`
- `POST /wp-json/cursor-bridge/v1/db-query` + `{"sql":"SELECT 1"}`

Auth: Application Password (Basic).
