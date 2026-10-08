#!/usr/bin/env sh
# Smoke test of dist/onetrace-woocommerce.zip: installs the build instead of the working copy and sends a real
# order over cURL to tests/env/receiver.php.
set -eu
cd "$(dirname "$0")"
docker compose cp ../../dist/onetrace-woocommerce.zip wordpress:/tmp/plugin.zip
docker compose exec -T wordpress sh -c '
set -e
wp() { command wp --allow-root "$@"; }
wp plugin deactivate onetrace-woocommerce >/dev/null
rm -rf /tmp/build && mkdir /tmp/build && cd /tmp/build && php -r "\$z=new ZipArchive;\$z->open(\"/tmp/plugin.zip\");\$z->extractTo(\".\");"
rm -rf /var/www/html/wp-content/plugins/onetrace-smoke && mv /tmp/build/onetrace-woocommerce /var/www/html/wp-content/plugins/onetrace-smoke
cd /var/www/html
rm -f /tmp/receiver.log; (php -S 127.0.0.1:9000 /var/www/html/wp-content/plugins/onetrace-woocommerce/tests/env/receiver.php >/dev/null 2>&1 &)
sleep 1
wp plugin activate onetrace-smoke >/dev/null
wp option update onetrace_settings "{\"url\":\"http://127.0.0.1:9000\",\"write_key\":\"cdp_wk_smoke\",\"secret_key\":\"cdp_sk_smoke\"}" --format=json >/dev/null
wp onetrace test
wp eval "\$p=new WC_Product_Simple();\$p->set_props([\"name\"=>\"Smoke\",\"regular_price\"=>\"10\",\"status\"=>\"publish\"]);\$p->save();\$o=wc_create_order();\$o->add_product(\$p,1);\$o->set_billing_email(\"smoke@example.com\");\$o->calculate_totals();\$o->save();do_action(\"woocommerce_checkout_order_processed\",\$o->get_id(),[],\$o);echo \$o->get_id();"
echo
wp onetrace flush
php -r "foreach (file(\"/tmp/receiver.log\") as \$l) { \$r=json_decode(\$l,true); echo \$r[\"method\"],\" \",\$r[\"path\"],\" \",substr(\$r[\"auth\"],0,20),\" \",implode(\",\",array_map(function(\$m){return \$m[\"event\"]??\$m[\"type\"];}, \$r[\"body\"][\"batch\"]??[])),PHP_EOL; }"
wp plugin deactivate onetrace-smoke >/dev/null && rm -rf /var/www/html/wp-content/plugins/onetrace-smoke
wp plugin activate onetrace-woocommerce >/dev/null
'
