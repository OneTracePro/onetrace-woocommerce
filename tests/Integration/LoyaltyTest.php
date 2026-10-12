<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\WooCommerce\Loyalty;
use OneTrace\WooCommerce\LoyaltyAccount;
use OneTrace\WooCommerce\LoyaltyBlocks;
use OneTrace\WooCommerce\LoyaltyCart;
use OneTrace\WooCommerce\Settings;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * Bonus points at checkout: the offer, the hold and the discount fee, re-holding after the cart changes, removal,
 * the confirmation with the order number (and a retry when the platform is down), the blocks state and the account.
 */
final class LoyaltyTest extends TestCase
{
    /** @var int */
    private $customer;

    /** @var int */
    private $holds = 0;

    /** @var int confirmation answer status */
    private $confirmStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        update_option(Settings::OPTION, ['loyalty' => 'yes'] + (array) get_option(Settings::OPTION));
        Loyalty::register();

        $this->customer = (int) wp_insert_user(['user_login' => 'anna' . wp_generate_password(6, false), 'user_pass' => 'secret', 'user_email' => wp_generate_password(6, false) . '@example.com']);
        wp_set_current_user($this->customer);
        wc_load_cart();
        WC()->cart->empty_cart();
        Loyalty::forget();

        $this->platform->responder = function (Request $request): ?Response {
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);
            $body = json_decode((string) $request->getBody(), true);

            if ($path === '/api/v1/loyalty/calculate') {
                $max = (int) floor($body['amount'] * 0.3);

                return $this->json(200, ['member' => true, 'balance' => 500, 'tier' => 'Silver', 'max_points' => $max, 'max_discount' => $max, 'earn_points' => 4, 'point_value' => 1, 'currency' => 'EUR']);
            }

            if ($path === '/api/v1/loyalty/redemptions') {
                $this->holds++;

                return $this->json(201, ['id' => 'r' . $this->holds, 'status' => 'held', 'points' => $body['points'], 'discount' => (float) $body['points'], 'held_until' => gmdate('c', time() + 1800)]);
            }

            if (preg_match('#/confirm$#', $path) === 1) {
                return $this->json($this->confirmStatus, $this->confirmStatus === 200 ? ['id' => 'r1', 'status' => 'confirmed'] : ['message' => 'down']);
            }

            if (preg_match('#/loyalty/profiles/user_id/\d+/transactions$#', $path) === 1) {
                return $this->json(200, ['data' => [['id' => 7, 'type' => 'earn', 'points' => 120, 'reference' => 'A-1', 'reason' => null, 'rule' => null, 'created_at' => '2026-10-01T10:00:00.000Z']], 'next_cursor' => null]);
            }

            if (preg_match('#/loyalty/profiles/user_id/\d+$#', $path) === 1) {
                return $this->json(200, ['member' => true, 'balance' => 500, 'pending' => 40, 'tier' => 'Silver', 'tier_spend' => 1200, 'expiring' => 100, 'expires_at' => '2026-11-01T00:00:00.000Z', 'enrolled_at' => '2026-01-01T00:00:00.000Z']);
            }

