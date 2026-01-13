#!/bin/bash

pkg="cashu-for-woocommerce.zip" # plugin name

# Clear build assets
# rm assets/dist/checkout.js

# Build packages
composer install
npm ci
npm run build
# composer install --no-dev --optimize-autoloader

# Create plugin
rm -f ${pkg}
echo "Creating zip file..."
zip -rq "${pkg}" assets languages src vendor/autoload.php vendor/composer cashu-for-woocommerce.php license.txt readme.txt -x="src/ts/*"
echo "Done"
