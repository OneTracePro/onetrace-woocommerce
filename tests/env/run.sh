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
run "wp --allow-root plugin is-installed woocommerce || wp --allow-root plugin install woocommerce ${WC_VERSION:+--version=$WC_VERSION}"
run 'wp --allow-root plugin is-installed translatepress-multilingual || wp --allow-root plugin install translatepress-multilingual'
run 'wp --allow-root plugin activate woocommerce translatepress-multilingual onetrace-woocommerce >/dev/null'
run 'wp --allow-root eval-file wp-content/plugins/onetrace-woocommerce/tests/env/translatepress.php'
run 'cd wp-content/plugins/onetrace-woocommerce && php vendor/bin/phpunit'
