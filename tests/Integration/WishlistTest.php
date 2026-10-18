<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\WooCommerce\Settings;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * Wishlist events from YITH WooCommerce Wishlist and TI WooCommerce Wishlist, both installed by tests/env/run.sh.
 */
final class WishlistTest extends TestCase
{
    /** @var list<int> */
    private $users = [];

    protected function tearDown(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ($this->users as $user) {
            wp_delete_user($user);
        }

        parent::tearDown();
    }

    public function testYithWishlistAddsAndRemovesFromTheServer(): void
    {
        if (!\function_exists('yith_wcwl_wishlists')) {
            self::markTestSkipped('YITH WooCommerce Wishlist is not active.');
        }

        $product = $this->product(['sale_price' => '39.95']);
        $user = $this->user();
        $_COOKIE['cdp_aid'] = 'browser-1';

        $added = yith_wcwl_wishlists()->add_item(['product_id' => $product->get_id()]);
        yith_wcwl_wishlists()->remove_item(['product_id' => $product->get_id(), 'wishlist_id' => $added['wishlist_id']]);
        $this->flush();

        [$add] = $this->platform->events('add_to_wishlist');
        [$remove] = $this->platform->events('remove_from_wishlist');

        self::assertSame(['userId' => (string) $user, 'anonymousId' => 'browser-1'], array_intersect_key($add, ['userId' => 1, 'anonymousId' => 1]));
        self::assertSame(['product_id' => (string) $product->get_id(), 'product_name' => 'Sneakers', 'price' => 39.95, 'currency' => get_woocommerce_currency()], array_intersect_key($add['properties'], ['product_id' => 1, 'product_name' => 1, 'price' => 1, 'currency' => 1]));
        self::assertArrayNotHasKey('quantity', $add['properties']);
        self::assertSame((string) $product->get_id(), $remove['properties']['product_id']);
        self::assertContract($add);
        self::assertContract($remove);
    }

    public function testTiWishlistAddsAndRemovesByTheButtonAndOnTheWishlistPage(): void
    {
        if (!class_exists(\TInvWL_Product::class)) {
            self::markTestSkipped('TI WooCommerce Wishlist is not active.');
        }

        $product = $this->product();
        $user = $this->user();
        $wishlist = (new \TInvWL_Wishlist())->add_user_default($user);
        $items = new \TInvWL_Product($wishlist);

        // The button on the product page toggles the product.
        $items->add_product(['product_id' => $product->get_id()]);
        $items->remove_product_from_wl((int) $wishlist['ID'], $product->get_id());
        // The remove button of the wishlist page removes the row.
        $row = $items->add_product(['product_id' => $product->get_id()]);
        $rows = $items->get_wishlist(['ID' => $row]);
        $items->remove((array) array_shift($rows));
        $this->flush();

        $added = $this->platform->events('add_to_wishlist');
        $removed = $this->platform->events('remove_from_wishlist');

        self::assertCount(2, $added);
        self::assertCount(2, $removed);

        foreach (array_merge($added, $removed) as $message) {
            self::assertSame((string) $user, $message['userId']);
            self::assertSame((string) $product->get_id(), $message['properties']['product_id']);
            self::assertContract($message);
        }
    }

    public function testAVariationIsTheProductWithItsVariantAndAGuestNeedsTheTrackerCookie(): void
    {
        $product = new \WC_Product_Variable();
        $product->set_props(['name' => 'Runner', 'status' => 'publish']);
        $product->save();
        $this->remember($product->get_id());
        $variation = new \WC_Product_Variation();
        $variation->set_props(['parent_id' => $product->get_id(), 'regular_price' => '59.00']);
        $variation->save();

        // A guest without the tracker cookie cannot be linked to a profile.
        do_action('yith_wcwl_added_to_wishlist', $variation->get_id(), 1, 0);
        $_COOKIE['cdp_aid'] = 'browser-2';
        do_action('yith_wcwl_added_to_wishlist', $variation->get_id(), 1, 0);
        do_action('tinvwl_wishlist_product_removed_from_wishlist', 1, $product->get_id(), $variation->get_id());
        $this->flush();

        [$add] = $this->platform->events('add_to_wishlist');
        [$remove] = $this->platform->events('remove_from_wishlist');

        self::assertCount(1, $this->platform->events('add_to_wishlist'));
        self::assertSame(['anonymousId' => 'browser-2'], array_intersect_key($add, ['userId' => 1, 'anonymousId' => 1]));

        foreach ([$add, $remove] as $message) {
            self::assertSame([(string) $product->get_id(), (string) $variation->get_id(), 59], [$message['properties']['product_id'], $message['properties']['variant_id'], $message['properties']['price']]);
            self::assertContract($message);
        }
    }

    public function testTheOwnerOfTheWishlistIsSentWhenAnotherUserChangesItAndTheSettingTurnsItOff(): void
    {
        $product = $this->product();
        $owner = $this->user();
        $this->user();
        $_COOKIE['cdp_aid'] = 'browser-of-the-manager';

        do_action('yith_wcwl_removed_from_wishlist', $product->get_id(), 1, $owner);
        update_option(Settings::OPTION, ['wishlist' => 'no'] + (array) get_option(Settings::OPTION));
        do_action('yith_wcwl_added_to_wishlist', $product->get_id(), 1, $owner);
        $this->flush();

        [$remove] = $this->platform->events('remove_from_wishlist');

        self::assertSame(['userId' => (string) $owner], array_intersect_key($remove, ['userId' => 1, 'anonymousId' => 1]));
        self::assertSame([], $this->platform->events('add_to_wishlist'));
    }

    /**
     * A signed-in customer.
     */
    private function user(): int
    {
        $login = 'wishlist' . wp_rand();
        $user = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.com', 'role' => 'customer']);
        self::assertIsInt($user);
        $this->users[] = $user;
        wp_set_current_user($user);

        return $user;
    }
}
