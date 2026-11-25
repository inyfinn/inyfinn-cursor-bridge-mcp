# Silnik MCP Adapter i nasza warstwa

Wtyczka to jedna paczka z dwiema częściami:

| Część | Pliki | Kto zmienia |
|-------|-------|-------------|
| **Silnik** | `includes/*` poza `includes/CursorBridge/`, cały `vendor/` | Nikt ręcznie. Kopia 1:1 oficjalnej paczki [mcp-adapter z wordpress.org](https://wordpress.org/plugins/mcp-adapter/) |
| **Nasza warstwa** | `inyfinn-cursor-bridge-mcp.php`, `includes/CursorBridge/`, `install/`, `tests/`, `docs/`, `assets/`, `tools/` | My |

Obecny silnik: **0.7.2** (`INYFINN_CURSOR_BRIDGE_ENGINE_VERSION`, `WP\MCP\Core\McpAdapter::VERSION`).

## Zasada

Pliki silnika mają być identyczne z paczką wordpress.org, bajt w bajt. Wtedy aktualizacja to podmiana plików, bez łączenia zmian.
Wszystko, co nasze, robimy hookami silnika i Abilities API w `includes/CursorBridge/`:

- **Nazwa serwera, instrukcje MCP, wersja serwera** — filtr `mcp_adapter_default_server_config` w `Bootstrap::default_server_config()`. `server_description` idzie do klienta jako `instructions` przy `initialize` (`Agent_Playbook::instructions()`).
- **Narzędzia `cursor-bridge/*`** — `wp_register_ability()` w `Abilities` (kategoria `cursor-bridge`, `meta.mcp = {public: true, type: tool}`). Klient woła je przez `mcp-adapter/execute-ability` serwera domyślnego `/wp-json/mcp/mcp-adapter-default-server`.
- **Start silnika** — plik główny robi to samo co upstream `mcp-adapter.php`: `includes/Autoloader.php` → `Autoloader::autoload()` (ładuje `vendor/autoload_packages.php`, Jetpack Autoloader) → `Plugin::instance()`. Potem nasze `require_once` z `includes/CursorBridge/`.

### Stałe

| Stała | Wartość | Kto definiuje |
|-------|---------|---------------|
| `WP_MCP_DIR` | katalog wtyczki | silnik (`Autoloader::autoload()`, bez sprawdzania `defined()`, dlatego plik główny jej nie definiuje) |
| `WP_MCP_VERSION` | wersja silnika | silnik (`Plugin::constants()` = `McpAdapter::VERSION`). Gdy jest zdefiniowana, `McpAdapter::check_plugin_loaded()` nie zgłasza „bundled library deprecated” |
| `INYFINN_CURSOR_BRIDGE_ENGINE_VERSION` | wersja silnika z paczki | plik główny (ustawia ją `tools/update-engine.sh`) |
| `INYFINN_CURSOR_BRIDGE_MCP_VERSION` | wersja wtyczki | plik główny (podbija etap wydania) |
| `WORDPRESS_MCP_ADAPTER_VERSION` | `1.5.5` | plik główny; martwa od 1.0.0, zostawiona dla kodu z zewnątrz |

### Oficjalna wtyczka MCP Adapter obok naszej

Obie deklarują te same klasy `WP\MCP\*`. `Installer` dezaktywuje `mcp-adapter/mcp-adapter.php` i `wordpress-mcp-adapter/mcp-adapter.php` przy włączeniu i naprawie, a panel pokazuje konflikt.
Gdy oficjalna załadowała się pierwsza (np. aktywna sieciowo), plik główny bierze jej `Autoloader` zamiast deklarować go drugi raz.
Gdy nasza jest pierwsza, oficjalnej nie da się włączyć: jej `mcp-adapter.php` deklaruje `Autoloader` drugi raz, błąd krytyczny łapie piaskownica aktywacji WordPressa (wniosek z kodu, nie sprawdzony na stronie).
Inna wtyczka z dołączonym silnikiem przez Jetpack Autoloader (np. WooCommerce): Jetpack wybiera najnowszą wersję klas `WP\MCP\*` spośród aktywnych wtyczek.
Jeśli wygra cudza kopia, silnik pokaże komunikat „Another version of MCP Adapter is already loaded”.

## Aktualizacja silnika

```bash
git checkout -b feat/engine-X.Y.Z
tools/update-engine.sh X.Y.Z /pelna/sciezka/pustego/katalogu/roboczego
```

Skrypt:

1. Pobiera `https://downloads.wordpress.org/plugin/mcp-adapter.X.Y.Z.zip` (paczka ma zbudowany `vendor/`, composer niepotrzebny).
2. Sprawdza, że to silnik w wersji X.Y.Z i że nie ma w nim `includes/CursorBridge`.
3. `git rm` plików silnika, których nowa wersja już nie ma. Niczego poza plikami silnika nie usuwa.
4. Kopiuje `includes/` i `vendor/` z paczki, `git add`, sprawdza, że pliki w indeksie są bajt w bajt jak w paczce.
5. Ustawia `INYFINN_CURSOR_BRIDGE_ENGINE_VERSION`, wypisuje statystykę zmian, nagłówek i bootstrap upstream `mcp-adapter.php`. Pełny diff `includes/` zostaje w katalogu roboczym (`engine.diff`).

Paczka zostaje w katalogu roboczym, usuń ją sam po pracy.

## Co sprawdzić po aktualizacji

1. **CHANGELOG upstream**, sekcje *Breaking Changes* i *Changed* od poprzedniej wersji, plus `docs/migration/` w [repo upstream](https://github.com/WordPress/mcp-adapter). Każdą pozycję sprawdź grepem w `includes/CursorBridge/` i w pliku głównym. Typowe miejsca: wymagane pola ability (`category`, `meta.mcp` jako tablica), filtry `mcp_adapter_*`, kolejność walidacji wejścia i uprawnień, nazwy trasy i serwera domyślnego.
2. **Bootstrap**: porównaj wypisany upstream `mcp-adapter.php` z naszym plikiem głównym (nowe stałe, nowy plik autoloadera, wymagania).
3. **Nagłówek**: `Requires at least` / `Requires PHP` nie niższe niż w upstream. Zmień też próg w `Health::check_wordpress_version()` i w `GitHub_Updater`.
4. **Filtr `mcp_adapter_default_server_config`**: nadal istnieje w `includes/Servers/DefaultServerFactory.php` i ma pola `server_name`, `server_description`, `server_version`.
5. `php -l` na `includes/`, pliku głównym, `install/`, `tests/`.
6. **Staging** (`WP_DEBUG_LOG` włączony): wgraj ZIP, Włącz, potem `cursor-bridge/verify-connection` → `ok: true`, `discover-abilities` zwraca `cursor-bridge/*`, panel Ustawienia → Cursor Bridge cały zielony. W `debug.log` nie może być `bundled dependency`, `Another version of MCP Adapter`, `already defined`, `Fatal`.
7. ZIP do testów: `git archive --format=zip --prefix=inyfinn-cursor-bridge-mcp/ -o <plik>.zip HEAD` (ten sam układ co wydania).
