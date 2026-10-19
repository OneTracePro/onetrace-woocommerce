#!/usr/bin/env sh
# Integration tests in Docker: WordPress + WooCommerce + this plugin, PHPUnit inside the WordPress container.
#   tests/env/run.sh                    — latest WordPress and WooCommerce
#   WP_TAG=6.8-php8.1-apache WC_VERSION=9.9.5 tests/env/run.sh
set -eu
cd "$(dirname "$0")"
docker compose up -d --wait db wordpress >/dev/null
run() { docker compose exec -T wordpress sh -c "$1"; }
run '[ -x /usr/local/bin/wp ] || (curl -sS -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp)'
run 'wp --allow-root core is-installed 2>/dev/null || wp --allow-root core install --url=http://localhost --title=Shop --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email'
run 'wp --allow-root config set FS_METHOD direct >/dev/null'
# No background queue runners while the tests flush the queue themselves (tests/env/no-background-queue.php).
run 'mkdir -p wp-content/mu-plugins && cp wp-content/plugins/onetrace-woocommerce/tests/env/no-background-queue.php wp-content/mu-plugins/'
run "wp --allow-root plugin is-installed woocommerce || wp --allow-root plugin install woocommerce ${WC_VERSION:+--version=$WC_VERSION}"
run 'wp --allow-root plugin is-installed translatepress-multilingual || wp --allow-root plugin install translatepress-multilingual'
# Wishlist plugins whose actions the plugin follows (tests/Integration/WishlistTest.php).
run 'for p in yith-woocommerce-wishlist ti-woocommerce-wishlist; do wp --allow-root plugin is-installed $p || wp --allow-root plugin install $p; done'
# Tracking plugins whose parcels the plugin sends (tests/Integration/ShipmentsTest.php).
run 'for p in woo-advanced-shipment-tracking trackship-for-woocommerce; do wp --allow-root plugin is-installed $p || wp --allow-root plugin install $p; done'
run 'wp --allow-root plugin activate woocommerce onetrace-woocommerce >/dev/null'
# Their latest versions may not support an older WooCommerce of the matrix: then WishlistTest and ShipmentsTest skip the plugin.
run 'for p in yith-woocommerce-wishlist ti-woocommerce-wishlist woo-advanced-shipment-tracking trackship-for-woocommerce; do wp --allow-root plugin is-active $p || wp --allow-root plugin activate $p >/dev/null 2>&1 || echo "$p is not active"; done'
# Separately: on activation TranslatePress redirects to its settings, which ends the wp-cli command.
run 'wp --allow-root plugin is-active translatepress-multilingual || wp --allow-root plugin activate translatepress-multilingual >/dev/null 2>&1 || true'
run 'wp --allow-root eval-file wp-content/plugins/onetrace-woocommerce/tests/env/translatepress.php'
run 'cd wp-content/plugins/onetrace-woocommerce && php vendor/bin/phpunit'
