#!/usr/bin/env bash
# Vytvoří balíček pro ruční nahrání přes FTP: dist/marmal-brand-X.Y.Z.zip (obsah složky public/).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(tr -d '[:space:]' < public/VERSION)
mkdir -p dist
rm -f "dist/marmal-brand-$VERSION.zip"
(cd public && zip -qr "../dist/marmal-brand-$VERSION.zip" . -x 'data-path.php')
echo "dist/marmal-brand-$VERSION.zip"
