<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\CustomerTokens;
use OneTrace\WooCommerce\Settings;
use OneTrace\WooCommerce\StoreMcp;
use OneTrace\WooCommerce\Tests\Support\Mailbox;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * MCP server of the store for the OneTrace AI chat bot and AI shopping assistants: platform key, confirmation by a
 * code from the shop, orders of the confirmed customer (an account or guest orders by email), the cancellation only
 * of unpaid orders; contract v2 — the quote and the order of an assistant (customer or guest, idempotent).
 */
final class StoreMcpTest extends TestCase
{
    /** @var list<int> */
    private $users = [];

    /** @var \WC_Shipping_Zone|null */
    private $zone;

    /** @var array<string, mixed> gateway settings before the test */
    private $gateways = [];

    protected function setUp(): void
    {
        parent::setUp();
        $settings = get_option(Settings::OPTION);
        update_option(Settings::OPTION, ['store_mcp' => 'yes', 'store_mcp_tools' => array_merge(StoreMcp::READ_TOOLS, ['store_cancel_order'], StoreMcp::ORDER_TOOLS)] + (\is_array($settings) ? $settings : []));
    }

    protected function tearDown(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ($this->users as $id) {
            wp_delete_user($id);
        }

        if ($this->zone !== null) {
            $this->zone->delete();
        }

        foreach ($this->gateways as $option => $value) {
            $value === false ? delete_option($option) : update_option($option, $value);
        }

        WC()->payment_gateways()->init();
        parent::tearDown();
    }

    /**
     * Germany: a courier for 4.90 and free pickup; payment by bank transfer and cash on delivery.
     *
     * @return array{courier: string, pickup: string}
     */
    private function shop(): array
    {
        $this->zone = new \WC_Shipping_Zone();
        $this->zone->set_zone_name('OneTrace test Germany');
        $this->zone->add_location('DE', 'country');
        $this->zone->save();
        $courier = $this->zone->add_shipping_method('flat_rate');
        update_option('woocommerce_flat_rate_' . $courier . '_settings', ['enabled' => 'yes', 'title' => 'Courier', 'tax_status' => 'none', 'cost' => '4.90']);
        $pickup = $this->zone->add_shipping_method('free_shipping');
        update_option('woocommerce_free_shipping_' . $pickup . '_settings', ['enabled' => 'yes', 'title' => 'Pickup', 'requires' => '']);

        foreach (['woocommerce_bacs_settings' => ['enabled' => 'yes', 'title' => 'Bank transfer'], 'woocommerce_cod_settings' => ['enabled' => 'yes', 'title' => 'Cash on delivery'], 'woocommerce_cheque_settings' => ['enabled' => 'no']] as $option => $value) {
            $this->gateways[$option] = get_option($option);
            update_option($option, $value + (array) get_option($option, []));
        }

        WC()->payment_gateways()->init();

        return ['courier' => 'flat_rate:' . $courier, 'pickup' => 'free_shipping:' . $pickup];
    }

    /**
     * A tool call of an AI shopping assistant.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function assistant(string $tool, array $arguments, array $meta = []): array
    {
        $meta += ['onetrace/source' => 'assistant', 'onetrace/assistant' => 'claude', 'onetrace/language' => 'en'];

        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments, '_meta' => $meta])['data']['result'];
    }

    /**
     * A guest token of a new email confirmed with the code of purpose "order".
     */
    private function guest(string $email): string
    {
        Mailbox::$sent = [];
        self::assertTrue($this->assistant('store_start_verification', ['contact' => $email, 'purpose' => 'order'])['structuredContent']['sent']);
        self::assertSame(1, preg_match('/<strong>(\d{6})<\/strong>/', (string) (Mailbox::to($email)[0]['message'] ?? ''), $code), 'The shop sends the code also to an address without an account.');

        return $this->assistant('store_verify', ['contact' => $email, 'code' => $code[1]])['structuredContent']['customer_token'];
    }

