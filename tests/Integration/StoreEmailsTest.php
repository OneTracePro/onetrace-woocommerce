<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\WooCommerce\OrderEmails;
use OneTrace\WooCommerce\Shipments;
use OneTrace\WooCommerce\StoreEmails;
use OneTrace\WooCommerce\Tests\Support\Mailbox;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * Store emails through OneTrace: wp_mail() of WooCommerce, WordPress and other plugins goes to POST /api/v1/emails
 * as is (relay) or as order data for a OneTrace template; whatever OneTrace does not accept the store sends itself.
 */
final class StoreEmailsTest extends TestCase
{
    /** @var array<string, int> status of POST /api/v1/emails by recipient */
    private $failFor = [];

    /** @var int|null status of GET /api/v1/emails/settings, null — relay on */
    private $settingsStatus;

    /** @var list<int> */
    private $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->platform->responder = function (Request $request): ?Response {
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);

            if ($path === '/api/v1/emails/settings') {
                return $this->settingsStatus !== null
                    ? $this->json($this->settingsStatus, ['message' => 'Unavailable'])
                    : $this->json(200, ['relay' => true, 'integration_id' => 3, 'templates' => ['order_placed' => false]]);
            }

            if ($path === '/api/v1/emails') {
                $to = (string) (json_decode((string) $request->getBody(), true)['to'] ?? '');

                return isset($this->failFor[$to])
                    ? $this->json($this->failFor[$to], ['error' => 'quota_exceeded', 'message' => 'The message quota is used up'])
                    : $this->json(202, ['id' => 'm1', 'status' => 'queued']);
            }

