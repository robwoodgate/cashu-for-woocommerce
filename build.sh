#!/bin/bash
set -euo pipefail

pkg="cashu-for-woocommerce.zip" # plugin name

# Build packages
composer install
npm ci --include=dev
npm run wp-env:start
npm run build
npm run i18n:mo
npm run wp-env:destroy

# Create plugin
rm -f ${pkg}
echo "Creating zip file..."
zip -rq "${pkg}" assets languages src vendor/autoload.php vendor/composer cashu-for-woocommerce.php license.txt readme.txt -x="src/ts/*"
echo "Done"