    /**
     * @return array<string, string>
     */
    private static function address(): array
    {
        return ['first_name' => 'Lena', 'last_name' => 'Weber', 'phone' => '+49 30 1234567', 'country' => 'DE', 'city' => 'Berlin', 'postcode' => '10115', 'address' => 'Invalidenstr. 1'];
    }

    private function placedOrder(string $number): \WC_Order
    {
        $order = wc_get_order((int) apply_filters('woocommerce_shortcode_order_tracking_order_id', $number));
        self::assertInstanceOf(\WC_Order::class, $order);
        $this->remember($order->get_id());

        return $order;
    }

    private function user(string $name): int
    {
        $login = $name . '-' . strtolower(wp_generate_password(8, false));
        $id = wp_create_user($login, wp_generate_password(), $login . '@example.com');
        self::assertIsInt($id);
        $this->users[] = $id;

        return $id;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = [], ?string $key = null): array
    {
        $request = new \WP_REST_Request('POST', '/onetrace/v1/mcp');
        $request->set_header('Authorization', 'Bearer ' . ($key ?? StoreMcp::key()));
        $request->set_body((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));
        $response = StoreMcp::serve($request);

        return ['status' => $response->get_status(), 'data' => $response->get_data()];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments = [], ?string $token = null, bool $confirmed = false): array
    {
        $meta = array_filter(['onetrace/customer_token' => $token, 'onetrace/confirmed' => $confirmed ?: null, 'onetrace/idempotency_key' => $confirmed ? 'k-' . $tool . '-' . md5((string) json_encode($arguments)) : null]);

        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments, '_meta' => $meta])['data']['result'];
    }

    public function testAnswersTheContractWithTheAllowedToolsForThePlatformKeyOnly(): void
    {
        $init = $this->rpc('initialize')['data']['result'];
        self::assertSame(['version' => 2], $init['capabilities']['experimental']['onetrace/store']);
        self::assertContains('store_cancel_order', array_column($this->rpc('tools/list')['data']['result']['tools'], 'name'));
        self::assertNotContains('store_create_return', array_column($this->rpc('tools/list')['data']['result']['tools'], 'name'));
        self::assertSame(401, $this->rpc('tools/list', [], 'wrong-key')['status']);
    }

    public function testConfirmsAGuestByACodeByEmailAndShowsOnlyTheirOrders(): void
    {
        // A new address every run: the code limit (5 an hour) lives in transients of the test site.
        $guest = 'guest-' . strtolower(wp_generate_password(8, false)) . '@example.com';
        $product = $this->product();
        $mine = $this->order($product, 1, ['email' => $guest]);
        $other = $this->order($product, 1, ['email' => 'other@example.com']);

        self::assertSame('auth_required', $this->call('store_get_order', ['order' => $mine->get_order_number()])['structuredContent']['error']);

        $this->call('store_start_verification', ['contact' => $guest]);
        self::assertSame($guest, Mailbox::$sent[0]['to']);
        self::assertSame(1, preg_match('/<strong>(\d{6})<\/strong>/', (string) Mailbox::$sent[0]['message'], $code));

        $token = $this->call('store_verify', ['contact' => $guest, 'code' => $code[1]])['structuredContent']['customer_token'];
        $orders = $this->call('store_find_orders', [], $token)['structuredContent']['orders'];

        self::assertSame([$mine->get_order_number()], array_column($orders, 'order'));
        self::assertSame('pending', $orders[0]['status']);
        self::assertSame('not_found', $this->call('store_get_order', ['order' => $other->get_order_number()], $token)['structuredContent']['error']);
        self::assertSame(['amount' => round((float) $mine->get_total(), 2), 'currency' => $mine->get_currency()], $this->call('store_get_order', ['order' => $mine->get_order_number()], $token)['structuredContent']['total']);
    }

    public function testCancelsOnlyUnpaidOrdersOfTheCustomerAfterConsent(): void
    {
        $user = $this->user('anna');
        $token = (new CustomerTokens((string) get_option(StoreMcp::SECRET_OPTION)))->issue('user:' . $user);
        $unpaid = $this->order($this->product());
        $unpaid->set_customer_id($user);
        $unpaid->save();

        self::assertSame('not_allowed', $this->call('store_cancel_order', ['order' => $unpaid->get_order_number()], $token)['structuredContent']['error']);

        $done = $this->call('store_cancel_order', ['order' => $unpaid->get_order_number()], $token, true)['structuredContent'];
        self::assertSame('cancelled', $done['status']);
        self::assertTrue(wc_get_order($unpaid->get_id())->has_status('cancelled'));

        $paid = $this->order($this->product());
        $paid->set_customer_id($user);
        $paid->set_status('processing');
        $paid->save();
        self::assertSame('not_allowed', $this->call('store_cancel_order', ['order' => $paid->get_order_number()], $token, true)['structuredContent']['error']);
    }

    public function testOrderToolsAreListedOnlyWhenTheOwnerAllowsThem(): void
    {
        self::assertContains('store_create_order', array_column($this->rpc('tools/list')['data']['result']['tools'], 'name'));

        update_option(Settings::OPTION, ['store_mcp_tools' => StoreMcp::READ_TOOLS] + (array) get_option(Settings::OPTION));
        $tools = array_column($this->rpc('tools/list')['data']['result']['tools'], 'name');

        self::assertNotContains('store_quote_order', $tools);
        self::assertNotContains('store_create_order', $tools);
        self::assertSame('not_allowed', $this->assistant('store_quote_order', ['items' => [['product_id' => '1', 'quantity' => 1]]])['structuredContent']['error']);
    }

    public function testQuotesItemsShippingOfTheAddressAndPaymentMethods(): void
    {
        $methods = $this->shop();
        $sneakers = $this->product();
        $socks = $this->product(['name' => 'Socks', 'regular_price' => '5.00', 'sku' => 'socks-' . wp_generate_password(6, false)]);
        $last = $this->product(['name' => 'Last pair', 'manage_stock' => true, 'stock_quantity' => 1]);

        $quote = $this->assistant('store_quote_order', [
            'items' => [['product_id' => (string) $sneakers->get_id(), 'quantity' => 2], ['product_id' => (string) $socks->get_id(), 'quantity' => 3], ['product_id' => (string) $last->get_id(), 'quantity' => 2], ['product_id' => '999999999', 'quantity' => 1]],
            'shipping' => ['country' => 'DE', 'city' => 'Berlin', 'postcode' => '10115'],
            'shipping_method' => $methods['courier'],
        ])['structuredContent'];
        $currency = get_woocommerce_currency();

        // Equal, not identical: whole amounts come back from JSON as integers.
        self::assertEquals([
            ['product_id' => (string) $sneakers->get_id(), 'name' => 'Sneakers', 'quantity' => 2, 'price' => ['amount' => 49.95, 'currency' => $currency], 'total' => ['amount' => 99.9, 'currency' => $currency]],
            ['product_id' => (string) $socks->get_id(), 'name' => 'Socks', 'quantity' => 3, 'price' => ['amount' => 5.0, 'currency' => $currency], 'total' => ['amount' => 15.0, 'currency' => $currency]],
        ], $quote['items']);
        self::assertSame([(string) $last->get_id(), '999999999'], $quote['unavailable'], 'More than in stock and unknown products cannot be ordered.');
        self::assertSame(['amount' => 114.9, 'currency' => $currency], $quote['subtotal']);
        self::assertEquals(['amount' => 0, 'currency' => $currency], $quote['discount']);
        self::assertSame([$methods['courier'], $methods['pickup']], array_column($quote['shipping_methods'], 'id'));
        self::assertSame(['Courier', 'Pickup'], array_column($quote['shipping_methods'], 'name'));
        self::assertSame($methods['courier'], $quote['shipping_method']);
        self::assertSame(['amount' => 4.9, 'currency' => $currency], $quote['shipping_price']);
        self::assertSame(['amount' => 119.8, 'currency' => $currency], $quote['total']);
        self::assertSame(['bacs', 'cod'], array_column($quote['payment_methods'], 'id'));
        self::assertTrue(WC()->cart->is_empty(), 'The cart of the quote is temporary.');

        // Without a method — the first one; the cart of SKU mode takes the SKU.
        $settings = (array) get_option(Settings::OPTION);
        update_option(Settings::OPTION, ['product_id' => 'sku'] + $settings);
        $bySku = $this->assistant('store_quote_order', ['items' => [['product_id' => $socks->get_sku(), 'quantity' => 1]], 'shipping' => ['country' => 'DE']])['structuredContent'];
        self::assertSame([$socks->get_sku()], array_column($bySku['items'], 'product_id'));
        self::assertSame($methods['courier'], $bySku['shipping_method']);
        self::assertSame('invalid_input', $this->assistant('store_quote_order', ['items' => []])['structuredContent']['error']);
    }

    public function testPlacesAGuestOrderOnceForAnIdempotencyKeyWithThePaymentLinkAndTheAssistant(): void
    {
        $methods = $this->shop();
        $product = $this->product();
        $email = 'shopper-' . strtolower(wp_generate_password(8, false)) . '@example.com';
        $token = $this->guest($email);
        $order = ['items' => [['product_id' => (string) $product->get_id(), 'quantity' => 2]], 'shipping' => self::address(), 'shipping_method' => $methods['courier'], 'comment' => 'Ring twice'];
        $meta = ['onetrace/customer_token' => $token, 'onetrace/confirmed' => true, 'onetrace/idempotency_key' => 'order-' . $email];

        self::assertSame(['id' => null, 'email' => $email], $this->assistant('store_get_customer', [], ['onetrace/customer_token' => $token])['structuredContent']);
        self::assertSame('auth_required', $this->assistant('store_find_orders', [], ['onetrace/customer_token' => $token])['structuredContent']['error'], 'A guest token only places orders.');
        self::assertSame('not_allowed', $this->assistant('store_create_order', $order, ['onetrace/customer_token' => $token, 'onetrace/idempotency_key' => 'x'])['structuredContent']['error'], 'Only with the consent of the customer.');

        $placed = $this->assistant('store_create_order', $order, $meta)['structuredContent'];
        $wc = $this->placedOrder((string) $placed['order']);

        self::assertSame('pending', $placed['status']);
        self::assertSame('pending', $placed['payment_status']);
        self::assertSame(['amount' => 104.8, 'currency' => get_woocommerce_currency()], $placed['total']);
        self::assertSame($wc->get_checkout_payment_url(), $placed['payment_url']);
        self::assertSame(0, $wc->get_customer_id());
        self::assertSame($email, $wc->get_billing_email());
        self::assertSame(['Lena', 'Weber', 'DE', 'Berlin', '10115', 'Invalidenstr. 1', '+49 30 1234567'], [$wc->get_shipping_first_name(), $wc->get_shipping_last_name(), $wc->get_shipping_country(), $wc->get_shipping_city(), $wc->get_shipping_postcode(), $wc->get_shipping_address_1(), $wc->get_shipping_phone()]);
        self::assertSame('Courier', $wc->get_shipping_method());
        self::assertSame('Ring twice', $wc->get_customer_note());
        self::assertSame(['onetrace', 'assistant', 'claude', 'claude', 'ai_assistant'], [$wc->get_created_via(), $wc->get_meta(StoreMcp::SOURCE_META), $wc->get_meta(StoreMcp::ASSISTANT_META), $wc->get_meta('_wc_order_attribution_utm_source'), $wc->get_meta('_wc_order_attribution_utm_medium')]);
        self::assertStringContainsString('Claude', implode(' ', array_map(static function ($note): string {
            return (string) $note->content;
        }, wc_get_order_notes(['order_id' => $wc->get_id()]))));

        // A repeat: the same order, also when the result kept by the server is gone (the order meta).
        self::assertSame($placed, $this->assistant('store_create_order', $order, $meta)['structuredContent']);
        self::assertSame($placed, StoreMcp::createOrder($order, new Context(null, 'en', null, true, 'order-' . $email, $email, 'assistant', 'claude')));
        self::assertCount(1, wc_get_orders(['billing_email' => $email, 'limit' => 5]));

        $this->flush();
        $completed = $this->platform->events('order_completed');
        self::assertCount(1, $completed);
        self::assertSame((string) $wc->get_order_number(), $completed[0]['properties']['order_id']);
        self::assertSame('claude', $completed[0]['properties']['ai_assistant']);
        self::assertSame($email, $this->platform->events('identify')[0]['traits']['email']);

        foreach ($this->platform->messages() as $message) {
            self::assertContract($message);
        }

        $again = $this->assistant('store_create_order', $order, ['onetrace/idempotency_key' => 'another'] + $meta)['structuredContent'];
        $this->placedOrder((string) $again['order']);
        self::assertNotSame($placed['order'], $again['order'], 'Another key is another order.');
    }

    public function testACustomerPaysOnDeliveryAndChangedStockOrMethodsAreRejected(): void
    {
        $methods = $this->shop();
        $user = $this->user('clara');
        $token = StoreMcp::tokens()->issue('user:' . $user);
        $product = $this->product(['manage_stock' => true, 'stock_quantity' => 3]);
        $meta = ['onetrace/customer_token' => $token, 'onetrace/confirmed' => true, 'onetrace/assistant' => 'chatgpt'];
        $order = ['items' => [['product_id' => (string) $product->get_id(), 'quantity' => 2]], 'shipping' => self::address(), 'shipping_method' => $methods['pickup'], 'payment_method' => 'cod'];

        self::assertSame('invalid_input', $this->assistant('store_create_order', ['items' => [['product_id' => (string) $product->get_id(), 'quantity' => 4]]] + $order, ['onetrace/idempotency_key' => 'stock'] + $meta)['structuredContent']['error']);
        self::assertSame('invalid_input', $this->assistant('store_create_order', ['shipping_method' => 'flat_rate:999999'] + $order, ['onetrace/idempotency_key' => 'method'] + $meta)['structuredContent']['error']);
        self::assertSame('invalid_input', $this->assistant('store_create_order', ['shipping' => ['country' => 'DE', 'postcode' => 'ABC'] + self::address()] + $order, ['onetrace/idempotency_key' => 'postcode'] + $meta)['structuredContent']['error']);
        self::assertSame('invalid_input', $this->assistant('store_create_order', ['payment_method' => 'cheque'] + $order, ['onetrace/idempotency_key' => 'cheque'] + $meta)['structuredContent']['error']);

        $placed = $this->assistant('store_create_order', $order, ['onetrace/idempotency_key' => 'cod'] + $meta)['structuredContent'];
        $wc = $this->placedOrder((string) $placed['order']);

        self::assertSame(['processing', 'on_delivery', null], [$placed['status'], $placed['payment_status'], $placed['payment_url']]);
        self::assertSame(['amount' => 99.9, 'currency' => get_woocommerce_currency()], $placed['total']);
        self::assertSame($user, $wc->get_customer_id());
        self::assertSame('cod', $wc->get_payment_method());
        self::assertSame(1, wc_get_product($product->get_id())->get_stock_quantity(), 'Cash on delivery reduces the stock as at the checkout.');

        $this->flush();
        self::assertSame('chatgpt', $this->platform->events('order_completed')[0]['properties']['ai_assistant']);
        self::assertSame([], $this->platform->events('order_paid'), 'Cash on delivery is paid when the order is completed.');
    }

    public function testPassesTheSignedInCustomerToTheChatBot(): void
    {
        $user = $this->user('boris');
        wp_set_current_user($user);

        $token = StoreMcp::customerToken();

        self::assertNotNull($token);
        self::assertSame('user:' . $user, StoreMcp::tokens()->verify($token));
    }
}
