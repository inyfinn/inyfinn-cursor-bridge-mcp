# Reference — MCP Abilities

Endpoint REST MCP: `/wp-json/mcp/mcp-adapter-default-server`

Wszystkie abilities mają prefix `cursor-bridge/`.

---

## Diagnostyka i setup

### `cursor-bridge/ping`

Health check. Uprawnienie: `read`.

**Odpowiedź:**
```json
{
  "ok": true,
  "bridge_version": "1.2.0",
  "mcp_adapter": "1.2.0",
  "public_abilities": 19
}
```

### `cursor-bridge/health-check`

12 testów instalacji. Uprawnienie: `manage_options`.

**Odpowiedź:**
```json
{
  "overall": "ok",
  "healthy": true,
  "failed_count": 0,
  "checks": [ { "id": "plugin_active", "status": "ok", ... } ]
}
```

### `cursor-bridge/repair`

Naprawa jednego komponentu. Uprawnienie: `manage_options`.

**Input:**
```json
{
  "action": "mu_plugin",
  "rotate_password": false
}
```

**Akcje:** `activate_plugin`, `mu_plugin`, `app_password`, `setup_file`, `setup_directory`, `permalinks`, `conflicts`, `profile`, `full_bootstrap`

### `cursor-bridge/run-auto-setup`

Pełny bootstrap. Input: `{ "rotate_password": false }`.

### `cursor-bridge/get-cursor-bundle`

Bundle `.env` + `mcp.json`. Input: `{ "include_secrets": true }`.

### `cursor-bridge/update-connection-settings`

SSH, FTP, workspace. Regeneruje setup file.

---

## Kontekst strony

| Ability | Opis |
|---------|------|
| `get-site-manifest` | Pełny manifest bez sekretów |
| `get-setup-guide` | Kroki per hosting (seohost/generic/local) |
| `configure-profile` | `hosting_provider`, `notes` |
| `get-site-info` | Tytuł, URL, język |

---

## WordPress

| Ability | Uprawnienie |
|---------|-------------|
| `list-plugins` | `activate_plugins` |
| `list-themes` | `switch_themes` |
| `list-posts` | `edit_posts` |
| `flush-caches` | `manage_options` |

---

## Pliki (wp-content)

| Ability | Limit |
|---------|-------|
| `read-wp-content-file` | 512 KB, bez `..`, **bez cursor-setup.json** |
| `write-wp-content-file` | bez `..`, **bez nadpisania setup file** |
| `list-wp-content-dir` | depth 1–4 |

Ścieżka względem `wp-content`, np. `themes/child/style.css`.

---

## WooCommerce

| Ability | Uprawnienie |
|---------|-------------|
| `wc-list-products` | `edit_products` lub `manage_woocommerce` |
| `wc-list-orders` | `manage_woocommerce` |

---

## Uprawnienia MCP

Połączenie przez Application Password użytkownika WP. Abilities respektują `permission_callback` WordPressa.

Sekrety (DB password, app password) tylko w:
- `get-cursor-bundle` (include_secrets)
- `run-auto-setup` (bundle w odpowiedzi)
- `cursor-setup.json` (SFTP, nie MCP read)

---

## Discover

W Cursorze po połączeniu MCP:

```text
discover abilities
```

Oczekiwane: 19 abilities `cursor-bridge/*` (v1.2.0).
