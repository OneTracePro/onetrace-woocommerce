<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\Customer;

/**
 * Wishlist events from the server: WooCommerce has no wishlist of its own, so the plugin follows the two most
 * popular wishlist plugins — YITH WooCommerce Wishlist and TI WooCommerce Wishlist. Themes with another wishlist
 * mark their buttons with data-cdp-wishlist / data-cdp-wishlist-remove for the tracker instead.
 *
 * The visitor is linked like in the cart events: the signed-in customer and the tracker cookie. When the owner of
 * the wishlist is another user (a shop manager edits it), only the owner's user id is sent.
 */
final class Wishlist
{
    public const ADDED = 'add_to_wishlist';

    public const REMOVED = 'remove_from_wishlist';

    public static function register(): void
    {
        // YITH WooCommerce Wishlist (includes/class-yith-wcwl-wishlists.php): product id (a variation id for a
        // chosen variation), wishlist id, user id of the wishlist (0 for guests).
        add_action('yith_wcwl_added_to_wishlist', [self::class, 'yithAdded'], 20, 3);
        add_action('yith_wcwl_removed_from_wishlist', [self::class, 'yithRemoved'], 20, 3);

        // TI WooCommerce Wishlist (includes/product.helper.php): add() passes the saved row, remove() — the removed
        // row (the wishlist page), remove_product_from_wl() — wishlist id, product id and variation id (the button
        // on the product, after adding to the cart).
        add_action('tinvwl_product_added', [self::class, 'tiAdded'], 20, 1);
        add_action('tinvwl_product_removed', [self::class, 'tiRemoved'], 20, 1);
        add_action('tinvwl_wishlist_product_removed_from_wishlist', [self::class, 'tiRemovedFromWishlist'], 20, 3);
    }

    /**
     * @param mixed $productId
     * @param mixed $wishlistId
     * @param mixed $userId
     */
    public static function yithAdded($productId, $wishlistId = 0, $userId = 0): void
    {
        self::send(self::ADDED, self::int($productId), 0, self::int($userId));
    }

    /**
     * @param mixed $productId
     * @param mixed $wishlistId
     * @param mixed $userId
     */
    public static function yithRemoved($productId, $wishlistId = 0, $userId = 0): void
    {
        self::send(self::REMOVED, self::int($productId), 0, self::int($userId));
    }

    /**
     * @param mixed $data row of the wishlist: product_id, variation_id, author, wishlist_id…
     */
    public static function tiAdded($data): void
    {
        if (\is_array($data)) {
            self::send(self::ADDED, self::int($data['product_id'] ?? 0), self::int($data['variation_id'] ?? 0), self::int($data['author'] ?? 0));
        }
    }

    /**
     * @param mixed $data the removed row; the REST API passes only its ID — then the product is unknown
     */
    public static function tiRemoved($data): void
    {
        if (\is_array($data)) {
            self::send(self::REMOVED, self::int($data['product_id'] ?? 0), self::int($data['variation_id'] ?? 0), self::int($data['author'] ?? 0));
        }
    }

    /**
     * @param mixed $wishlistId
     * @param mixed $productId
     * @param mixed $variationId
     */
    public static function tiRemovedFromWishlist($wishlistId, $productId = 0, $variationId = 0): void
    {
        self::send(self::REMOVED, self::int($productId), self::int($variationId), 0);
    }

    private static function send(string $event, int $productId, int $variationId, int $ownerId): void
    {
        $product = wc_get_product($variationId ?: $productId);
        $customer = self::customer($ownerId);

        if ($customer === null || !$product instanceof \WC_Product || $productId <= 0) {
            return;
        }

        Queue::event(Connection::messages()->product($event, $customer, Cart::line($product, 0, 1), get_woocommerce_currency()));
    }

    private static function customer(int $ownerId): ?Customer
    {
        if (!Settings::enabled('wishlist')) {
            return null;
        }

        $userId = get_current_user_id();

        if ($ownerId > 0 && $ownerId !== $userId) {
            return new Customer((string) $ownerId);
        }

        $anonymousId = Connection::anonymousId();

        return $userId || $anonymousId !== null ? new Customer($userId ? (string) $userId : null, $anonymousId) : null;
    }

    /**
     * @param mixed $value
     */
    private static function int($value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
