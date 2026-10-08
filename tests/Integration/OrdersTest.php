<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\WooCommerce\Customers;
use OneTrace\WooCommerce\Tests\TestCase;

final class OrdersTest extends TestCase
{
    public function testAPlacedGuestOrderIdentifiesTheCustomerWithConsentAndSendsTheOrder(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $order->update_meta_data(Customers::ORDER_META, 'yes');
        $order->save();

        do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
        $this->flush();

        [$identify] = $this->platform->events('identify');
        [$completed] = $this->platform->events('order_completed');

        self::assertSame(['email' => 'anna@example.com', 'phone' => '+4915112345678', 'first_name' => 'Anna', 'last_name' => 'Schmidt', 'city' => 'Berlin', 'country' => 'DE'], array_diff_key($identify['traits'], ['language' => 1]));
        self::assertSame([['channel' => 'email', 'status' => 'subscribed']], $identify['consents']);
        self::assertSame($identify['anonymousId'], $completed['anonymousId'], 'The guest order lands in the profile of the identify.');
        self::assertSame((string) $order->get_order_number(), $completed['properties']['order_id']);
        self::assertSame(99.9, $completed['properties']['amount']);
        self::assertSame([['product_id' => (string) $product->get_id(), 'product_name' => 'Sneakers', 'price' => 49.95, 'quantity' => 2, 'category_id' => (string) get_option('default_product_cat')]], $completed['properties']['products']);
        self::assertSame('Bearer cdp_sk_test', $this->platform->requests[0]['auth']);

        foreach ($this->platform->messages() as $message) {
            self::assertContract($message);
        }
    }

    public function testSendsNoConsentWithoutTheCheckboxAndTheSameIdsForARepeatedOrder(): void
    {
        $order = $this->order($this->product());

        do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
        do_action('woocommerce_store_api_checkout_order_processed', $order);
        $this->flush();

        $completed = $this->platform->events('order_completed');

        self::assertArrayNotHasKey('consents', $this->platform->events('identify')[0]);
        self::assertCount(2, $completed);
        self::assertSame($completed[0]['messageId'], $completed[1]['messageId'], 'The platform drops the repeat by messageId.');
    }

    public function testFollowsPaymentCancellationAndRefunds(): void
    {
        $order = $this->order($this->product());

        $order->update_status('processing');
        $order->update_status('completed');
        $order->update_status('cancelled');
        $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => 20, 'reason' => 'Damaged']);
        $this->remember($refund->get_id());
        $this->flush();

        $paid = $this->platform->events('order_paid');
        $refunded = $this->platform->events('order_refunded');

        self::assertCount(1, $paid, 'processing → completed is not a second payment.');
        self::assertCount(1, $this->platform->events('order_cancelled'));
        self::assertEquals(20, $refunded[0]['properties']['amount']);
        self::assertStringEndsWith(':order_refunded:' . $refund->get_id(), $refunded[0]['messageId']);

        foreach ($this->platform->messages() as $message) {
            self::assertContract($message);
        }
    }

    public function testTreatsCashOnDeliveryAsPaidOnlyWhenCompleted(): void
    {
        $order = $this->order($this->product());
        $order->set_payment_method('cod');
        $order->set_status('processing');
        $order->save();

        do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
        $order->update_status('completed');
        $this->flush();

        self::assertCount(1, $this->platform->events('order_completed'));
        self::assertCount(1, $this->platform->events('order_paid'), 'Only the completed status means the money is received.');
    }

    public function testLinksTheOrderToTheBrowserAndTheAccount(): void
    {
        $userId = wp_insert_user(['user_login' => 'anna' . wp_rand(), 'user_email' => 'anna' . wp_rand() . '@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer']);
        $this->remember(0);
        $_COOKIE['cdp_aid'] = 'browser-1';
        $order = $this->order($this->product());
        $order->set_customer_id((int) $userId);
        do_action('woocommerce_checkout_create_order', $order, []);
        $order->save();

        do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
        $this->flush();
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user((int) $userId);

        $completed = $this->platform->events('order_completed')[0];

        self::assertSame((string) $userId, $completed['userId']);
        self::assertSame('browser-1', $completed['anonymousId']);
    }
}
