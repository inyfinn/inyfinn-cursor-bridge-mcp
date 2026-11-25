#!/usr/bin/env bash
# Replace the MCP Adapter engine with an official release from wordpress.org.
#
#   tools/update-engine.sh <version> [work-dir]
#
# Engine = includes/* except includes/CursorBridge/, plus vendor/. These files must stay
# byte-identical to the wordpress.org package. Our layer (includes/CursorBridge, install/, tests/,
# docs/, assets/) is never touched; in the main plugin file only the constant
# INYFINN_CURSOR_BRIDGE_ENGINE_VERSION is changed. See docs/ENGINE.md.
#
# Deletes nothing except tracked engine files missing from the new release (git rm, so
# git keeps them). The downloaded ZIP stays in work-dir (default <repo>/.work/engine-<version>,
# ignored by git): remove it yourself when done.
set -euo pipefail

VERSION="${1:-}"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Usage: tools/update-engine.sh <version, e.g. 0.7.2> [work-dir]" >&2
	exit 2
fi

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="$REPO/inyfinn-cursor-bridge-mcp.php"
cd "$REPO"
[[ -f "$MAIN" && -d includes/CursorBridge && -d .git ]] || { echo "Not the plugin repo: $REPO" >&2; exit 1; }

if [[ -n "$(git status --porcelain -- includes vendor "$MAIN")" ]]; then
	echo "Commit or stash changes in includes/, vendor/ and the main file first." >&2
	exit 1
fi

WORK="${2:-$REPO/.work/engine-$VERSION}"
mkdir -p "$WORK"
[[ -z "$(ls -A "$WORK")" ]] || { echo "Work dir must be empty: $WORK" >&2; exit 1; }

ZIP="$WORK/mcp-adapter.$VERSION.zip"
curl -fsSL -o "$ZIP" "https://downloads.wordpress.org/plugin/mcp-adapter.$VERSION.zip"
unzip -q "$ZIP" -d "$WORK/x"
PKG="$WORK/x/mcp-adapter"

# The package must be the engine we expect, and must not carry anything that would land on our layer.
[[ -f "$PKG/includes/Core/McpAdapter.php" && -f "$PKG/vendor/autoload_packages.php" ]] || { echo "Unexpected package layout in $PKG" >&2; exit 1; }
grep -q "public const VERSION = '$VERSION';" "$PKG/includes/Core/McpAdapter.php" || { echo "McpAdapter::VERSION in the package is not $VERSION" >&2; exit 1; }
[[ ! -e "$PKG/includes/CursorBridge" ]] || { echo "Package contains includes/CursorBridge: refusing" >&2; exit 1; }

( cd "$PKG" && find includes vendor -type f | LC_ALL=C sort ) > "$WORK/new.txt"
git ls-files -- includes vendor | grep -v '^includes/CursorBridge/' | LC_ALL=C sort > "$WORK/old.txt"
LC_ALL=C comm -23 "$WORK/old.txt" "$WORK/new.txt" > "$WORK/removed.txt"

# Only tracked engine files that the new release no longer ships.
while IFS= read -r f; do
	case "$f" in
		includes/CursorBridge/*) echo "Refusing to remove $f" >&2; exit 1 ;;
		includes/*|vendor/*) git rm -q -- "$f" ;;
		*) echo "Refusing to remove $f" >&2; exit 1 ;;
	esac
done < "$WORK/removed.txt"

cp -a "$PKG/includes/." includes/
cp -a "$PKG/vendor/." vendor/
git add -A -- includes vendor

# Self-check: tracked engine = package file list, byte for byte (no CRLF conversion).
git ls-files -- includes vendor | grep -v '^includes/CursorBridge/' | LC_ALL=C sort | diff -q - "$WORK/new.txt" >/dev/null \
	|| { echo "Tracked engine files differ from the package list" >&2; exit 1; }
git ls-files -s -- includes vendor | grep -v 'includes/CursorBridge/' | awk '{print $2"  "$4}' | LC_ALL=C sort -k2 > "$WORK/idx.txt"
( cd "$PKG" && git hash-object --no-filters --stdin-paths < "$WORK/new.txt" | paste -d' ' - "$WORK/new.txt" | awk '{print $1"  "$2}' | LC_ALL=C sort -k2 ) > "$WORK/pkg.txt"
diff -q "$WORK/idx.txt" "$WORK/pkg.txt" >/dev/null || { echo "Staged engine blobs differ from the package" >&2; exit 1; }

grep -q "define( 'INYFINN_CURSOR_BRIDGE_ENGINE_VERSION', '$VERSION' )" "$MAIN" \
	|| sed -i "s/define( 'INYFINN_CURSOR_BRIDGE_ENGINE_VERSION', '[0-9.]*' )/define( 'INYFINN_CURSOR_BRIDGE_ENGINE_VERSION', '$VERSION' )/" "$MAIN"
grep -q "define( 'INYFINN_CURSOR_BRIDGE_ENGINE_VERSION', '$VERSION' )" "$MAIN" || { echo "Could not set INYFINN_CURSOR_BRIDGE_ENGINE_VERSION in $MAIN" >&2; exit 1; }

git diff --cached -- includes > "$WORK/engine.diff"
echo
echo "Engine $VERSION staged. Removed: $(wc -l < "$WORK/removed.txt") file(s)."
git diff --cached --stat -- includes vendor | tail -n 1
echo
echo "== includes/ changes (full diff: $WORK/engine.diff) =="
git diff --cached --stat -- includes | sed '$d'
echo
echo "== Upstream header (compare with $MAIN) =="
grep -E '^\s*\* (Version|Requires at least|Requires PHP):' "$PKG/mcp-adapter.php"
echo
echo "== Upstream bootstrap mcp-adapter.php (port changes to our main file by hand) =="
sed -n '/^declare/,$p' "$PKG/mcp-adapter.php"
echo
echo "Next: read the upstream CHANGELOG (Breaking Changes), run php -l, test on staging (docs/ENGINE.md)."
echo "Downloaded package left in: $WORK"
