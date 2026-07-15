# Instalacja — Inyfinn Cursor Bridge MCP

## Wymagania wstępne

- WordPress **6.8 lub nowszy**
- PHP **7.4+** (zalecane 8.1+)
- Konto **administratora** WordPress
- **HTTPS** na domenie (Application Passwords)
- Cursor IDE z MCP
- Node.js (dla `npx`)

---

## Metoda A — Upload ZIP (najprostsza)

1. Pobierz release z GitHub:  
   https://github.com/inyfinn/inyfinn-cursor-bridge-mcp/releases/latest

2. **WP Admin → Wtyczki → Dodaj nową → Wyślij wtyczkę** → wybierz ZIP

3. **Aktywuj**

4. **Ustawienia → Cursor Bridge** — sprawdź diagnostykę

---

## Metoda B — FTP / SFTP

1. Skopiuj folder `inyfinn-cursor-bridge-mcp` do:

```text
wp-content/plugins/inyfinn-cursor-bridge-mcp/
```

2. **WP Admin → Wtyczki → Aktywuj**

3. Sprawdź **Ustawienia → Cursor Bridge**

---

## Metoda C — Git (deweloperzy)

```bash
cd wp-content/plugins
git clone https://github.com/inyfinn/inyfinn-cursor-bridge-mcp.git
```

Aktywuj w panelu WP.

---

## Co dzieje się przy aktywacji?

| Krok | Plik / efekt |
|------|----------------|
| 1 | Wtyczka dodana do `active_plugins` |
| 2 | `wp-content/mu-plugins/000-inyfinn-cursor-bridge-mcp-loader.php` |
| 3 | Application Password „Cursor MCP (Inyfinn)” |
| 4 | `wp-content/inyfinn-cursor-bridge/cursor-setup.json` |
| 5 | Profil hostingu (auto: seohost jeśli domena zawiera „seohost”) |
| 6 | Dezaktywacja konfliktowego `mcp-adapter` (jeśli był) |
| 7 | Flush rewrite rules (REST MCP) |

Jeśli coś się nie udało — **Ustawienia → Cursor Bridge → Napraw** lub **Pełny auto-setup**.

---

## Konfiguracja Cursor IDE

### 1. Workspace

Otwórz w Cursorze folder `public_html` strony (SFTP, RaiDrive, dysk S: itd.).

### 2. Polecenie agenta

W chacie:

```text
uruchom wtyczkę inyfinn-cursor-bridge-mcp
```

Agent (patrz `AGENTS.md`):
- czyta `wp-content/inyfinn-cursor-bridge/cursor-setup.json`
- zapisuje `public_html/.env` (gitignore!)
- scala `mcp.json` do `~/.cursor/mcp.json`
- pyta o `missing_fields` (SSH, WORKSPACE_PUBLIC_HTML)

### 3. Ręczna konfiguracja MCP (opcjonalnie)

Plik `~/.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "twoj-serwer-wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://TWOJA-DOMENA.pl/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "login_admina",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Hasło i username są w `cursor-setup.json` lub z panelu **get-cursor-bundle** (MCP).

---

## Weryfikacja — checklist

### Panel WordPress

- [ ] **Ustawienia → Cursor Bridge** — baner zielony
- [ ] Wszystkie 12 testów diagnostyki = OK
- [ ] `cursor-setup.json` istnieje (ścieżka w tabeli)

### Cursor (po MCP)

- [ ] MCP serwer połączony (Settings → MCP)
- [ ] `cursor-bridge/ping` → `ok: true`
- [ ] `cursor-bridge/health-check` → `healthy: true`
- [ ] discover-abilities → lista z `cursor-bridge/ping`, `health-check`, `repair`…

### Opcjonalnie SSH (SEOHost itp.)

W panelu **Ustawienia → Cursor Bridge** uzupełnij:

| Pole | Przykład SEOHost |
|------|------------------|
| SSH_HOST | `ssh.seohost.pl` |
| SSH_USER | `srv123456` |
| SSH_REMOTE_PUBLIC_HTML | `/home/srv123456/domains/domena.pl/public_html` |
| WORKSPACE_PUBLIC_HTML | `S:\domains\domena.pl\public_html` |

Zapisz → `cursor-setup.json` odświeżony, `missing_fields` puste.

---

## Instalacja na innym hostingu (generic)

1. Aktywuj wtyczkę
2. **Ustawienia → Cursor Bridge** → auto-setup jeśli potrzeba
3. MCP endpoint zawsze:  
   `https://TWOJA-DOMENA/wp-json/mcp/mcp-adapter-default-server`
4. W Cursorze: to samo polecenie `uruchom wtyczkę inyfinn-cursor-bridge-mcp`
5. Uzupełnij SSH/workspace według hostingu

Profil `generic` — kroki w `cursor-bridge/get-setup-guide`.

---

## WP-CLI

```bash
# Smoke test
wp eval-file wp-content/plugins/inyfinn-cursor-bridge-mcp/tests/smoke-cursor-bridge.php

# Ręczny bootstrap (na serwerze)
wp eval 'echo json_encode( \Inyfinn_Cursor_Bridge\Installer::full_bootstrap( false ) );'
```

---

## Następne kroki

- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) — gdy coś nie działa
- [ABILITIES.md](ABILITIES.md) — pełna lista API MCP
- [AGENTS.md](../AGENTS.md) — dla agenta Cursor
