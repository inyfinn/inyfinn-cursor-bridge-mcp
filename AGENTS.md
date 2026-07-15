# Inyfinn Cursor Bridge MCP — instrukcja dla Cursor Agent

## Polecenie użytkownika

Gdy user pisze: **„uruchom wtyczkę inyfinn-cursor-bridge-mcp”** — wykonaj workflow:

### Krok 0 — sprawdź zdrowie (jeśli MCP już działa)

```
cursor-bridge/health-check
```

Jeśli `healthy: false` → `cursor-bridge/repair` z odpowiednim `action` lub `full_bootstrap`.

### Krok A — bez MCP (workspace SFTP)

1. Przeczytaj `wp-content/inyfinn-cursor-bridge/cursor-setup.json` z workspace `public_html`
2. Z pliku weź: `env_file_content`, `mcp_json_content`, `missing_fields`
3. Zapisz `.env` w root workspace (`public_html/.env`) — **gitignore**
4. Scal `mcp_json` do `~/.cursor/mcp.json`
5. Zapytaj usera **tylko** o pola z `missing_fields`
6. Po połączeniu MCP: `cursor-bridge/update-connection-settings`

### Krok B — przez MCP

1. `cursor-bridge/run-auto-setup` (`rotate_password: false`)
2. `cursor-bridge/health-check` → `healthy: true`
3. `cursor-bridge/get-cursor-bundle` (`include_secrets: true`) — tylko jeśli potrzeba sekretów
4. `cursor-bridge/ping` → `bridge_version` zgodny z panelem WP
5. `cursor-bridge/get-site-manifest`
6. Opcjonalnie usuń `cursor-setup.json` po sukcesie

## Weryfikacja — co wpisać żeby wiedzieć że działa

| Test | Oczekiwany wynik |
|------|------------------|
| Panel WP: Ustawienia → Cursor Bridge | Zielony baner, 12× OK |
| `cursor-bridge/ping` | `ok: true` |
| `cursor-bridge/health-check` | `healthy: true`, `failed_count: 0` |
| discover-abilities | ≥19 × `cursor-bridge/*` |

## Naprawa z panelu WP

**Ustawienia → Cursor Bridge** — tabela diagnostyka z przyciskami **Napraw**.

Przez MCP: `cursor-bridge/repair` z `action`: `mu_plugin`, `app_password`, `setup_file`, `permalinks`, `conflicts`, `full_bootstrap`.

## Edycja plików

| Metoda | Kiedy |
|--------|-------|
| Workspace SFTP | Preferowane |
| `write-wp-content-file` | Brak SFTP |
| `read-wp-content-file` | Odczyt max 512 KB |

**Nie czytaj** `inyfinn-cursor-bridge/cursor-setup.json` przez MCP — zablokowane. Użyj `get-cursor-bundle` lub SFTP.

## Dokumentacja

- `README.md` — przegląd
- `docs/INSTALLATION.md` — nowa instalacja
- `docs/TROUBLESHOOTING.md` — problemy
- `docs/ABILITIES.md` — API reference
