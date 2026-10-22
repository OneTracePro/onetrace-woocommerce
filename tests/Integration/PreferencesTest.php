<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\WooCommerce\PreferencesAccount;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * My account → Newsletters: topics by channel from OneTrace, the choice sent back with unticked boxes as
 * unsubscribed, the pause, "Unsubscribe from everything" and a customer the platform does not know yet.
 */
final class PreferencesTest extends TestCase
{
    /** @var int */
    private $customer;

    /** @var int preferences answer status */
    private $status = 200;

    /** @var string */
    private $url = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = (int) wp_insert_user(['user_login' => 'anna' . wp_generate_password(6, false), 'user_pass' => 'secret', 'user_email' => wp_generate_password(6, false) . '@example.com']);
        wp_set_current_user($this->customer);

        $this->platform->responder = function (Request $request): ?Response {
            if (preg_match('#/profiles/user_id/\d+/preferences$#', (string) parse_url($request->getUrl(), PHP_URL_PATH)) !== 1) {
                return null;
            }

            $this->url = $request->getUrl();

            return $this->status === 200 ? $this->json(200, [
                'profile_id' => 7,
                'language' => 'en-US',
                'channels' => [['key' => 'email', 'name' => 'email', 'blocked' => false], ['key' => 'sms', 'name' => 'SMS', 'blocked' => true]],
                'topics' => [
                    ['key' => 'news', 'name' => 'Store news', 'description' => 'Once a month', 'channels' => ['email' => true, 'sms' => false]],
                    ['key' => 'offers', 'name' => 'Special offers', 'description' => '', 'channels' => ['email' => false, 'sms' => null]],
                ],
                'frequency' => 'weekly',
                'paused_until' => null,
                'preferences_url' => 'https://crm.example.com/preferences/x',
                'preferences_url_expires_at' => '2026-11-21T00:00:00+00:00',
            ]) : $this->json($this->status, ['message' => 'Profile not found']);
        };
    }

    protected function tearDown(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($this->customer);
    }

    public function testTheTabShowsTopicsByChannelTheFrequencyAndTheMenuItem(): void
    {
        ob_start();
        PreferencesAccount::render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Store news', $html);
        self::assertStringContainsString('<th style="text-align:center">Email</th>', $html);
        self::assertMatchesRegularExpression('/name="topics\[news\]\[email\]" value="1"[^>]*checked/', $html);
        self::assertMatchesRegularExpression('/name="topics\[news\]\[sms\]" value="1"[^>]*disabled/', $html);
        self::assertStringNotContainsString('topics[offers][sms]', $html);
        self::assertMatchesRegularExpression('/value="weekly"\s+selected/', $html);
        self::assertStringContainsString('language=en-US', $this->url);
        self::assertSame('customer-logout', array_key_last(PreferencesAccount::menu(['orders' => 'Orders', 'customer-logout' => 'Log out'])));
    }

    public function testSavingSendsUntickedBoxesAsUnsubscribedAndSkipsBlockedChannels(): void
    {
        self::assertTrue(PreferencesAccount::save('save', ['offers' => ['email' => '1']], 'monthly', '30'));

        $body = $this->lastPut();
        self::assertSame(['news' => ['email' => false], 'offers' => ['email' => true]], $body['topics']);
        self::assertSame('monthly', $body['frequency']);
        self::assertEqualsWithDelta(time() + 30 * DAY_IN_SECONDS, strtotime((string) $body['paused_until']), 60);

        self::assertTrue(PreferencesAccount::save('save', [], 'default', 'keep'));
        self::assertNull($this->lastPut()['frequency']);
        self::assertArrayNotHasKey('paused_until', $this->lastPut());

        self::assertTrue(PreferencesAccount::save('all', [], 'default', 'keep'));
        self::assertTrue($this->lastPut()['unsubscribe_all']);
    }

    public function testACustomerThePlatformDoesNotKnowYetHasNothingToChoose(): void
    {
        $this->status = 404;

        ob_start();
        PreferencesAccount::render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('We do not send you newsletters yet.', $html);
        self::assertStringNotContainsString('<form', $html);

        $this->status = 403;

        ob_start();
        PreferencesAccount::render();
        self::assertStringContainsString('not available right now', (string) ob_get_clean());
        self::assertFalse(PreferencesAccount::save('all', [], 'default', 'keep'));
    }

    /**
     * @return array<string, mixed>
     */
    private function lastPut(): array
    {
        $puts = array_values(array_filter($this->platform->requests, static function (array $request): bool {
            return $request['method'] === 'PUT';
        }));

        return (array) end($puts)['body'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
