<?php
/**
 * Plugin Name:          OneTrace for WooCommerce
 * Plugin URI:           https://github.com/OneTracePro/onetrace-woocommerce
 * Description:          Connects the store to OneTrace.pro, the customer data platform: orders, customers and consents from the server, the website tracker, the product catalog, recommendation widgets and Web Push.
 * Version:              1.0.1
 * Requires at least:    6.6
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      11.2
 * Author:               OneTrace.pro
 * Author URI:           https://onetrace.pro
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          onetrace-woocommerce
 * Domain Path:          /languages
 */

defined('ABSPATH') || exit;

define('ONETRACE_WC_VERSION', '1.0.1');
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
