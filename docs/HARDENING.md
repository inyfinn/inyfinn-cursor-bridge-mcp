# Hardening — SVG, uploady, login, limity

Moduł w Inyfinn Cursor Bridge MCP (v1.3.0+).

## Zasady bezpieczeństwa (obowiązkowe)

1. **Zawsze backup** przed edycją → `wp-content/inyfinn-cursor-bridge/backups/`
2. **Wykrywanie duplikatów** — znaczniki `BEGIN Inyfinn Cursor Bridge: …` + sygnatury
3. **Mu-plugin najpierw** — `functions.php` tylko z `allow_functions_php=true` (ostateczność)
4. **Walidacja PHP** po zapisie → rollback przy błędzie składni
5. **Bez niebezpiecznych wartości** z Twojego szkicu (patrz niżej)

---

## Co zostało poprawione względem Twojego szkicu

| Oryginał | Werdykt (3× audyt) | W paczce |
|----------|-------------------|----------|
| SVG mime + metadata + preview | OK (z poprawkami) | Tak — mu-plugin |
| Podwójny `media_library_infinite_scrolling` | Bug | Usunięty duplikat; batch 80 (nie 250) |
| `nexta_svg_metadata` | Obca nazwa | `inyfinn_cb_svg_metadata` |
| Login → 404 na wp-admin dla gości | **Niebezpieczne** (psuje AJAX/pluginów) | Soft redirect `/logowanie`, bez 404 wp-admin |
| `WP_MEMORY_LIMIT` 8000M + drugi 256M | Konflikt + absurd | Tylko `256M` / `512M` max |
| `max_execution_time = 0` | Może zawiesić hosting | `120` |
| `upload_max_filesize = 8000M` | Nierealne / ryzykowne | `64M` |
| `WP_ALLOW_REPAIR` | Dziura bezpieczeństwa | **Nie włączamy** |
| `WP_DEBUG = true` na produkcji | Ryzyko | Domyślnie `false` |
| `AUTOMATIC_UPDATER_DISABLED` | Świadoma decyzja, nie auto | **Nie włączamy** |
| `WPLANG` | Deprecated | Pominięte |
| Unikalne nazwy plików | Brakowało | `unique-uploads` z datą `Ymd-His` przy konflikcie |

---

## Funkcje

| ID | Co robi | Plik |
|----|---------|------|
| `svg-media` | SVG/SVGZ, metadane, podgląd, infinite scroll | `mu-plugins/inyfinn-svg-media.php` |
| `unique-uploads` | Przy konflikcie nazwy → `plik-20260715-201500.svg` | `mu-plugins/inyfinn-unique-uploads.php` |
| `custom-login` | URL `/logowanie`, soft redirect z wp-login.php | `mu-plugins/inyfinn-custom-login.php` |
| `wp-config` | HTTPS proxy, SSL admin, memory 256/512, DEBUG off | wstawka przed „stop editing” |
| `php-limits` | `.user.ini` (+ `.htaccess` jeśli Apache) | limity 64M/256M/120s |

---

## Panel WP

**Ustawienia → Cursor Bridge → Hardening strony**

- Status każdej funkcji
- Przycisk **Zainstaluj**
- **Zainstaluj wszystkie brakujące**

---

## MCP

```text
cursor-bridge/hardening-status
cursor-bridge/hardening-install  { "feature": "svg-media", "dry_run": true }
cursor-bridge/hardening-install  { "feature": "all" }
```

Opcje:
- `dry_run` — tylko symulacja
- `force` — nadpisz mimo duplikatu (niezalecane)
- `allow_functions_php` — ostateczność gdy mu-plugins niezapisywalne

---

## Komunikaty (5 wariantów / przypadek)

Przykłady przypadków:
- `already_exists` — kod już jest
- `similar_exists` — podobny kod od innej wtyczki/motywu
- `backup_failed` — bez backupu nie ruszamy
- `syntax_invalid` — rollback
- `installed_mu` / `installed_functions` / `installed_config`
- `skipped_dangerous` — odrzucono 8000M / REPAIR itd.

---

## Po instalacji custom-login

1. Wejdź w **Ustawienia → Bezpośrednie odnośniki → Zapisz** (flush rewrite) — wtyczka też flushuje raz
2. Test: `https://domena.pl/logowanie`
3. Stary `wp-login.php` przekieruje na `/logowanie`

---

## Przywracanie

Backupi: `wp-content/inyfinn-cursor-bridge/backups/YYYYMMDD-HHMMSS__nazwa`

Skopiuj plik z powrotem na oryginalną ścieżkę.
