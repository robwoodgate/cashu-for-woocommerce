#!/bin/bash
set -euo pipefail

pkg="cashu-for-woocommerce.zip" # plugin name

# Build packages
composer install
npm ci --include=dev
npm run build

# Compile .po -> .mo using gettext (no wp-env needed)
if compgen -G "languages/*.po" > /dev/null; then
  if command -v msgfmt >/dev/null 2>&1; then
    for po in languages/*.po; do
      msgfmt "$po" -o "${po%.po}.mo"
    done
  else
    echo "Warning: msgfmt (gettext) not found, skipping .mo generation."
    echo "Install gettext locally (brew install gettext) or rely on CI."
  fi
fi

# Create plugin
rm -f "${pkg}"
echo "Creating zip file..."
zip -rq "${pkg}" assets languages src vendor/autoload.php vendor/composer cashu-for-woocommerce.php license.txt readme.txt -x="src/ts/*"
echo "Done"
