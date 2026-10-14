#!/usr/bin/env bash
# Usage: bump "Version:" + TRICHOCMP_VERSION in trichocmp.php (and Stable tag in readme.txt), commit, then ./release.sh
# Builds trichocmp.zip (folder trichocmp/ inside) and publishes GitHub release vX.Y.Z.
# Installed sites see it as a normal WordPress update within 6 h.
set -euo pipefail
cd "$(dirname "$0")"
v=$(sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p' trichocmp.php)
c=$(sed -n "s/.*define( 'TRICHOCMP_VERSION', '\([0-9.]*\)'.*/\1/p" trichocmp.php)
[ -n "$v" ] && [ "$v" = "$c" ] || { echo "Version header ($v) != TRICHOCMP_VERSION ($c)"; exit 1; }
php -l trichocmp.php >/dev/null
gh release view "v$v" >/dev/null 2>&1 && { echo "v$v already released - bump the version"; exit 1; }
git diff --quiet HEAD || { echo "Commit your changes first"; exit 1; }
git push -q origin main
rm -rf build trichocmp.zip && mkdir -p build/trichocmp && cp trichocmp.php readme.txt build/trichocmp/
# Real zip with forward slashes: zip(1) if present, else Windows bsdtar (Git Bash's GNU tar can't write zip).
if command -v zip >/dev/null; then (cd build && zip -qr ../trichocmp.zip trichocmp); else (cd build && /c/Windows/System32/tar.exe -a -c -f ../trichocmp.zip trichocmp); fi
[ "$(head -c2 trichocmp.zip)" = "PK" ] || { echo "trichocmp.zip is not a zip"; exit 1; }
gh release create "v$v" trichocmp.zip --title "v$v" --generate-notes
rm -rf build trichocmp.zip
