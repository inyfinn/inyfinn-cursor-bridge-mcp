# Test integracyjny w WordPress Playground

Prawdziwy WordPress + Elementor, bez serwera. Każda ability wywołana przez `wp_get_ability()->execute()`.

Z katalogu nadrzędnego repo (Git Bash: `MSYS_NO_PATHCONV=1`, inaczej `/wordpress` zamieni się w ścieżkę Windows):

```bash
OUT=$(mktemp -d); cp tests/playground/*.php "$OUT"/
MSYS_NO_PATHCONV=1 npx -y @wp-playground/cli@latest run-blueprint   --blueprint=tests/playground/blueprint.json   --mount-dir "$PWD" /wordpress/wp-content/plugins/inyfinn-cursor-bridge-mcp   --mount-dir "$OUT" /out
cat "$OUT/result.json"
```

Wtyczka jest aktywowana przez `activate_plugin()` w PHP (`activate.php`), nie krokiem `activatePlugin`.
Błędy PHP: `$OUT/debug.log`.

Wersja 1.7.1 w tym teście wywracała całą stronę (nieskończona rekurencja przy adresie bez https) — dlatego test działa na http.
