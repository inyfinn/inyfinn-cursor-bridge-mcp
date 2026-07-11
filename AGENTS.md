# Inyfinn Cursor Bridge MCP — instrukcja dla Cursor Agent

**Jedna wtyczka WordPress** = fork MCP Adapter + abilities + przewodniki hostingu.  
Nie instaluj osobnego „MCP Adapter” ani „Inyfinn Cursor Bridge”.

## Połączenie MCP (Cursor → WordPress)

W `~/.cursor/mcp.json` używaj **`seohost-wordpress`** (lub nazwy z profilu):

```json
"seohost-wordpress": {
  "command": "npx",
  "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
  "env": {
    "WP_API_URL": "https://TWOJA-DOMENA/wp-json/mcp/mcp-adapter-default-server",
    "WP_API_USERNAME": "login",
    "WP_API_PASSWORD": "${env:SEOHOST_ETA_WP_APP_PASS}"
  }
}
```

Endpoint REST **zostaje ten sam** (`mcp-adapter-default-server`) — zmiana tylko wtyczki po stronie WP.

## Trzy warstwy (wszystkie potrzebne)

| Warstwa | Gdzie | Do czego |
|---------|--------|----------|
| **Pliki** | Workspace Cursor (SFTP/dysk `public_html`) | PHP, CSS, JS, motywy, mu-plugins |
| **MCP** | `seohost-wordpress` → ta wtyczka | WordPress, WooCommerce, manifest, setup |
| **SSH / WP-CLI** | Terminal Cursor + `.env` | Batch, cache, `wp search-replace` |

## Co NIE jest tą wtyczką

- **`mariadb`** — lokalny MySQL (Local WP). Nie produkcja.
- **`wordpress-local`** — osobny pakiet dla Local.
- **`filesystem`** — pliki na dysku dewelopera.

## Pierwsze kroki agenta

1. `cursor-bridge/get-setup-guide`
2. `cursor-bridge/get-site-manifest`
3. `.env` w `public_html` na maszynie z Cursorem (wtyczka WP **nie czyta** `.env`)
4. `cursor-bridge/configure-profile` → `hosting_provider: "seohost"`
5. `cursor-bridge/ping` → `public_abilities` > 0

## .env (gitignored, na PC z Cursorem)

```env
WP_SITE_URL=https://twoja-domena.pl
WP_MCP_API_URL=https://twoja-domena.pl/wp-json/mcp/mcp-adapter-default-server
WP_MCP_USERNAME=login
WP_MCP_APP_PASSWORD=xxxx xxxx xxxx
SSH_HOST=
SSH_USER=
SSH_PORT=22
SSH_REMOTE_PUBLIC_HTML=
WORKSPACE_PUBLIC_HTML=
WP_CLI_COMMAND=php ~/wp-cli.phar
```

## Abilities (`cursor-bridge/*`)

| Ability | Użycie |
|---------|--------|
| `ping` | Health check |
| `get-site-manifest` | Kontekst strony |
| `get-setup-guide` | Kroki pod hosting |
| `configure-profile` | `seohost` / `generic` / `local` |
| `list-plugins` / `list-themes` / `list-posts` | Audyt |
| `wc-list-products` / `wc-list-orders` | WooCommerce |
| `read-wp-content-file` / `list-wp-content-dir` | Odczyt kodu w wp-content |

Narzędzia MCP: `mcp-adapter-discover-abilities`, `mcp-adapter-execute-ability`.

## Instalacja na nowej stronie

1. Skopiuj folder `wp-content/plugins/inyfinn-cursor-bridge-mcp/`
2. Dodaj `wp-content/mu-plugins/000-inyfinn-cursor-bridge-mcp-loader.php`
3. **Wyłącz** oficjalny `mcp-adapter` jeśli był aktywny
4. Application Password + `mcp.json` jak wyżej

## Bezpieczeństwo

- Hasła tylko w `.env` / zmiennych systemowych
- Backup przed migracją DB
- Nie wkładaj SSH ani shell exec do wtyczki PHP
