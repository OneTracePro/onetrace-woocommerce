<?php
/**
 * The product search page of classic themes with the OneTrace search widget: the theme's header, footer and
 * WooCommerce content wrappers; the results, facets and sorting are drawn by the tracker (SearchWidget::template()).
 */

defined('ABSPATH') || exit;

get_header('shop');

// The content wrappers and breadcrumbs of WooCommerce and the theme.
do_action('woocommerce_before_main_content');

echo \OneTrace\WooCommerce\SearchWidget::container(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in container().

do_action('woocommerce_after_main_content');

get_footer('shop');
