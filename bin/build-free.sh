#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="storehand-ai-product-manager-for-woocommerce"
OUT="dist/${PLUGIN_SLUG}.zip"
OLDPWD="$(pwd)"

echo "▶ Building assets..."
npm run build

echo "▶ Creating ${OUT}..."
mkdir -p dist
rm -f "$OUT"

zip -r "$OUT" \
    pilot-for-woocommerce.php \
    readme.txt \
    uninstall.php \
    includes/ \
    build/ \
    --exclude "includes/freemius/*" \
    --exclude "**/.DS_Store"

# Re-wrap so the zip extracts to the correct folder name
STAGING=$(mktemp -d)
unzip -q "$OUT" -d "${STAGING}/${PLUGIN_SLUG}"
rm "$OUT"
find "${STAGING}" -name ".DS_Store" -delete
cd "$STAGING"
zip -r - "${PLUGIN_SLUG}/" > "${OLDPWD}/${OUT}"
cd "$OLDPWD"
rm -rf "$STAGING"

echo "✅ ${OUT}"
