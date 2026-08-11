# Odtworzenie projektu od zera — Inyfinn Cursor Bridge MCP

Instrukcja krok po kroku: nowa strona WordPress → pełny dostęp w Cursorze (CMS + baza + pliki).

**Czas:** ~15 minut  
**Repo:** https://github.com/inyfinn/inyfinn-cursor-bridge-mcp

---

## 1. Model dostępu (zapamiętaj)

| Warstwa | Jak działa | Potrzebny zdalny MySQL? |
|---------|------------|-------------------------|
| **WordPress** | MCP `cursor-bridge/*` | Nie |
| **Baza danych** | MCP `db-query` przez **wpdb na serwerze** (jak Better Search Replace) | **Nie** |
| **Pliki** | SFTP workspace **lub** MCP `read/write-wp-content-file` | Nie |
| **SSH** | Opcjonalny — tylko WP-CLI w terminalu | Nie |

**NIE konfiguruj** zdalnego `mariadb` MCP do produkcyjnej bazy — hosting (SEOHost itd.) blokuje port 3306 z zewnątrz. WordPress i wtyczka używają tej samej bazy przez PHP na serwerze.

---

## 2. Wymagania

- WordPress **6.8+**
- PHP **7.4+**
- HTTPS na domenie
- Konto admina WordPress
- [Cursor IDE](https://cursor.com) z MCP
- Node.js (dla `npx`)
- Dostęp SFTP do `public_html` (opcjonalny — pliki też przez MCP)

---

## 3. Serwer — instalacja wtyczki

### Krok 3.1 — Wgraj wtyczkę

Pobierz najnowszy release:  
https://github.com/inyfinn/inyfinn-cursor-bridge-mcp/releases/latest

```text
wp-content/plugins/inyfinn-cursor-bridge-mcp/
```

Metody: ZIP w panelu WP, SFTP, lub `git clone` do `plugins/`.

### Krok 3.2 — Aktywuj

**WP Admin → Wtyczki → Aktywuj „Inyfinn Cursor Bridge MCP”**

Auto-setup tworzy:
- MU-plugin loader
- `wp-content/inyfinn-cursor-bridge/cursor-setup.json`
- Application Password (jeśli możliwe)

### Krok 3.3 — Hasło aplikacji

**Ustawienia → Cursor Bridge** → pole **„Hasło aplikacji MCP”**:

1. Jeśli auto-setup utworzył hasło — skopiuj z panelu (Pełny auto-setup).
2. Lub: **Profil → Hasła aplikacji** → utwórz nowe → wklej w pole wtyczki → **Zapisz**.

User MCP (np. `inyfinn`) musi mieć `manage_options`.

### Krok 3.4 — Test połączenia w panelu WP

**Ustawienia → Cursor Bridge** → sekcja **„Test połączenia”**.

Wszystkie 5 wierszy muszą być **✓ OK**:
- WordPress CMS
- Baza danych (wpdb — liczba tabel i postów)
- Pliki (wp-content)
- MCP REST endpoint
- Application Password

Jeśli nie — kliknij **Napraw** przy czerwonym wierszu lub **Pełny auto-setup MCP**.

---

## 4. Cursor IDE — konfiguracja

### Krok 4.1 — Workspace SFTP

Zamontuj folder `public_html` w Cursorze (dysk sieciowy, RaiDrive, itp.).

Przykład: `Z:\public_html` lub `S:\domains\domena.pl\public_html`

### Krok 4.2 — MCP w `~/.cursor/mcp.json`

**Jeden serwer** — nie dodawaj zdalnego mariadb:

```json
{
  "mcpServers": {
    "twoja-domena-wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://TWOJA-DOMENA.pl/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "login_admina",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

**Ważne:**
- `WP_API_URL` musi używać **domeny publicznej** (np. `kubara.pl`), nie wewnętrznego hosta SEOHost (`srv123.seohost.com.pl`), jeśli to różne adresy.
- Hasło aplikacji — ze spacjami lub bez (WordPress akceptuje oba).

Opcjonalnie: skopiuj fragment z `cursor-setup.json` (`mcp_json_content`) po auto-setup.

### Krok 4.3 — Plik `.env` w `public_html`

Z `cursor-setup.json` → `env_file_content` lub ręcznie:

```env
WP_SITE_URL=https://TWOJA-DOMENA.pl
WP_MCP_API_URL=https://TWOJA-DOMENA.pl/wp-json/mcp/mcp-adapter-default-server
WP_MCP_USERNAME=login_admina
WP_MCP_APP_PASSWORD=haslo aplikacji
WORKSPACE_PUBLIC_HTML=ścieżka do zamontowanego public_html
```

Dodaj do `.gitignore`: `.env`

### Krok 4.4 — Restart MCP w Cursorze

**Settings → MCP** → wyłącz/włącz serwer WordPress (lub restart Cursora).

Serwer musi być **zielony / Connected**.

---

## 5. Weryfikacja — checklist

### W panelu WordPress

- [ ] **Test połączenia** — 5× OK
- [ ] Diagnostyka MCP — zielony baner (opcjonalnie)

### W Cursorze (MCP)

```text
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

Dodatkowe testy:

```text
cursor-bridge/ping
cursor-bridge/db-query  → sql: SELECT COUNT(*) AS n FROM {prefix}posts
cursor-bridge/db-list-tables
```

### Przez REST (curl / PowerShell)

Zastąp `DOMENA`, `USER`, `PASS`:

```bash
# Ping
curl -u "USER:PASS" https://DOMENA/wp-json/cursor-bridge/v1/ping

# Pełny test
curl -u "USER:PASS" https://DOMENA/wp-json/cursor-bridge/v1/verify-connection

# Baza — liczba tabel
curl -u "USER:PASS" https://DOMENA/wp-json/cursor-bridge/v1/db-tables

# Baza — zapytanie
curl -u "USER:PASS" -X POST https://DOMENA/wp-json/cursor-bridge/v1/db-query \
  -H "Content-Type: application/json" \
  -d '{"sql":"SELECT option_value FROM wp_options WHERE option_name=\"siteurl\" LIMIT 1"}'
```

(Zamień `wp_` na prefix tabel z `wp-config.php`, np. `nzp_`.)

---

## 6. Polecenie dla agenta Cursor

W chacie Cursora na zamontowanym workspace:

```text
uruchom wtyczkę inyfinn-cursor-bridge-mcp
```

Agent:
1. Czyta `wp-content/inyfinn-cursor-bridge/cursor-setup.json`
2. Zapisuje `.env` i scala `mcp.json`
3. Wywołuje `cursor-bridge/verify-connection`
4. Pyta tylko o brakujące pola SSH (opcjonalne)

---

## 7. Typowe problemy na starcie

| Objaw | Przyczyna | Rozwiązanie |
|-------|-----------|-------------|
| „Wybrany odnośnik jest nieaktualny” | Wygasły nonce | Odśwież stronę (Ctrl+F5), zapisz ponownie (v1.3.5+ nie pokazuje białej strony) |
| Application Passwords wyłączone | HTTPS/proxy | **Napraw** w panelu lub v1.3.4+ force-enable |
| Brak hasła MCP | Hasło tylko w profilu WP | Wklej w **Ustawienia → Cursor Bridge** |
| MCP offline w Cursor | Stary config / brak restart | Zaktualizuj `mcp.json`, restart MCP |
| 401 na REST | Złe hasło lub user | Sprawdź `inyfinn` / login admina |
| Zły endpoint | srvXXX.seohost vs domena | Użyj `home_url()` — domena publiczna |
| „Brak bazy” w Cursor | Zdalny mariadb MCP | Użyj `cursor-bridge/db-query`, nie mariadb |
| Access denied MySQL z PC | Hosting blokuje 3306 | Normalne — użyj wpdb przez wtyczkę |
| REST 500 | Zepsuty route (stare wersje) | Aktualizuj do v1.5.1+ |

---

## 8. SSH (opcjonalnie)

Tylko jeśli chcesz WP-CLI w terminalu Cursora. **Nie jest wymagany** dla WordPress, bazy ani plików.

W **Ustawienia → Cursor Bridge** uzupełnij:
- `SSH_HOST` (np. `ssh.seohost.pl`)
- `SSH_USER` (np. `srv123456`)
- `SSH_REMOTE_PUBLIC_HTML` (np. `/home/srv123456/domains/domena.pl/public_html`)
- `WORKSPACE_PUBLIC_HTML` (ścieżka lokalna w Cursorze)

---

## 9. Po udanym połączeniu

1. Usuń `wp-content/inyfinn-cursor-bridge/cursor-setup.json` (zawiera sekrety)
2. Zachowaj `.env` lokalnie — **nie commituj** do gita
3. Dokumentacja abilities: `docs/ABILITIES.md`
4. Weryfikacja: `docs/VERIFY.md`

---

## 10. Historia wersji kluczowych fixów

| Wersja | Co naprawia |
|--------|-------------|
| 1.3.4 | Application Passwords na HTTPS/proxy |
| 1.3.5 | Nonce bez wp_die przy zapisie |
| 1.4.0 | Model uniwersalny: db-query przez wpdb |
| 1.5.0 | `verify-connection` + panel testu |
| 1.5.1 | Fix REST `/ping` (500) |

Zawsze instaluj **najnowszy release** z GitHub.

---

## 11. Szybka ściąga (1 strona)

```
1. Wgraj wtyczkę → Aktywuj
2. Cursor Bridge → wklej hasło aplikacji → Zapisz
3. Test połączenia → 5× OK
4. Cursor: mcp.json (jeden serwer wordpress-remote)
5. Cursor: Settings → MCP → restart serwera
6. Chat: cursor-bridge/verify-connection → ok:true
7. Baza: cursor-bridge/db-query (NIE mariadb MCP)
8. Pliki: SFTP workspace LUB read/write-wp-content-file
```

**Gotowe.** Masz WordPress + bazę + pliki w Cursorze.
