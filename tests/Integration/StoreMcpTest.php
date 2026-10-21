<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\StoreMcp\CustomerTokens;
use OneTrace\WooCommerce\Settings;
use OneTrace\WooCommerce\StoreMcp;
use OneTrace\WooCommerce\Tests\Support\Mailbox;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * MCP server of the store for the OneTrace AI chat bot: platform key, confirmation by a code from the shop, orders of
 * the confirmed customer (an account or guest orders by email), the cancellation only of unpaid orders.
 */
final class StoreMcpTest extends TestCase
{
    /** @var list<int> */
    private $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $settings = get_option(Settings::OPTION);
        update_option(Settings::OPTION, ['store_mcp' => 'yes', 'store_mcp_tools' => array_merge(StoreMcp::READ_TOOLS, ['store_cancel_order'])] + (\is_array($settings) ? $settings : []));
    }

    protected function tearDown(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ($this->users as $id) {
            wp_delete_user($id);
        }

        parent::tearDown();
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
        $meta = array_filter(['onetrace/customer_token' => $token, 'onetrace/confirmed' => $confirmed ?: null, 'onetrace/idempotency_key' => $confirmed ? 'k-' . $tool : null]);

        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments, '_meta' => $meta])['data']['result'];
    }

    public function testAnswersTheContractWithTheAllowedToolsForThePlatformKeyOnly(): void
    {
        $init = $this->rpc('initialize')['data']['result'];
        self::assertSame(['version' => 1], $init['capabilities']['experimental']['onetrace/store']);
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

    public function testPassesTheSignedInCustomerToTheChatBot(): void
    {
        $user = $this->user('boris');
        wp_set_current_user($user);

        $token = StoreMcp::customerToken();

        self::assertNotNull($token);
        self::assertSame('user:' . $user, StoreMcp::tokens()->verify($token));
    }
}
