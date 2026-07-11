# Inyfinn Cursor Bridge MCP

Fork [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) **0.5.0** + wbudowane abilities i przewodniki dla **Cursor IDE**.

Jedna wtyczka zamiast osobnego MCP Adapter + kompanion. Endpoint REST bez zmian — działa z `@automattic/mcp-wordpress-remote`.

## Wymagania

- WordPress 6.8+ (Abilities API)
- PHP 7.4+
- Application Password dla użytkownika WP
- Cursor + `mcp.json` (patrz `cursor-mcp.example.json`)

## Instalacja

1. Skopiuj folder `inyfinn-cursor-bridge-mcp` do `wp-content/plugins/`
2. Skopiuj `install/mu-plugins/000-inyfinn-cursor-bridge-mcp-loader.php` do `wp-content/mu-plugins/`
3. **Nie** instaluj oficjalnego `mcp-adapter` — ten fork go zastępuje
4. Skonfiguruj `~/.cursor/mcp.json` i `.env` (patrz `AGENTS.md`)

## MCP w Cursorze

```json
"seohost-wordpress": {
  "command": "npx",
  "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
  "env": {
    "WP_API_URL": "https://TWOJA-DOMENA/wp-json/mcp/mcp-adapter-default-server",
    "WP_API_USERNAME": "${env:WP_MCP_USERNAME}",
    "WP_API_PASSWORD": "${env:WP_MCP_APP_PASSWORD}"
  }
}
```

## Abilities (`cursor-bridge/*`)

| Ability | Opis |
|---------|------|
| `ping` | Health check |
| `get-site-manifest` | Kontekst strony, ścieżki, endpoint |
| `get-setup-guide` | Kroki pod hosting (seohost/generic/local) |
| `configure-profile` | Ustaw profil hostingu |
| `read-wp-content-file` | Odczyt pliku w `wp-content` (max 512 KB) |
| `list-wp-content-dir` | Lista katalogów w `wp-content` |
| `list-plugins` / `list-themes` / `list-posts` | Audyt |
| `wc-list-products` / `wc-list-orders` | WooCommerce |
| `flush-caches` | Cache po deployu |

## Trzy warstwy dostępu

| Warstwa | Gdzie |
|---------|--------|
| Pliki (pełna edycja) | Workspace Cursor (SFTP) |
| WordPress / DB | MCP → ta wtyczka |
| SSH / WP-CLI | Terminal + `.env` na PC dewelopera |

Wtyczka **nie czyta** `.env` — tylko zwraca instrukcje (`get-setup-guide`).

## Licencja

GPL-2.0-or-later (fork MCP Adapter + kod Inyfinn).

## Autor

[Inyfinn](https://inyfinn.pl)
