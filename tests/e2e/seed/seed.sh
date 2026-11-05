#!/bin/sh
# Seeds the test shop: WordPress, WooCommerce (pinned), the Storefront theme, one product, checkout pages and the
# Clikgate plugin. Connecting the plugin to the Clikgate test organisation and turning App banners on are done by
# the operator as described in README.md (the approval needs a person with a security code).
set -eu
wp core install --url="$WP_BASE_URL" --title="Bloom Foods test shop" --admin_user=e2e-admin \
  --admin_password=e2e-only-password --admin_email=e2e-admin@example.com --skip-email
wp plugin install woocommerce --version=9.4.3 --activate
wp theme install storefront --version=4.6.0 --activate
wp plugin activate clikgate
wp option update woocommerce_coming_soon no
wp wc tool run install_pages --user=e2e-admin
wp rewrite structure '/%postname%/' --hard
PRODUCT_ID=$(wp wc product create --user=e2e-admin --name="Jollof spice mix" --slug=jollof-spice-mix \
  --sku=JSM-250 --regular_price=4500 --porcelain)
echo "WP_PRODUCT_ID=$PRODUCT_ID"
