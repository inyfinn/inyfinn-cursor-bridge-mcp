# Rozwiązywanie problemów

## Złota zasada

1. **Ustawienia → Cursor Bridge** — przeczytaj tabelę diagnostyki
2. Kliknij **Napraw** przy czerwonym wierszu
3. Jeśli nadal źle → **Pełny auto-setup**
4. W Cursorze: `cursor-bridge/health-check`

---

## Diagnostyka w panelu WP

| Test | Znaczenie | Naprawa |
|------|-----------|---------|
| WordPress 6.8+ | Abilities API | Zaktualizuj WordPress |
| Wtyczka aktywna | W `active_plugins` | Przycisk Napraw → `activate_plugin` |
| MU-plugin loader | Plik w mu-plugins | Napraw → kopiuje loader |
| Application Passwords | API WP dostępne | Włącz HTTPS; sprawdź filtry `wp_is_application_passwords_available` |
| Application Password MCP | Hasło „Cursor MCP” | Napraw → tworzy hasło |
| cursor-setup.json | Plik dla Cursora | Napraw → regeneruje |
| Katalog setup (.htaccess) | Ochrona HTTP | Napraw → tworzy .htaccess |
| Permalinki | Pretty URLs | Ustawienia → Bezpośrednie odnośniki → zapisz |
| MCP Adapter | Fork załadowany | Pełny auto-setup |
| Abilities | ≥15 cursor-bridge/* | Pełny auto-setup |
| Konflikt mcp-adapter | Duplikat wtyczki | Napraw → dezaktywuje |
| REST route MCP | Trasa REST | Napraw permalinki + auto-setup |

---

## Cursor nie łączy się z MCP

### Objaw

MCP serwer offline, timeout, 401.

### Kroki

1. Sprawdź `WP_API_URL` w `~/.cursor/mcp.json`:
   ```text
   https://DOMENA/wp-json/mcp/mcp-adapter-default-server
   ```
2. Sprawdź username i Application Password (panel → auto-setup regeneruje)
3. `curl -u "user:haslo" https://DOMENA/wp-json/mcp/mcp-adapter-default-server` — czy odpowiada?
4. Permalinki: nie mogą być „plain” bez konfiguracji REST
5. **Napraw** → `app_password` w panelu (rotacja: Pełny auto-setup)

---

## discover-abilities pusta lub brak cursor-bridge/*

### Przyczyny

- WordPress < 6.8
- Wtyczka nieaktywna (tylko mu-loader bez pełnej aktywacji)
- Błąd PHP przy ładowaniu

### Fix

```text
Panel: Pełny auto-setup
MCP: cursor-bridge/repair { "action": "full_bootstrap" }
```

---

## cursor-setup.json nie istnieje

1. **Ustawienia → Cursor Bridge → Napraw** przy wierszu setup_file
2. Lub Pełny auto-setup
3. Sprawdź uprawnienia zapisu `wp-content/inyfinn-cursor-bridge/`

---

## Application Password nie działa

- Wymagane **HTTPS**
- Sprawdź czy użytkownik MCP ma rolę **administrator**
- Po zmianie `AUTH_KEY` w wp-config — **Napraw** app_password (auto-rotacja)
- Panel pokazuje username MCP (np. `inyfinn`)

---

## read-wp-content-file: „Sensitive setup file”

**To zamierzone.** `cursor-setup.json` nie może być czytany przez MCP (sekrety).

Użyj:
- `cursor-bridge/get-cursor-bundle` (admin, przez MCP)
- lub odczyt przez SFTP workspace

---

## Wtyczka pokazuje active: false w list-plugins

Uruchom **Napraw** przy „Wtyczka aktywna” lub auto-setup.  
Od v1.2.0 `ensure_plugin_active()` dodaje wtyczkę do `active_plugins`.

---

## Konflikt z mcp-adapter

Nie instaluj obu. Ta wtyczka dezaktywuje:
- `mcp-adapter/mcp-adapter.php`
- `wordpress-mcp-adapter/mcp-adapter.php`

Napraw w panelu: wiersz „Brak konfliktowego mcp-adapter”.

---

## missing_fields w bundle

Uzupełnij w **Ustawienia → Cursor Bridge**:
- `SSH_HOST`, `SSH_USER`, `SSH_REMOTE_PUBLIC_HTML`, `WORKSPACE_PUBLIC_HTML`

Zapisz formularz → setup file odświeżony.

---

## Testy developerskie

```bash
php -l wp-content/plugins/inyfinn-cursor-bridge-mcp/includes/CursorBridge/*.php
wp eval-file wp-content/plugins/inyfinn-cursor-bridge-mcp/tests/smoke-cursor-bridge.php
```

### Przez MCP (7 szybkich testów)

1. `cursor-bridge/ping`
2. `cursor-bridge/health-check`
3. `cursor-bridge/get-site-manifest`
4. `cursor-bridge/get-cursor-bundle` + `include_secrets: false`
5. `cursor-bridge/run-auto-setup` + `rotate_password: false`
6. `cursor-bridge/read-wp-content-file` + `themes/pharma-child/style.css`
7. `cursor-bridge/read-wp-content-file` + `inyfinn-cursor-bridge/cursor-setup.json` → musi być **blocked**

---

## Kontakt / issue

GitHub Issues: https://github.com/inyfinn/inyfinn-cursor-bridge-mcp/issues

Do zgłoszenia dołącz:
- wynik `cursor-bridge/health-check` (bez sekretów)
- wersję WP i PHP z manifestu
- screenshot panelu Diagnostyka
