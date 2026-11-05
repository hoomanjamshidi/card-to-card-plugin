#!/usr/bin/env bash
# Build the installable plugin: dist/card-to-card-<version>.zip with only the runtime files
# (no sources, tests, dev site, node_modules or vendor).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -m1 "Version:" card-to-card.php | awk '{print $NF}')
npm run build >/dev/null
OUT="dist/card-to-card"
rm -rf dist && mkdir -p "$OUT"
cp -R card-to-card.php uninstall.php readme.txt LICENSE includes templates languages "$OUT/"
mkdir -p "$OUT/assets"
cp -R assets/admin assets/banks assets/fonts "$OUT/assets/"
mkdir -p "$OUT/assets/css" "$OUT/assets/js"
cp assets/css/payment.css assets/css/payment.min.css "$OUT/assets/css/"
cp assets/js/payment.js assets/js/payment.min.js assets/js/blocks.js "$OUT/assets/js/"
find "$OUT" -name ".DS_Store" -delete
(cd dist && zip -qr "card-to-card-${VERSION}.zip" card-to-card)
rm -rf "$OUT"
echo "dist/card-to-card-${VERSION}.zip ($(du -h "dist/card-to-card-${VERSION}.zip" | cut -f1))"
