<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

/**
 * Cart links of the OneTrace AI assistants: a shopper's assistant (ChatGPT, Claude) makes a cart with the platform
 * and gives a link that leads to any page of the store with ?onetrace_cart=ID:QTY,ID:QTY. The products go into the
 * cart (guests too) and the visitor lands on the cart page with every other parameter of the link (utm_* …), so the
 * tracker records the visit with them.
 */
final class CartLinks
{
    /** GET parameter of the link: comma-separated pairs "catalog id:quantity". */
    public const PARAM = 'onetrace_cart';

    /** Pairs read from one link, the rest is ignored. */
    public const MAX_ITEMS = 20;

    public const MAX_QUANTITY = 99;

    public static function register(): void
    {
        // After the cart of the session is loaded (wp_loaded, 10) and with the add to cart handler of WooCommerce.
        add_action('wp_loaded', [self::class, 'open'], 20);
    }

    /**
     * A front-end GET request with the parameter: fills the cart and redirects to it (302, not cached); when
     * nothing could be added, to the same address without the parameter.
     */
    public static function open(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link from outside the store carries no nonce; it only adds products for sale to the visitor's own cart.
        if (!isset($_GET[self::PARAM]) || !self::frontEndGet()) {
            return;
        }

        $value = \is_string($_GET[self::PARAM]) ? sanitize_text_field(wp_unslash($_GET[self::PARAM])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $added = self::add($value);

        if ($added > 0 && WC()->session instanceof \WC_Session_Handler && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }

        nocache_headers();
        wp_safe_redirect(self::location(remove_query_arg(self::PARAM), $added > 0), 302, 'OneTrace');
        exit;
    }

    /**
     * Pairs of the link: split on the last colon (a SKU may contain one), at most MAX_ITEMS, the quantity an
     * integer clamped to 1…MAX_QUANTITY; pairs without an id or an integer quantity are ignored.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function parse(string $value): array
    {
        $pairs = array_slice(array_values(array_filter(array_map('trim', explode(',', $value)), static function (string $pair): bool {
            return $pair !== '';
        })), 0, self::MAX_ITEMS);
        $items = [];

        foreach ($pairs as $pair) {
            $colon = strrpos($pair, ':');
            $id = $colon !== false ? trim(substr($pair, 0, $colon)) : '';
            $quantity = $colon !== false ? trim(substr($pair, $colon + 1)) : '';

            if ($id !== '' && preg_match('/^[+-]?\d+$/', $quantity) === 1) {
                $items[] = [$id, max(1, min(self::MAX_QUANTITY, (int) $quantity))];
            }
        }

        return $items;
    }

    /**
     * Adds the products of the link to the cart: the number of pairs added. Unknown, unpublished, out of stock and
     * not purchasable products, variable products without a variation and adds WooCommerce rejects (stock
     * limits, the add to cart validation of plugins) are skipped.
     */
    public static function add(string $value): int
    {
        // Not loaded on this request yet: the cart of the visitor's session (a guest gets one on the first add).
        if (WC()->cart === null) {
            wc_load_cart();
        }

        $cart = WC()->cart;
        $added = 0;

        foreach (self::parse($value) as [$id, $quantity]) {
            $product = self::product($id);

            if ($product !== null && self::addProduct($cart, $product, $quantity) !== null) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * The redirect: the cart page with the other parameters of the request (those of the cart address itself,
     * e.g. page_id of plain permalinks, stay its own), or the requested address without the cart parameter.
     *
     * @param string $current the request URI without the cart parameter
     */
    public static function location(string $current, bool $added): string
    {
        $current = remove_query_arg(self::PARAM, $current);

        if (!$added) {
            // A path, never "//host" (wp_safe_redirect would take it for another site).
            return '/' . ltrim($current, '/');
        }

        $cart = wc_get_cart_url();
        wp_parse_str((string) wp_parse_url($current, PHP_URL_QUERY), $params);
        wp_parse_str((string) wp_parse_url($cart, PHP_URL_QUERY), $own);
        $params = array_diff_key($params, $own);

        return $params === [] ? $cart : add_query_arg(urlencode_deep($params), $cart);
    }

    /**
     * The product or variation of a catalog id: the SKU in the SKU mode (a variation SKU is the variation), a
     * numeric id without a matching SKU — the post id. Also the product ids of orders from AI assistants (StoreMcp).
     */
    public static function product(string $id): ?\WC_Product
    {
        $postId = Products::bySku() ? (int) wc_get_product_id_by_sku($id) : 0;

        if ($postId === 0 && ctype_digit($id)) {
            $postId = (int) $id;
        }

        $postId = $postId > 0 ? (int) apply_filters('woocommerce_add_to_cart_product_id', $postId) : 0;
        $product = $postId > 0 ? wc_get_product($postId) : null;

        return $product instanceof \WC_Product ? $product : null;
    }

    /**
     * Adds a product or variation for sale to the cart: the key of the cart line, null when it is skipped.
     */
    public static function addProduct(\WC_Cart $cart, \WC_Product $product, int $quantity): ?string
    {
        $variation = $product instanceof \WC_Product_Variation;
        $parent = $variation ? wc_get_product($product->get_parent_id()) : $product;

        if (!$parent instanceof \WC_Product || $parent->get_status() !== 'publish' || $product->get_status() !== 'publish') {
            return null;
        }

        // Simple-like products only: a variable, grouped or external product needs its page.
        if ((!$variation && !$product->supports('ajax_add_to_cart')) || !$product->is_purchasable() || !$product->is_in_stock()) {
            return null;
        }

        $productId = $parent->get_id();
        $variationId = $variation ? $product->get_id() : 0;
        // The attributes of the variation; an "any" attribute has no value, and WooCommerce rejects the add.
        $attributes = $variation ? wc_get_product_variation_attributes($variationId) : [];

        if (!apply_filters('woocommerce_add_to_cart_validation', true, $productId, $quantity, $variationId, $attributes)) {
            return null;
        }

        $key = $cart->add_to_cart($productId, $quantity, $variationId, $attributes);

        return \is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * A page of the storefront: not the admin, AJAX (also wc-ajax), WP-Cron, the REST API, XML-RPC or WP-CLI.
     */
    private static function frontEndGet(): bool
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD'])) : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only the presence of the parameters.
        $endpoint = isset($_GET['wc-ajax']) || isset($_GET['rest_route']);

        return $method === 'get'
            && !$endpoint
            && !is_admin()
            && !wp_doing_ajax()
            && !wp_doing_cron()
            && !(\defined('REST_REQUEST') && REST_REQUEST)
            && !(\defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            && !(\defined('WP_CLI') && WP_CLI)
            && !WC()->is_rest_api_request();
    }
}