            return null;
        };
    }

    protected function tearDown(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ($this->users as $id) {
            wp_delete_user($id);
        }

        as_unschedule_all_actions(OrderEmails::HOOK);
        delete_transient(StoreEmails::SETTINGS_TRANSIENT);
        parent::tearDown();
    }

    public function testWooCommerceEmailIsRelayedAsTheStoreMadeIt(): void
    {
        $this->storeEmails(true, []);
        $order = $this->order($this->product());

        WC()->mailer()->emails['WC_Email_Customer_Invoice']->trigger($order->get_id(), $order);

        $sent = $this->platform->to('POST', '/api/v1/emails');

        self::assertCount(1, $sent);
        $email = $sent[0]['body'];
        self::assertSame('anna@example.com', $email['to']);
        self::assertSame('invoice', $email['kind']);
        self::assertStringContainsString((string) $order->get_order_number(), $email['subject']);
        self::assertStringContainsString('Sneakers', $email['html']);
        self::assertSame(['first_name' => 'Anna'], $email['traits']);
        self::assertSame('en-US', $email['language']);
        self::assertSame(WC()->mailer()->emails['WC_Email_Customer_Invoice']->get_from_name(), $email['from_name']);
        self::assertArrayNotHasKey('order', $email, 'Relayed as is, not from a template.');
        self::assertMatchesRegularExpression('/^wc-[0-9a-f]{8}-[0-9a-f]{40}$/', $sent[0]['idempotency']);
        self::assertSame([], Mailbox::$sent, 'OneTrace accepted it: the store does not send it.');
    }

    public function testOrderTemplateSendsTheOrderDataInsteadOfTheEmail(): void
    {
        $this->storeEmails(false, ['order_placed']);
        $product = $this->product(['sku' => 'SNK-1']);
        $order = $this->order($product, 2, ['address_1' => 'Unter den Linden 1', 'postcode' => '10117']);

        $order->update_status('on-hold');

        $sent = $this->platform->to('POST', '/api/v1/emails');

        self::assertCount(1, $sent, 'The customer email only; the admin email is not relayed.');
        $email = $sent[0]['body'];
        self::assertSame('order_placed', $email['kind']);
        self::assertSame('anna@example.com', $email['to']);
        self::assertSame('en-US', $email['language']);
        self::assertSame(['first_name' => 'Anna'], $email['traits']);
        self::assertSame((string) $order->get_order_number(), $email['order']['order_id']);
        self::assertSame((string) $order->get_order_number(), $email['order']['number']);
        self::assertSame(99.9, $email['order']['amount']);
        self::assertSame(get_woocommerce_currency(), $email['order']['currency']);
        self::assertSame('Direct bank transfer', $email['order']['payment_method']);
        self::assertSame($order->get_checkout_order_received_url(), $email['order']['url'], 'A guest: the order received page.');
        self::assertSame(['name' => 'Anna Schmidt', 'address1' => 'Unter den Linden 1', 'city' => 'Berlin', 'postcode' => '10117', 'country' => 'Germany', 'phone' => '+49 151 1234-5678'], $email['order']['billing_address']);
        self::assertSame('SNK-1', $email['order']['products'][0]['sku']);
        self::assertSame('Sneakers', $email['order']['products'][0]['product_name']);
        self::assertSame(2, $email['order']['products'][0]['quantity']);
        self::assertSame(99.9, $email['order']['products'][0]['total']);
        self::assertSame($product->get_permalink(), $email['order']['products'][0]['url']);
        self::assertNotEmpty($email['html'], 'The store email goes along in case the template was switched off.');
        self::assertSame(OrderEmails::key($order, 'order_placed'), $sent[0]['idempotency']);
        self::assertSame([], Mailbox::to('anna@example.com'), 'The native email is not sent.');
        self::assertCount(1, Mailbox::to((string) get_option('admin_email')), 'The admin email goes out natively: relay is off.');

        // Payment of the bank transfer: the processing email is order_paid, its template is off — native.
        $order = wc_get_order($order->get_id());
        $order->update_status('processing');

        self::assertCount(1, $this->platform->to('POST', '/api/v1/emails'));
        self::assertCount(1, Mailbox::to('anna@example.com'));
    }

    public function testProcessingOrderPaidAtOnceIsTheOrderConfirmation(): void
    {
        $this->storeEmails(false, ['order_placed', 'order_paid']);
        $order = $this->order($this->product());
        $order->set_payment_method('stripe');
        $order->save();

        $order->update_status('processing');

        $sent = $this->platform->to('POST', '/api/v1/emails');

        self::assertCount(1, $sent);
        self::assertSame('order_placed', $sent[0]['body']['kind']);
    }

    public function testRefundTemplateCarriesTheAmountAndReason(): void
    {
        $this->storeEmails(false, ['order_refunded']);
        $order = $this->order($this->product(), 2);
        $order->update_status('processing');

        $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => 49.95, 'reason' => 'Wrong size']);
        $this->remember($refund->get_id());

        $sent = $this->platform->to('POST', '/api/v1/emails');

        self::assertCount(1, $sent);
        self::assertSame('order_refunded', $sent[0]['body']['kind']);
        self::assertSame(49.95, $sent[0]['body']['order']['refund_amount']);
        self::assertSame('Wrong size', $sent[0]['body']['order']['reason']);
        self::assertSame(OrderEmails::key($order, 'order_refunded', (string) $refund->get_id()), $sent[0]['idempotency']);
    }

    public function testStoreSendsTheEmailWhenOneTraceDoesNotAcceptIt(): void
    {
        $this->storeEmails(true, []);
        $this->failFor = ['anna@example.com' => 402];
        $order = $this->order($this->product());

        WC()->mailer()->emails['WC_Email_Customer_Invoice']->trigger($order->get_id(), $order);

        self::assertCount(1, $this->platform->to('POST', '/api/v1/emails'));
        self::assertCount(1, Mailbox::to('anna@example.com'), 'Not accepted: the store sends it itself.');

        // Two recipients, one not accepted: the store sends it only to that one.
        Mailbox::$sent = [];
        $this->failFor = ['bob@example.com' => 429];
        wp_mail('carl@example.com, Bob <bob@example.com>', 'Hello', '<p>Hi</p>', ['Content-Type: text/html; charset=UTF-8', 'Cc: shop@example.com']);

        $kinds = array_map(static function (array $request): array {
            return [$request['body']['to'], $request['body']['kind']];
        }, \array_slice($this->platform->to('POST', '/api/v1/emails'), 1));

        self::assertSame([['carl@example.com', 'other'], ['bob@example.com', 'other']], $kinds, 'One request per recipient; the copy is the store\'s own.');
        self::assertCount(1, Mailbox::$sent);
        self::assertSame(['bob@example.com', 'shop@example.com'], Mailbox::$sent[0]['to'], 'The recipient OneTrace did not accept and the copy.');
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], Mailbox::$sent[0]['headers']);
    }

    public function testAttachmentsOneTraceCannotCarryAreSentByTheStore(): void
    {
        $this->storeEmails(true, []);
        $pdf = (string) tempnam(get_temp_dir(), 'invoice');
        $zip = (string) tempnam(get_temp_dir(), 'photos');
        file_put_contents($pdf, '%PDF-1.4 test');
        file_put_contents($zip, 'PK');
        rename($pdf, $pdf .= '.pdf');
        rename($zip, $zip .= '.zip');

        try {
            wp_mail('anna@example.com', 'Your invoice', 'See the attachment.', [], [$pdf]);
            wp_mail('anna@example.com', 'Your photos', 'See the attachment.', [], [$zip]);
        } finally {
            unlink($pdf);
            unlink($zip);
        }

        $sent = $this->platform->to('POST', '/api/v1/emails');

        self::assertCount(1, $sent);
        self::assertSame([['name' => basename($pdf), 'type' => 'application/pdf', 'content' => base64_encode('%PDF-1.4 test')]], $sent[0]['body']['attachments']);
        self::assertSame('See the attachment.', $sent[0]['body']['text']);
        self::assertStringContainsString('See the attachment.', $sent[0]['body']['html']);
        self::assertCount(1, Mailbox::$sent, 'A zip file goes natively.');
        self::assertSame('Your photos', Mailbox::$sent[0]['subject']);
    }

    public function testPasswordResetEmailsOfWordPressAndWooCommerce(): void
    {
        $this->storeEmails(true, ['order_placed']);
        $userId = wp_insert_user(['user_login' => 'reset_' . wp_generate_password(6, false), 'user_email' => 'reset-' . wp_generate_password(6, false) . '@example.com', 'user_pass' => wp_generate_password(), 'first_name' => 'Rita']);
        self::assertIsInt($userId);
        $this->users[] = $userId;
        $user = get_userdata($userId);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        retrieve_password($user->user_login);
        WC()->mailer()->emails['WC_Email_Customer_Reset_Password']->trigger($user->user_login, 'reset-key-123');

        $sent = $this->platform->to('POST', '/api/v1/emails');

        self::assertCount(2, $sent);
        self::assertSame(['password_reset', 'password_reset'], array_column(array_column($sent, 'body'), 'kind'));
        self::assertSame($user->user_email, $sent[0]['body']['to']);
        self::assertSame(['first_name' => 'Rita'], $sent[0]['body']['traits']);
        self::assertStringContainsString('action=rp', $sent[0]['body']['text']);
        self::assertMatchesRegularExpression('#<a href="[^"]*action=rp[^"]*"#', $sent[0]['body']['html'], 'The link of the plain text email is clickable.');
        self::assertStringContainsString('reset-key-123', $sent[1]['body']['html']);
        self::assertSame([], Mailbox::$sent);
    }

    public function testEmailsToTheShopOwnerAreAlwaysSentByTheStore(): void
    {
        $this->storeEmails(true, ['order_placed', 'order_paid']);
        $order = $this->order($this->product());
        $admin = (string) get_option('admin_email');

        WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->get_id(), $order);
        WC()->mailer()->emails['WC_Email_Cancelled_Order']->trigger($order->get_id(), $order);
        $userId = wp_insert_user(['user_login' => 'staff_' . wp_generate_password(6, false), 'user_email' => 'staff-' . wp_generate_password(6, false) . '@example.com', 'user_pass' => wp_generate_password()]);
        self::assertIsInt($userId);
        $this->users[] = $userId;
        wp_password_change_notification(get_userdata($userId));
        wp_mail($admin . ', anna@example.com', 'Report', 'Text');

        $sent = $this->platform->to('POST', '/api/v1/emails');

        self::assertCount(1, $sent, 'Only the customer of the last email; no staff profiles on the platform.');
        self::assertSame('anna@example.com', $sent[0]['body']['to']);
        self::assertSame([], $this->platform->to('GET', '/api/v1/emails/settings'), 'Settings are cached by the test.');
        self::assertCount(4, Mailbox::to($admin), 'New order, cancelled order, password change and the report — native, relay or not.');
        self::assertSame([$admin], (array) Mailbox::$sent[3]['to'], 'The last email natively only to the admin.');
    }

    public function testSettingsAreCachedAndAFailureMeansNativeEmails(): void
    {
        delete_transient(StoreEmails::SETTINGS_TRANSIENT);

        wp_mail('anna@example.com', 'One', 'Text');
        wp_mail('anna@example.com', 'Two', 'Text');

        self::assertCount(1, $this->platform->to('GET', '/api/v1/emails/settings'));
        self::assertCount(2, $this->platform->to('POST', '/api/v1/emails'));
        self::assertSame(['relay' => true, 'integration_id' => 3, 'templates' => ['order_placed' => false]], StoreEmails::cached());

        StoreEmails::forget();
        $this->settingsStatus = 503;

        wp_mail('anna@example.com', 'Three', 'Text');
        wp_mail('anna@example.com', 'Four', 'Text');

        self::assertCount(2, $this->platform->to('GET', '/api/v1/emails/settings'), 'The failure is cached too.');
        self::assertCount(2, $this->platform->to('POST', '/api/v1/emails'));
        self::assertSame(['Three', 'Four'], array_column(Mailbox::$sent, 'subject'));
        self::assertFalse(StoreEmails::cached());
        self::assertStringStartsWith('ServerException', (string) StoreEmails::error());
    }

    public function testWithoutTheSecretKeyNothingChanges(): void
    {
        update_option('onetrace_settings', ['secret_key' => ''] + (array) get_option('onetrace_settings'));

        wp_mail('anna@example.com', 'Hello', 'Text');

        self::assertSame([], $this->platform->requests);
        self::assertCount(1, Mailbox::$sent);
    }

    public function testShippedTemplateEmailOncePerParcelAndNoCompletedEmail(): void
    {
        $this->storeEmails(false, ['order_shipped']);
        $order = $this->order($this->product());
        $order->update_status('processing');
        $parcel = ['tracking_provider' => '', 'custom_tracking_provider' => 'Hermes', 'custom_tracking_link' => 'https://tracking.example.com/H-123', 'tracking_number' => 'H-123', 'date_shipped' => (string) time(), 'tracking_id' => 'a1'];

        $this->track($order->get_id(), [$parcel]);
        $this->track($order->get_id(), [$parcel]);

        self::assertTrue(as_has_scheduled_action(OrderEmails::HOOK, [$order->get_id(), 'order_shipped', 'H-123', 1], 'onetrace'));
        self::assertCount(1, as_get_scheduled_actions(['hook' => OrderEmails::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING], 'ids'), 'Once per parcel.');

        OrderEmails::run($order->get_id(), 'order_shipped', 'H-123', 1);
        OrderEmails::run($order->get_id(), 'order_shipped', 'H-123', 1);
        Mailbox::$sent = [];
        $order = wc_get_order($order->get_id());
        $order->update_status('completed');

        $sent = $this->platform->to('POST', '/api/v1/emails');

        self::assertCount(1, $sent, 'The parcel once; the completed email is not sent, the parcel email told the customer.');
        self::assertSame('order_shipped', $sent[0]['body']['kind']);
        self::assertSame('Hermes', $sent[0]['body']['order']['carrier']);
        self::assertSame('H-123', $sent[0]['body']['order']['tracking_number']);
        self::assertSame('https://tracking.example.com/H-123', $sent[0]['body']['order']['tracking_url']);
        self::assertArrayNotHasKey('html', $sent[0]['body']);
        self::assertSame(OrderEmails::key($order, 'order_shipped', 'H-123'), $sent[0]['idempotency']);
        self::assertSame([], Mailbox::to('anna@example.com'));
    }

    public function testCompletedWithoutTrackingIsTheShippedTemplateEmailOnce(): void
    {
        $this->storeEmails(false, ['order_shipped']);
        $order = $this->order($this->product());
        $order->update_status('processing');
        Mailbox::$sent = [];

        $order->update_status('completed');

        $sent = $this->platform->to('POST', '/api/v1/emails');

        self::assertCount(1, $sent);
        self::assertSame('order_shipped', $sent[0]['body']['kind']);
        self::assertArrayNotHasKey('tracking_number', $sent[0]['body']['order']);
        self::assertSame(OrderEmails::key($order, 'order_shipped'), $sent[0]['idempotency']);
        self::assertFalse(as_has_scheduled_action(OrderEmails::HOOK, null, 'onetrace'), 'Shipments does not send it again.');
        self::assertSame([], Mailbox::to('anna@example.com'));
    }

    public function testDeliveredTemplateEmailFromTheDeliveredStatus(): void
    {
        add_filter('wc_order_statuses', [self::class, 'delivered']);

        try {
            $this->storeEmails(false, ['order_delivered']);
            $order = $this->order($this->product());
            $order->update_status('processing');
            $order->update_status('delivered');

            self::assertTrue(as_has_scheduled_action(OrderEmails::HOOK, [$order->get_id(), 'order_delivered', '', 1], 'onetrace'));
            OrderEmails::run($order->get_id(), 'order_delivered', '', 1);

            $sent = $this->platform->to('POST', '/api/v1/emails');

            self::assertCount(1, $sent);
            self::assertSame('order_delivered', $sent[0]['body']['kind']);

            // The template off: nothing is scheduled.
            $this->storeEmails(false, []);
            $other = $this->order($this->product());
            $other->update_status('processing');
            $other->update_status('delivered');

            self::assertFalse(as_has_scheduled_action(OrderEmails::HOOK, [$other->get_id(), 'order_delivered', '', 1], 'onetrace'));
        } finally {
            remove_filter('wc_order_statuses', [self::class, 'delivered']);
        }
    }

    /**
     * @param array<string, string> $statuses
     *
     * @return array<string, string>
     */
    public static function delivered(array $statuses): array
    {
        return $statuses + ['wc-delivered' => 'Delivered'];
    }

    /**
     * @param list<array<string, string>> $items
     */
    private function track(int $orderId, array $items): void
    {
        $order = wc_get_order($orderId);
        $order->update_meta_data(Shipments::TRACKING_META, $items);
        $order->save();
    }

    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