            return null;
        };
    }

    protected function tearDown(): void
    {
        WC()->cart->empty_cart();
        Loyalty::forget();
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($this->customer);
        parent::tearDown();
    }

    public function testHoldsThePointsAndAppliesTheDiscountAsAFee(): void
    {
        WC()->cart->add_to_cart($this->product()->get_id(), 2);

        self::assertSame(29, Loyalty::offer()['max_points']);
        Loyalty::apply(25);
        WC()->cart->calculate_totals();

        $hold = $this->request('/api/v1/loyalty/redemptions');
        self::assertSame(['type' => 'user_id', 'value' => (string) $this->customer], $hold['body']['profile']);
        self::assertSame(25, $hold['body']['points']);
        self::assertSame(99.9, $hold['body']['amount']);
        self::assertStringStartsWith('wc:', $hold['body']['cart_id']);
        self::assertSame('Bearer cdp_sk_test', $hold['auth']);
        self::assertEqualsWithDelta(74.9, (float) WC()->cart->get_total('edit'), 0.001);
        self::assertSame(-25.0, (float) array_values(WC()->cart->get_fees())[0]->amount);
        self::assertStringContainsString('25', LoyaltyCart::content());
    }

    public function testHoldsAgainWhenTheCartShrinksAndRemovesThePoints(): void
    {
        $item = WC()->cart->add_to_cart($this->product()->get_id(), 2);
        Loyalty::apply(29);
        WC()->cart->set_quantity((string) $item, 1);
        WC()->cart->calculate_totals();

        // 49.95 × 30 % → at most 14 points: the hold is replaced with the allowed share.
        self::assertSame(14, Loyalty::hold()['points']);
        self::assertSame(2, $this->holds);
        self::assertEqualsWithDelta(35.95, (float) WC()->cart->get_total('edit'), 0.001);

        Loyalty::remove();
        WC()->cart->calculate_totals();

        self::assertNull(Loyalty::hold());
        self::assertSame([], WC()->cart->get_fees());
        self::assertNotNull($this->request('/api/v1/loyalty/redemptions/r2/cancel'));
    }

    public function testConfirmsTheRedemptionWithTheOrderNumberAndRetriesWhenThePlatformIsDown(): void
    {
        WC()->cart->add_to_cart($this->product()->get_id(), 2);
        Loyalty::apply(20);
        $order = $this->order($this->product());
        Loyalty::attach($order);
        $order->save();

        do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);

        self::assertSame(['order_id' => (string) $order->get_order_number()], $this->request('/api/v1/loyalty/redemptions/r1/confirm')['body']);
        self::assertNull(Loyalty::hold(), 'The cart forgets the hold after the order.');
        self::assertSame(20, (int) wc_get_order($order->get_id())->get_meta(Loyalty::META_POINTS));

        $this->confirmStatus = 503;
        WC()->cart->add_to_cart($this->product()->get_id(), 1);
        Loyalty::apply(10);
        $second = $this->order($this->product());
        Loyalty::attach($second);
        $second->save();
        do_action('woocommerce_store_api_checkout_order_processed', $second);

        self::assertTrue(as_has_scheduled_action(Loyalty::CONFIRM_HOOK, [$second->get_id(), 1], 'onetrace'));
        as_unschedule_all_actions(Loyalty::CONFIRM_HOOK, [$second->get_id(), 1], 'onetrace');
    }

    public function testAGuestGetsAHintAndNoOffer(): void
    {
        wp_set_current_user(0);
        WC()->cart->add_to_cart($this->product()->get_id(), 1);

        self::assertNull(Loyalty::offer());
        self::assertStringContainsString('Sign in', LoyaltyCart::content());
        self::assertArrayHasKey('link', (array) LoyaltyBlocks::state());
        self::assertSame([], array_filter($this->platform->requests, static function (array $request): bool {
            return strpos($request['path'], '/loyalty/') !== false;
        }));
    }

    public function testTheBlocksStateAndTheirUpdateCallback(): void
    {
        WC()->cart->add_to_cart($this->product()->get_id(), 2);

        $state = LoyaltyBlocks::state();
        self::assertSame(29, $state['max']);
        self::assertStringContainsString('500', $state['balance']);

        LoyaltyBlocks::update(['mode' => 'apply', 'points' => 10]);
        self::assertStringContainsString('10', (string) LoyaltyBlocks::state()['applied']);

        LoyaltyBlocks::update(['mode' => 'remove']);
        self::assertArrayNotHasKey('applied', (array) LoyaltyBlocks::state());
    }

    public function testTheAccountShowsTheBalanceTierExpiringPointsAndHistory(): void
    {
        ob_start();
        LoyaltyAccount::render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('500', $html);
        self::assertStringContainsString('Silver', $html);
        self::assertStringContainsString('+120', $html);
        self::assertStringContainsString('A-1', $html);
        self::assertArrayHasKey(LoyaltyAccount::ENDPOINT, LoyaltyAccount::menu(['orders' => 'Orders', 'customer-logout' => 'Log out']));
        self::assertSame('customer-logout', array_key_last(LoyaltyAccount::menu(['orders' => 'Orders', 'customer-logout' => 'Log out'])));
    }

    /**
     * @return array{method: string, path: string, body: mixed, auth: string}|null
     */
    private function request(string $path): ?array
    {
        foreach (array_reverse($this->platform->requests) as $request) {
            if ($request['path'] === $path) {
                return $request;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
