#!/bin/bash
set -euo pipefail

pkg="cashu-for-woocommerce.zip" # plugin name

# Build packages
composer install
npm ci --include=dev
npm run build
npm run i18n:mo

# Create plugin
rm -f ${pkg}
echo "Creating zip file..."
zip -rq "${pkg}" assets languages src vendor/autoload.php vendor/composer cashu-for-woocommerce.php license.txt readme.txt -x="src/ts/*"
echo "Done"
