<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\WooCommerce\Loyalty;
use OneTrace\WooCommerce\LoyaltyAccount;
use OneTrace\WooCommerce\PromoSync;
use OneTrace\WooCommerce\Referrals;
use OneTrace\WooCommerce\Settings;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * Promo codes and referrals: codes of a pool become coupons with the discount and restrictions of the template coupon
 * (by the cursor, expiry updated, coupons of the store untouched); the friend's code of the registration goes to the
 * platform and is repeated while the profile is unknown; the account shows the referral link and active codes.
 */
final class PromoTest extends TestCase
{
    /** @var list<array<string, mixed>> codes of the pool after the cursor */
    private $codes = [];

    /** @var int status of the referral answer */
    private $referStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        update_option(Settings::OPTION, ['loyalty' => 'yes', 'referral_field' => 'yes', 'promo_pools' => "welcome = WELCOME-TEMPLATE\nmissing = NO-SUCH-COUPON"] + (array) get_option(Settings::OPTION));
        delete_option('onetrace_promo_cursors');

        foreach (['WELCOME-TEMPLATE', 'WEL-AAAA', 'WEL-BBBB', 'STORE-OWN'] as $code) {
            if (($id = wc_get_coupon_id_by_code($code)) > 0) {
                wp_delete_post($id, true);
            }
        }

        // wc_get_coupon_id_by_code() caches ids of the deleted coupons.
        \WC_Cache_Helper::invalidate_cache_group('coupons');

        $template = new \WC_Coupon();
        $template->set_props(['code' => 'WELCOME-TEMPLATE', 'discount_type' => 'percent', 'amount' => 10, 'minimum_amount' => 50, 'product_categories' => [3]]);
        $template->save();

        $own = new \WC_Coupon();
        $own->set_props(['code' => 'STORE-OWN', 'amount' => 5]);
        $own->save();

        $this->platform->responder = function (Request $request): ?Response {
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);
            parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $query);

            if ($path === '/api/v1/promo/pools/welcome/codes') {
                $since = (string) ($query['since'] ?? '');

                return $this->json(200, ['data' => $since === 'c2' ? [] : $this->codes, 'next' => $since === 'c2' ? 'c2' : 'c2']);
            }

            if ($path === '/api/v1/promo/pools/missing/codes') {
                return $this->json(404, ['message' => 'Not found']);
            }

            if ($path === '/api/v1/loyalty/referrals') {
                return $this->json($this->referStatus, $this->referStatus === 422 ? ['message' => 'Own code', 'errors' => ['code' => ['Own code']]] : ($this->referStatus === 404 ? ['message' => 'Not found'] : ['status' => 'pending', 'rewarded' => true]));
            }

            if (preg_match('#/loyalty/profiles/user_id/\d+/transactions$#', $path) === 1) {
                return $this->json(200, ['data' => [['id' => 9, 'type' => 'earn', 'points' => 200, 'reference' => 'referral:4', 'reason' => null, 'rule' => null, 'referral' => 'referrer', 'created_at' => '2026-10-01T10:00:00.000Z']], 'next_cursor' => null]);
            }

            if (preg_match('#/loyalty/profiles/user_id/\d+$#', $path) === 1) {
                return $this->json(200, ['member' => true, 'balance' => 200, 'pending' => 0, 'tier' => null, 'expiring' => 0, 'expires_at' => null,
                    'referral_code' => 'K7M2QX9A', 'referral_url' => 'https://shop.example/?ref=K7M2QX9A', 'referrals' => ['invited' => 2, 'rewarded' => 1, 'points' => 200]]);
            }

            if (preg_match('#/promo/profiles/user_id/\d+/codes$#', $path) === 1) {
                return $this->json(200, ['data' => [['pool' => 'welcome', 'code' => 'WEL-AAAA', 'status' => 'issued', 'active' => true, 'expires_at' => '2026-11-30T00:00:00Z'], ['pool' => 'welcome', 'code' => 'WEL-OLD', 'status' => 'used', 'active' => false]]]);
            }

            return null;
        };
    }

    public function testCodesOfAPoolBecomeCouponsOfTheTemplate(): void
    {
        $this->codes = [
            ['code' => 'WEL-AAAA', 'status' => 'available', 'expires_at' => null],
            ['code' => 'WEL-BBBB', 'status' => 'issued', 'expires_at' => '2026-11-30T00:00:00Z'],
            ['code' => 'STORE-OWN', 'status' => 'available', 'expires_at' => null],
        ];

        self::assertSame(['welcome' => 2], PromoSync::synchronize());

        $coupon = new \WC_Coupon('wel-bbbb');
        self::assertSame(['percent', '10', '50', [3], 1, 1, 'welcome'], [$coupon->get_discount_type(), (string) $coupon->get_amount(), (string) $coupon->get_minimum_amount(), $coupon->get_product_categories(), $coupon->get_usage_limit(), $coupon->get_usage_limit_per_user(), $coupon->get_meta(PromoSync::META)]);
        self::assertSame('2026-11-30', $coupon->get_date_expires() !== null ? $coupon->get_date_expires()->date('Y-m-d') : null);
        self::assertSame('5', (string) (new \WC_Coupon('STORE-OWN'))->get_amount(), 'A coupon of the store keeps its settings.');
        self::assertSame(['welcome' => 'c2'], get_option('onetrace_promo_cursors'));

        // The next run continues from the cursor: nothing new.
        self::assertSame(['welcome' => 0], PromoSync::synchronize());
    }

    public function testTheFriendsCodeOfTheRegistrationIsSentAndRepeatedWhileTheProfileIsUnknown(): void
    {
        $_POST[Referrals::FIELD] = ' k7m2qx9a ';
        Referrals::created(77);
        unset($_POST[Referrals::FIELD]);

        self::assertTrue(as_has_scheduled_action(Referrals::HOOK, ['77', 'K7M2QX9A', 1], 'onetrace'));

        $this->referStatus = 404;
        Referrals::send('77', 'K7M2QX9A', 1);
        self::assertSame(['profile' => ['type' => 'user_id', 'value' => '77'], 'code' => 'K7M2QX9A'], $this->lastRequest('/api/v1/loyalty/referrals')['body']);
        self::assertTrue(as_has_scheduled_action(Referrals::HOOK, ['77', 'K7M2QX9A', 2], 'onetrace'));

        $this->referStatus = 422;
        Referrals::send('77', 'K7M2QX9A', 2);
        self::assertFalse(as_has_scheduled_action(Referrals::HOOK, ['77', 'K7M2QX9A', 3], 'onetrace'));
        as_unschedule_all_actions(Referrals::HOOK);
    }

    public function testTheAccountShowsTheReferralLinkAndActivePromoCodes(): void
    {
        $user = (int) wp_insert_user(['user_login' => 'promo' . wp_generate_password(6, false), 'user_pass' => 'secret', 'user_email' => wp_generate_password(6, false) . '@example.com']);
        wp_set_current_user($user);
        Loyalty::register();

        ob_start();
        LoyaltyAccount::render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('value="https://shop.example/?ref=K7M2QX9A"', $html);
        self::assertStringContainsString('<code>WEL-AAAA</code>', $html);
        self::assertStringNotContainsString('WEL-OLD', $html);
        self::assertStringNotContainsString('referral:4', $html);

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user);
    }

    /**
     * @return array{method: string, path: string, body: mixed, auth: string}
     */
    private function lastRequest(string $path): array
    {
        $found = array_values(array_filter($this->platform->requests, static function (array $request) use ($path): bool {
            return $request['path'] === $path;
        }));

        return $found[\count($found) - 1];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
