# Hardening — SVG, uploady, wp-config, limity 8000M

Moduł w Inyfinn Cursor Bridge MCP (v1.3.1+).

## Zasady

1. **Zawsze backup** przed edycją → `wp-content/inyfinn-cursor-bridge/backups/`
2. **Wykrywanie duplikatów** — znaczniki `BEGIN Inyfinn Cursor Bridge: …` + sygnatury
3. **Mu-plugin domyślnie** — `functions.php` gdy zaznaczysz w panelu
4. **Walidacja PHP** po zapisie → rollback przy błędzie składni
5. **Wymuś ponowne zastosowanie** — nadpisuje nasze istniejące bloki (wp-config, .user.ini, functions.php)

---

## Funkcje

| ID | Co robi | Gdzie trafia |
|----|---------|--------------|
| `svg-media` | SVG/SVGZ, metadane, podgląd, infinite scroll | `mu-plugins/` lub `functions.php` |
| `unique-uploads` | Przy konflikcie nazwy → `plik-20260715-201500.svg` | `mu-plugins/` lub `functions.php` |
| `wp-config` | HTTPS proxy, DEBUG, WPLANG, SSL admin, FS_METHOD, WP_ALLOW_REPAIR, WP_CACHE, memory **8000M** | `wp-config.php` |
| `php-limits` | upload/post/memory **8000M**, `max_execution_time=0` | `.user.ini` + `.htaccess` |

**Usunięte w 1.3.1:** `custom-login` (redirect `/logowanie`) — nie jest już częścią paczki.

---

## Panel WP

**Ustawienia → Cursor Bridge → Poprawki strony**

1. Zaznacz poprawki (checkboxy)
2. Opcjonalnie: **Wstrzyknij SVG i uploady do functions.php**
3. Opcjonalnie: **Wymuś ponowne zastosowanie**
4. Kliknij **Zastosuj zaznaczone poprawki**

---

## MCP

```text
cursor-bridge/hardening-status
cursor-bridge/hardening-install  { "feature": "php-limits" }
cursor-bridge/hardening-install  { "feature": "svg-media", "prefer_functions_php": true }
cursor-bridge/hardening-install  { "feature": "wp-config", "force": true }
cursor-bridge/hardening-install  { "feature": "all" }
```

Opcje:
- `dry_run` — symulacja
- `force` / `replace` — nadpisz nasze bloki
- `prefer_functions_php` — SVG/uploady do `functions.php` zamiast mu-plugins
- `allow_functions_php` — fallback gdy mu-plugins niezapisywalne

---

## Komunikaty

- `already_exists` — kod już jest (użyj force)
- `similar_exists` — podobny kod od innej wtyczki
- `backup_failed` — bez backupu nie ruszamy
- `syntax_invalid` — rollback
- `installed_mu` / `installed_functions` / `installed_config` / `installed_userini`

---

## Przywracanie

Backupi: `wp-content/inyfinn-cursor-bridge/backups/YYYYMMDD-HHMMSS__nazwa`

Skopiuj plik z powrotem na oryginalną ścieżkę.
