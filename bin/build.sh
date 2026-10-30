#!/usr/bin/env bash
# Build minified assets and the installable zip.
# Usage: bin/build.sh            (requires Node.js; uses esbuild through npx)
set -euo pipefail
cd "$(dirname "$0")/.."
ESBUILD="${ESBUILD:-npx --yes esbuild@0.24.0}"
for f in assets/js/wahou.js assets/js/checkout.js assets/js/gateways/stripe.js assets/js/gateways/paypal.js; do
	$ESBUILD "$f" --minify --target=es2019 --log-level=warning --outfile="${f%.js}.min.js"
done
for f in assets/css/wahou.css assets/css/checkout.css; do
	$ESBUILD "$f" --minify --log-level=warning --outfile="${f%.css}.min.css"
done
version=$(sed -n "s/^ \* Version: *//p" wahou-commerce.php | head -1)
mkdir -p dist
rm -f "dist/wahou-commerce-${version}.zip"
cd ..
zip -qr "wahou-commerce/dist/wahou-commerce-${version}.zip" wahou-commerce \
	-x "wahou-commerce/dist/*" "wahou-commerce/bin/*" "wahou-commerce/docs/*" "wahou-commerce/.git*" "wahou-commerce/*.po~"
echo "Built wahou-commerce/dist/wahou-commerce-${version}.zip"
