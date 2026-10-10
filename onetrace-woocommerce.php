<?php
/**
 * Plugin Name:          OneTrace for WooCommerce
 * Plugin URI:           https://wordpress.org/plugins/onetrace-for-woocommerce/
 * Description:          Connects the store to OneTrace.pro, the customer data platform: orders, customers and consents from the server, the website tracker, the product catalog, recommendation widgets and Web Push.
 * Version:              1.5.1
 * Requires at least:    6.6
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      11.2
 * Author:               OneTrace.pro
 * Author URI:           https://onetrace.pro
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          onetrace-for-woocommerce
 * Domain Path:          /languages
 */

defined('ABSPATH') || exit;

// Two copies of the plugin (the wordpress.org folder onetrace-for-woocommerce and an older zip from GitHub in
// onetrace-woocommerce) would declare the same classes: the copy loaded second stays off and asks to remove itself.
// Settings and the queue are shared, nothing is lost.
if (defined('ONETRACE_WC_FILE')) {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: folder of the active copy, 2: folder of the inactive copy */
                __('OneTrace for WooCommerce is installed twice: the copy in the %1$s folder works, the copy in the %2$s folder is not loaded. Deactivate and delete the copy in %2$s — settings stay.', 'onetrace-for-woocommerce'),
                basename(dirname(ONETRACE_WC_FILE)),
                basename(__DIR__)
            ))
        );
    });

    return;
}

define('ONETRACE_WC_VERSION', '1.5.1');
define('ONETRACE_WC_FILE', __FILE__);

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, [\OneTrace\WooCommerce\Queue::class, 'install']);
register_deactivation_hook(__FILE__, [\OneTrace\WooCommerce\Plugin::class, 'deactivate']);

// HPOS (custom order tables) and the Cart and Checkout blocks are supported.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ONETRACE_WC_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', ONETRACE_WC_FILE, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (class_exists('WooCommerce')) {
        \OneTrace\WooCommerce\Plugin::instance()->boot();
    }
});
