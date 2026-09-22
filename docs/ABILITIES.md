# Reference — MCP Abilities

Endpoint REST MCP: `/wp-json/mcp/mcp-adapter-default-server`

Wszystkie abilities mają prefix `cursor-bridge/`.

Agent dostaje skrót tej listy automatycznie w polu `instructions` odpowiedzi MCP `initialize` (fakty o stronie + kolejność pracy). Pełna wersja: `cursor-bridge/get-agent-playbook`.

## Edycja treści i Elementora (1.7.0)

| Ability | Wejście | Do czego |
|---------|---------|----------|
| `get-agent-playbook` | — | Fakty o stronie, workflowy, zasady. Czytać najpierw. |
| `find-content` | `text`, `limit` | Gdzie leży widoczny tekst: element Elementora (`post_id`, `element_id`, `setting`), post_content, meta, opcje, pliki motywu potomnego / mu-plugins. |
| `elementor-outline` | `post_id` | Płaska lista elementów strony: id, typ, głębokość, rodzic, podgląd tekstu, `hidden_on`. |
| `elementor-get-element` | `post_id`, `element_id` | Pełne ustawienia jednego elementu. |
| `elementor-patch-element` | `post_id`, `element_id`, `settings`, `unset`, `dry_run` | Zmiana ustawień jednego elementu. Każdy klucz zastępuje całą wartość. Odmowa dla rewizji, kopia, weryfikacja, cache. |
| `elementor-list-backups` | `post_id` | 5 ostatnich kopii sprzed zapisów mostu. |
| `elementor-restore-backup` | `post_id`, `backup_key` | Cofnięcie zapisu (bieżący stan też idzie do kopii). |
| `purge-caches` | — | Wszystkie warstwy cache strony (bez CDN / hostingu). |

Przykład — ukrycie sekcji na telefonie zamiast kasowania:

```json
{ "ability_name": "cursor-bridge/elementor-patch-element",
  "parameters": { "post_id": 5937, "element_id": "3f2a9c1", "settings": { "hide_mobile": "hidden-mobile" }, "dry_run": true } }
```

`db-query`: `{prefix}` zamienia się na prefiks tabel (`SELECT ID FROM {prefix}posts`). Błąd SQL = `ok:false` z komunikatem.

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

**Akcje:** `mu_plugin` (usuwa leftover loader 1.5.x), `app_password`, `setup_file`, `setup_directory`, `permalinks`, `conflicts`, `profile`, `full_bootstrap`. `activate_plugin` tylko raportuje — włączenie = przycisk **Włącz** w WP Admin.

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
