# Inyfinn Cursor Bridge MCP

**Repo:** https://github.com/inyfinn/inyfinn-cursor-bridge-mcp  
**Wersja:** 1.3.0  
**Licencja:** GPL-2.0-or-later

Fork [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) 0.5.0 z wbudowanym **auto-setupem** i **diagnostyką** dla [Cursor IDE](https://cursor.com).

Łączy WordPress z Cursorem przez MCP — bez ręcznego tworzenia Application Password, bez kopiowania haseł z panelu WP.

---

## Co robi ta wtyczka?

| Po aktywacji | Efekt |
|--------------|--------|
| MU-plugin loader | Wtyczka ładuje się nawet po problemach z `active_plugins` |
| Application Password | Automatyczne hasło „Cursor MCP (Inyfinn)” dla admina |
| `cursor-setup.json` | Plik konfiguracyjny dla agenta Cursor (SFTP workspace) |
| Bundle `.env` + `mcp.json` | DB z `wp-config`, MCP endpoint, brakujące pola SSH |
| 19 abilities MCP | Ping, manifest, pliki, WooCommerce, health-check, repair |
| Panel diagnostyczny | **Ustawienia → Cursor Bridge** — testy + przyciski Napraw |
| Hardening | SVG, unikalne nazwy uploadów, `/logowanie`, limity PHP — z backupem i ochroną przed duplikatami |

---

## Wymagania

| Składnik | Wersja |
|----------|--------|
| WordPress | **6.8+** (Abilities API) |
| PHP | 7.4+ |
| Cursor IDE | z obsługą MCP |
| Node.js | dla `npx @automattic/mcp-wordpress-remote` |
| HTTPS | wymagane dla Application Passwords |

**Nie instaluj** osobnego pluginu `mcp-adapter` — wtyczka go zastępuje i dezaktywuje konflikt.

---

## Instalacja na nowym WordPressie (5 minut)

### Krok 1 — Wgraj wtyczkę

```text
wp-content/plugins/inyfinn-cursor-bridge-mcp/
```

Źródła:
- GitHub Releases: https://github.com/inyfinn/inyfinn-cursor-bridge-mcp/releases
- lub `git clone` do `plugins/`

### Krok 2 — Aktywuj

**WP Admin → Wtyczki → Aktywuj „Inyfinn Cursor Bridge MCP”**

Auto-setup uruchamia się przy aktywacji (mu-plugin, hasło, setup file).

### Krok 3 — Sprawdź diagnostykę

**Ustawienia → Cursor Bridge**

Wszystkie wiersze w tabeli **Diagnostyka** powinny być **✓ OK**.  
Jeśli nie — kliknij **Napraw** przy danym wierszu lub **Pełny auto-setup**.

### Krok 4 — Cursor workspace

1. Zamontuj folder `public_html` w Cursorze (SFTP / dysk sieciowy).
2. W chacie Cursora napisz **dokładnie**:

```text
uruchom wtyczkę inyfinn-cursor-bridge-mcp
```

Agent przeczyta `wp-content/inyfinn-cursor-bridge/cursor-setup.json`, zapisze `.env` i `mcp.json`, zapyta tylko o brakujące pola (SSH, ścieżka workspace).

### Krok 5 — Zweryfikuj połączenie MCP

W Cursorze (po skonfigurowaniu MCP):

| Test | Oczekiwany wynik |
|------|------------------|
| `cursor-bridge/ping` | `ok: true`, `bridge_version: "1.2.0"` |
| `cursor-bridge/health-check` | `healthy: true`, `failed_count: 0` |
| discover-abilities | ≥19 pozycji `cursor-bridge/*` |

Szczegóły: [docs/INSTALLATION.md](docs/INSTALLATION.md)

---

## Jak wiedzieć, że działa?

### W panelu WordPress

**Ustawienia → Cursor Bridge** → zielony baner: *„Wszystko działa — wtyczka gotowa dla Cursor IDE.”*

### W Cursorze (po MCP)

```
Wywołaj cursor-bridge/ping przez MCP
```

Odpowiedź:

```json
{
  "ok": true,
  "bridge_version": "1.2.0",
  "public_abilities": 19
}
```

### Przez MCP health-check

```
Wywołaj cursor-bridge/health-check
```

Odpowiedź: `"healthy": true`, `"overall": "ok"`.

---

## Trzy warstwy pracy

| Warstwa | Mechanizm | Kiedy |
|---------|-----------|-------|
| **Pliki lokalnie** | Cursor workspace (SFTP / dysk) | Edycja PHP, CSS, JS — preferowane |
| **Pliki zdalnie** | MCP `write-wp-content-file` | Gdy brak SFTP |
| **WordPress / DB** | MCP → REST → abilities | Manifest, pluginy, WooCommerce |
| **SSH / WP-CLI** | Terminal + `.env` | Cache, migracje, batch |

---

## Abilities (`cursor-bridge/*`)

| Ability | Opis |
|---------|------|
| `ping` | Health check wersji |
| `health-check` | Pełna diagnostyka (12 testów) |
| `repair` | Naprawa jednego komponentu |
| `run-auto-setup` | Pełny bootstrap |
| `get-cursor-bundle` | mcp.json + .env + sekrety (admin) |
| `get-site-manifest` | Kontekst strony bez sekretów |
| `write-wp-content-file` | Zapis pliku w wp-content |
| `read-wp-content-file` | Odczyt pliku (max 512 KB) |
| `list-plugins` / `list-themes` | Audyt |
| `wc-list-products` / `wc-list-orders` | WooCommerce |

Pełna lista: [docs/ABILITIES.md](docs/ABILITIES.md)

---

## Naprawa problemów

1. **Panel WP:** Ustawienia → Cursor Bridge → przycisk **Napraw** przy czerwonym wierszu
2. **MCP:** `cursor-bridge/repair` z `action: "full_bootstrap"`
3. **Dokumentacja:** [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)

---

## Bezpieczeństwo

- `cursor-setup.json` — `chmod 0600`, katalog chroniony `.htaccess`
- Odczyt setup file przez MCP **zablokowany** — sekrety tylko przez `get-cursor-bundle` (admin)
- Application Password szyfrowane w opcji WP (`AUTH_KEY`)
- Usuń `cursor-setup.json` po pierwszym udanym połączeniu Cursora

---

## Dokumentacja

| Plik | Zawartość |
|------|-----------|
| `docs/HARDENING.md` | SVG, uploady, login, limity — zasady bezpieczeństwa |
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Instalacja krok po kroku |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Rozwiązywanie problemów |
| [docs/ABILITIES.md](docs/ABILITIES.md) | Reference abilities |
| [AGENTS.md](AGENTS.md) | Instrukcja dla agenta Cursor |
| [CHANGELOG.md](CHANGELOG.md) | Historia wersji |

---

## Testy (developers)

```bash
wp eval-file wp-content/plugins/inyfinn-cursor-bridge-mcp/tests/smoke-cursor-bridge.php
```

---

## Autor

[Inyfinn](https://inyfinn.pl) — ETA Innovations i projekty WordPress/WooCommerce.
