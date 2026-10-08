<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\Customer;
use OneTrace\Commerce\LineItem;

/**
 * Cart events from the server: they work with AJAX buttons, the Cart block and page caching alike. Sent only when
 * the visitor can be linked to a profile: the tracker cookie or a signed-in customer.
 */
final class Cart
{
    /** Cart contents for which checkout_started was already sent in this session. */
    private const SESSION_KEY = 'onetrace_checkout_hash';

    public static function register(): void
    {
        add_action('woocommerce_add_to_cart', [self::class, 'added'], 20, 4);
        add_action('woocommerce_cart_item_removed', [self::class, 'removed'], 20, 2);
        add_action('template_redirect', [self::class, 'checkout']);
    }

    /**
     * @param string $key
     * @param int|string $productId
     * @param int|string $quantity
     * @param int|string $variationId
     */
    public static function added($key, $productId, $quantity, $variationId = 0): void
    {
        $customer = self::customer();
        $product = wc_get_product((int) ($variationId ?: $productId));

        if ($customer === null || !$product instanceof \WC_Product) {
            return;
        }

        Queue::event(Connection::messages()->product('add_to_cart', $customer, self::line($product, (int) $productId, (int) $quantity), get_woocommerce_currency(), wc_get_cart_url()));
    }

    /**
     * @param string $key
     * @param mixed $cart
     */
    public static function removed($key, $cart): void
    {
        $customer = self::customer();
        $item = $cart instanceof \WC_Cart ? ($cart->removed_cart_contents[$key] ?? null) : null;

        if ($customer === null || !\is_array($item)) {
            return;
        }

        $product = wc_get_product((int) (($item['variation_id'] ?? 0) ?: ($item['product_id'] ?? 0)));

        if ($product instanceof \WC_Product) {
            Queue::event(Connection::messages()->product('remove_from_cart', $customer, self::line($product, (int) $item['product_id'], (int) ($item['quantity'] ?? 1)), get_woocommerce_currency()));
        }
    }

    /**
     * The checkout page is opened: once per cart contents in the session.
     */
    public static function checkout(): void
    {
        if (!is_checkout() || is_wc_endpoint_url('order-received') || is_wc_endpoint_url('order-pay') || WC()->cart === null || WC()->cart->is_empty()) {
            return;
        }

        $customer = self::customer();
        $hash = WC()->cart->get_cart_hash();

        if ($customer === null || WC()->session === null || WC()->session->get(self::SESSION_KEY) === $hash) {
            return;
        }

        $lines = [];

        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;

            if ($product instanceof \WC_Product) {
                $lines[] = self::line($product, (int) $item['product_id'], (int) $item['quantity'], (float) $item['line_total'] / max(1, (int) $item['quantity']));
            }
        }

        if ($lines === []) {
            return;
        }

        WC()->session->set(self::SESSION_KEY, $hash);
        Queue::event(Connection::messages()->checkoutStarted($customer, $lines, (float) WC()->cart->get_total('edit'), get_woocommerce_currency(), wc_get_cart_url()));
    }

    private static function customer(): ?Customer
    {
        if (!Settings::enabled('cart')) {
            return null;
        }

        $userId = get_current_user_id();
        $anonymousId = Connection::anonymousId();

        return $userId || $anonymousId !== null ? new Customer($userId ? (string) $userId : null, $anonymousId) : null;
    }

    private static function line(\WC_Product $product, int $productId, int $quantity, ?float $price = null): LineItem
    {
        $parent = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : null;

        return new LineItem(
            $productId ?: $product->get_id(),
            $parent instanceof \WC_Product ? $parent->get_name() : $product->get_name(),
            $price ?? (float) wc_get_price_to_display($product),
            max(1, $quantity),
            $product->get_parent_id() ? $product->get_id() : null,
            Catalog::categoryId($product)
        );
    }
}
