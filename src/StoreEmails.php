<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Exception\AuthenticationException;
use OneTrace\Exception\NotFoundException;
use OneTrace\Exception\PermissionException;
use OneTrace\Resource\Emails;

/**
 * Store emails through OneTrace: every email of wp_mail() — WooCommerce, WordPress, other plugins — is sent through
 * the email integration of the project when the "Store emails" section of the platform says so
 * (GET /api/v1/emails/settings, cached for 5 minutes):
 *
 * - an order email with the OneTrace template on: the order data (OrderEmails), OneTrace builds the email in the
 *   customer's language; the store's own email goes along and is sent as is if the template was switched off since;
 * - "relay" on: the email as the store made it (subject, HTML, text, reply-to, sender name, attachments);
 * - emails to the shop owner and staff (admin_* kinds, the admin email and the recipients of the WooCommerce emails
 *   to the store, Cc and Bcc copies) always go out natively: OneTrace would make customer profiles of them;
 * - otherwise, and whenever OneTrace does not accept the email (no integration, the quota, the limit per recipient,
 *   the platform unavailable): the store sends it itself — only to the recipients OneTrace did not accept.
 *
 * The kind of the email (order_placed, password_reset, admin_new_order …) comes from the WooCommerce email being
 * sent (woocommerce_mail_callback_params) or the WordPress notification filters; anything else is "other".
 */
final class StoreEmails
{
    public const SETTINGS_TRANSIENT = 'onetrace_email_settings';

    public const PROJECT_TRANSIENT = 'onetrace_project_uid';

    /** customer_on_hold_order was sent: the processing email that follows is the payment. */
    public const ON_HOLD_META = '_onetrace_on_hold_emailed';

    private const MAX_ATTACHMENTS = 5;

    private const MAX_BYTES = 5242880;

    /** Attachment types the platform accepts, by the file extension. */
    private const TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'ics' => 'text/calendar',
    ];

    /** WooCommerce emails and their kinds; the processing, completed and admin emails are decided in kind(). */
    private const KINDS = [
        'customer_on_hold_order' => 'order_placed',
        'customer_partial_shipped_order' => 'order_shipped',
        'customer_updated_tracking_order' => 'order_shipped',
        'customer_refunded_order' => 'order_refunded',
        'customer_partially_refunded_order' => 'order_refunded',
        'customer_cancelled_order' => 'order_cancelled',
        'customer_failed_order' => 'order_failed',
        'customer_invoice' => 'invoice',
        'customer_note' => 'order_note',
        'customer_reset_password' => 'password_reset',
        'customer_new_account' => 'account_new',
    ];

    /** @var array{email: \WC_Email, to: list<string>}|null the WooCommerce email being sent */
    private static $current;

    /** @var array{kind: string, to: list<string>, user: \WP_User|null}|null a WordPress notification about to be sent */
    private static $pending;

    /** @var bool the native send to the recipients OneTrace did not accept */
    private static $bypass = false;

    /** @var bool order emails resent from the order screen: not a repeat to drop */
    private static $resend = false;

    /** @var array<int, true> */
    private static $onHold = [];

    public static function register(): void
    {
        add_filter('pre_wp_mail', [self::class, 'intercept'], 10, 2);
        add_filter('woocommerce_mail_callback_params', [self::class, 'track'], PHP_INT_MAX, 2);
        add_action('woocommerce_email_sent', [self::class, 'untrack']);
        add_action('woocommerce_before_resend_order_emails', [self::class, 'resending']);
        add_action('woocommerce_after_resend_order_email', [self::class, 'resent']);

        $notifications = [
            'wp_new_user_notification_email_admin' => 'admin_new_user',
            'wp_password_change_notification_email' => 'admin_password_changed',
            'password_change_email' => 'password_changed',
            'email_change_email' => 'email_changed',
        ];

        foreach ($notifications as $filter => $kind) {
            add_filter($filter, static function ($email) use ($kind) {
                return self::expect($email, $kind);
            }, PHP_INT_MAX);
        }

        add_filter('retrieve_password_notification_email', static function ($email, $key = '', $login = '', $user = null) {
            return self::expect($email, 'password_reset', $user);
        }, PHP_INT_MAX, 4);
        add_filter('wp_new_user_notification_email', static function ($email, $user = null) {
            return self::expect($email, 'account_new', $user);
        }, PHP_INT_MAX, 2);
    }

    /**
     * Settings of the store emails section, cached; null — not available (no secret key, no permission, the platform
     * does not answer): the store sends everything itself.
     *
     * @return array{relay: bool, integration_id: int|null, templates: array<string, bool>}|null
     */
    public static function settings(): ?array
    {
        if (!Settings::server()) {
            return null;
        }

        $cached = self::cached();

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        try {
            $settings = Connection::client('emails')->emails()->settings();
        } catch (\Throwable $error) {
            self::unavailable($error);

            return null;
        }

        set_transient(self::SETTINGS_TRANSIENT, $settings, 5 * MINUTE_IN_SECONDS);

        return $settings;
    }

    /**
     * The cached settings: null — not read yet, false — not available.
     *
     * @return array{relay: bool, integration_id: int|null, templates: array<string, bool>}|false|null
     */
    public static function cached()
    {
        $cached = get_transient(self::SETTINGS_TRANSIENT);

        if (!\is_array($cached)) {
            return null;
        }

        if (isset($cached['error'])) {
            return false;
        }

        return [
            'relay' => ($cached['relay'] ?? false) === true,
            'integration_id' => is_numeric($cached['integration_id'] ?? null) ? (int) $cached['integration_id'] : null,
            'templates' => array_map('boolval', (array) ($cached['templates'] ?? [])),
        ];
    }

    /** Why the settings are not available, for the settings page. */
    public static function error(): ?string
    {
        $cached = get_transient(self::SETTINGS_TRANSIENT);

        return \is_array($cached) && isset($cached['error']) ? (string) $cached['error'] : null;
    }

    /** The settings are read again: the plugin settings were saved. */
    public static function forget(): void
    {
        delete_transient(self::SETTINGS_TRANSIENT);
        delete_transient(self::PROJECT_TRANSIENT);
    }

    /**
     * Short id of the store in idempotency keys: two stores of one project never share a key.
     */
    public static function site(): string
    {
        return substr(md5(home_url('/')), 0, 8);
    }

    /**
     * woocommerce_mail_callback_params: the WooCommerce email being sent.
     *
     * @param mixed $params
     * @param mixed $email
     *
     * @return mixed
     */
    public static function track($params, $email = null)
    {
        if (\is_array($params) && $email instanceof \WC_Email) {
            self::$current = ['email' => $email, 'to' => self::lower(self::addresses($params[0] ?? ''))];

            if ($email->id === 'customer_on_hold_order' && $email->object instanceof \WC_Order && !isset(self::$onHold[$email->object->get_id()])) {
                self::$onHold[$email->object->get_id()] = true;
                $fresh = wc_get_order($email->object->get_id());

                if ($fresh instanceof \WC_Order) {
                    $fresh->update_meta_data(self::ON_HOLD_META, (string) time());
                    $fresh->save_meta_data();
                }
            }
        }

        return $params;
    }

    public static function untrack(): void
    {
        self::$current = null;
    }

    public static function resending(): void
    {
        self::$resend = true;
    }

    public static function resent(): void
    {
        self::$resend = false;
    }

    /**
     * A WordPress notification filter: the next email to these recipients is of this kind.
     *
     * @param mixed $email
     * @param mixed $user
     *
     * @return mixed
     */
    public static function expect($email, string $kind, $user = null)
    {
        if (\is_array($email)) {
            self::$pending = ['kind' => $kind, 'to' => self::lower(self::addresses($email['to'] ?? '')), 'user' => $user instanceof \WP_User ? $user : null];
        }

        return $email;
    }

    /**
     * pre_wp_mail: true — OneTrace accepted the email for every recipient; null — WordPress sends it; the result of
     * the native send when only some recipients were accepted.
     *
     * @param mixed $return
     * @param mixed $atts
     *
     * @return mixed
     */
    public static function intercept($return, $atts)
    {
        if ($return !== null || self::$bypass || !\is_array($atts) || !Settings::server()) {
            return $return;
        }

        try {
            $email = self::identify($atts);

            // Emails to the shop owner and staff never go through OneTrace: no customer profiles for staff addresses.
            if (strpos($email['kind'], 'admin_') === 0) {
                return null;
            }

            $settings = self::settings();

            if ($settings === null) {
                return null;
            }

            $route = Emails::route($settings, $email['kind']);

            if ($route === Emails::TEMPLATE && $email['order'] === null) {
                $route = $settings['relay'] ? Emails::RELAY : Emails::NATIVE;
            }

            $message = $route !== Emails::NATIVE ? self::message($atts, $email['wc']) : null;
        } catch (\Throwable $error) {
            Queue::log('Store email: ' . $error->getMessage());

            return null;
        }

        if ($message === null) {
            return null;
        }

        $native = [];
        $staff = self::staff();

        foreach ($message['to'] as $address) {
            if (\in_array(strtolower($address), $staff, true) || !self::deliver($route, $email, $message, $address)) {
                $native[] = $address;
            }
        }

        // Copies (Cc, Bcc) go to the store's staff: the store sends them itself.
        foreach ($message['copies'] as $address) {
            $native[] = $address;
        }

        if ($native === []) {
            return true;
        }

        if (\count($native) === \count($message['to']) + \count($message['copies'])) {
            return null;
        }

        return self::native($atts, $native);
    }

    /**
     * The kind of the WooCommerce email. The processing email is the payment when the on-hold email (bank transfer,
     * cheque) came before it, otherwise the order confirmation of an order paid at once or paid on delivery; the
     * completed email is the shipment (as order_shipped of the plugin), for virtual orders order_completed.
     */
    public static function kind(\WC_Email $email, ?\WC_Order $order): string
    {
        $id = (string) $email->id;

        if (isset(self::KINDS[$id])) {
            return self::KINDS[$id];
        }

        if ($id === 'customer_processing_order') {
            return $order !== null && self::onHoldBefore($order) ? 'order_paid' : 'order_placed';
        }

        if ($id === 'customer_completed_order') {
            return $order === null || Shipments::ships($order) ? 'order_shipped' : 'order_completed';
        }

        if (!$email->is_customer_email()) {
            $id = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($id)), '_');

            // Kinds are up to 40 characters.
            preg_match('/^.{1,40}/', strpos($id, 'admin_') === 0 ? $id : 'admin_' . $id, $kind);

            return rtrim($kind[0] ?? 'admin', '_');
        }

        return 'other';
    }

    /**
     * Email addresses of a recipient list ("a@example.com, Anna <b@example.com>" or an array).
     *
     * @param mixed $value
     *
     * @return list<string>
     */
    public static function addresses($value): array
    {
        $parts = \is_array($value) ? $value : explode(',', (string) $value);
        $result = [];

        foreach ($parts as $part) {
            if (!\is_string($part)) {
                continue;
            }

            $part = trim($part);

            if (preg_match('/<([^>]+)>\s*$/', $part, $match) === 1) {
                $part = trim($match[1]);
            }

            if ($part !== '' && is_email($part) !== false && !\in_array(strtolower($part), self::lower($result), true)) {
                $result[] = $part;
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $atts
     *
     * @return array{kind: string, order: \WC_Order|null, user: \WP_User|null, wc: \WC_Email|null, part: string, extra: array<string, mixed>}
     */
    private static function identify(array $atts): array
    {
        $to = self::lower(self::addresses($atts['to'] ?? ''));
        $email = ['kind' => 'other', 'order' => null, 'user' => null, 'wc' => null, 'part' => '', 'extra' => []];

        if (self::$current !== null && self::$current['to'] === $to) {
            $wc = self::$current['email'];
            $email['wc'] = $wc;
            $email['order'] = $wc->object instanceof \WC_Order ? $wc->object : null;
            $email['user'] = $wc->object instanceof \WP_User ? $wc->object : null;
            $email['kind'] = self::kind($wc, $email['order']);

            if ($email['kind'] === 'order_refunded' && $email['order'] !== null) {
                $refund = self::refund($wc instanceof \WC_Email_Customer_Refunded_Order ? $wc->refund : null) ?? ($email['order']->get_refunds()[0] ?? null);

                if ($refund instanceof \WC_Order_Refund) {
                    $email['part'] = (string) $refund->get_id();
                    $email['extra'] = ['refund_amount' => (float) $refund->get_amount(), 'reason' => (string) $refund->get_reason()];
                }
            }
        } elseif (self::$pending !== null && self::$pending['to'] === $to) {
            $email['kind'] = self::$pending['kind'];
            $email['user'] = self::$pending['user'];
        }

        self::$pending = null;

        /**
         * Kind of an email sent through OneTrace: order_placed, order_paid, order_shipped, order_delivered,
         * order_cancelled, order_refunded (built from OneTrace templates when they are on), password_reset,
         * account_new, email_verification, login_link, login_code (access links: no click tracking, the body is not
         * kept), or any other name of [a-z0-9_] up to 40 characters.
         *
         * @param string $kind
         * @param array<string, mixed> $atts arguments of wp_mail()
         * @param \WC_Email|null $wc the WooCommerce email being sent
         */
        $kind = apply_filters('onetrace_woocommerce_email_kind', $email['kind'], $atts, $email['wc']);
        $email['kind'] = preg_match('/^[a-z0-9_]{1,40}$/', (string) $kind) === 1 ? (string) $kind : 'other';

        return $email;
    }

    /**
     * The email as wp_mail() would send it; null when OneTrace cannot carry it (no recipient or subject, an
     * attachment of another type, more than 5 attachments or 5 MB): then the store sends it.
     *
     * @param array<mixed> $atts
     *
     * @return array{to: list<string>, copies: list<string>, subject: string, html: string, text: string|null, from_name: string|null, reply_to: string|null, attachments: list<array{name: string, type: string, content: string}>}|null
     */
    private static function message(array $atts, ?\WC_Email $wc): ?array
    {
        $to = self::addresses($atts['to'] ?? '');
        $subject = trim((string) ($atts['subject'] ?? ''));
        $body = (string) ($atts['message'] ?? '');
        $headers = self::headers($atts['headers'] ?? []);
        $attachments = self::attachments($atts['attachments'] ?? []);

        if ($to === [] || $subject === '' || trim($body) === '' || $attachments === null) {
            return null;
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the core filter, as wp_mail() applies it.
        $type = strtolower($headers['content-type'] ?? (string) apply_filters('wp_mail_content_type', 'text/plain'));
        $text = null;

        if (strpos($type, 'text/html') === false && strpos($type, 'multipart/') === false) {
            $text = $body;
            $body = self::html($body);
        } elseif ($wc !== null && strpos($type, 'multipart/') !== false) {
            $text = trim((string) $wc->get_content_plain()) ?: null;
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the core filter, as wp_mail() applies it.
        $fromName = $headers['from-name'] ?? trim((string) apply_filters('wp_mail_from_name', 'WordPress'));

        return [
            'to' => $to,
            'copies' => array_values(array_diff($headers['copies'], $to)),
            'subject' => $subject,
            'html' => $body,
            'text' => $text,
            'from_name' => $fromName !== '' && $fromName !== 'WordPress' ? wp_specialchars_decode($fromName, ENT_QUOTES) : null,
            'reply_to' => $headers['reply-to'] ?? null,
            'attachments' => $attachments,
        ];
    }

    /**
     * Sends the email to one recipient; false — OneTrace did not accept it.
     *
     * @param array{kind: string, order: \WC_Order|null, user: \WP_User|null, wc: \WC_Email|null, part: string, extra: array<string, mixed>} $email
     * @param array{to: list<string>, copies: list<string>, subject: string, html: string, text: string|null, from_name: string|null, reply_to: string|null, attachments: list<array{name: string, type: string, content: string}>} $message
     */
    private static function deliver(string $route, array $email, array $message, string $address): bool
    {
        $order = $email['order'];
        $key = null;
        $content = ['subject' => $message['subject'], 'html' => $message['html'], 'text' => $message['text'], 'attachments' => $message['attachments'] ?: null];

        if ($route === Emails::TEMPLATE && $order !== null) {
            // Parcels with tracking get their own shipped emails from the shipment hooks.
            if ($email['kind'] === 'order_shipped' && Settings::enabled('orders') && Shipments::shipments($order) !== []) {
                return true;
            }

            $key = OrderEmails::key($order, $email['kind'], $email['part']);
            // The store's email goes along: OneTrace sends it as is if the template was switched off since.
            $payload = OrderEmails::payload($order, $email['kind'], $address, $email['extra']) + $content;
            $idempotency = $key . (self::$resend ? '-' . time() : '');
        } else {
            $payload = ['to' => $address, 'kind' => $email['kind']] + $content + ['reply_to' => $message['reply_to'], 'from_name' => $message['from_name']] + self::person($email, $address);
            $idempotency = 'wc-' . self::site() . '-' . substr(hash('sha256', implode("\n", [
                $email['kind'],
                strtolower($address),
                $message['subject'],
                $message['html'],
                implode(',', array_column($message['attachments'], 'name')),
                // The same email again within 10 minutes is a repeat, unless resent from the order screen.
                self::$resend ? uniqid('', true) : (string) intdiv(time(), 600),
            ])), 0, 40);
        }

        try {
            Connection::client('emails')->emails()->send(array_filter($payload, static function ($value): bool {
                return $value !== null;
            }), $idempotency);
        } catch (\Throwable $error) {
            Queue::log(sprintf('Store email %s was sent by the store: %s', $email['kind'], $error->getMessage()));

            if ($error instanceof AuthenticationException || $error instanceof PermissionException) {
                self::unavailable($error);
            }

            return false;
        }

        if ($key !== null && \in_array($email['kind'], ['order_shipped', 'order_delivered'], true)) {
            OrderEmails::remember($order, $key);
        }

        return true;
    }

    /**
     * Language and first name of the recipient when the email goes to the customer of the order or to the user.
     *
     * @param array{kind: string, order: \WC_Order|null, user: \WP_User|null, wc: \WC_Email|null, part: string, extra: array<string, mixed>} $email
     *
     * @return array{language?: string|null, traits?: array<string, string>|null}
     */
    private static function person(array $email, string $address): array
    {
        $address = strtolower($address);

        if ($email['order'] !== null && strtolower((string) $email['order']->get_billing_email()) === $address) {
            $firstName = trim((string) $email['order']->get_billing_first_name());

            return ['language' => OrderEmails::language($email['order']), 'traits' => $firstName !== '' ? ['first_name' => $firstName] : null];
        }

        $user = $email['user'];

        if ($user !== null && strtolower((string) $user->user_email) === $address) {
            $firstName = trim((string) $user->first_name);

            return ['language' => \OneTrace\Commerce\Customer::languageTag(get_user_locale($user)), 'traits' => $firstName !== '' ? ['first_name' => $firstName] : null];
        }

        return [];
    }

    /**
     * WordPress sends the email to the recipients OneTrace did not accept (as To, without Cc and Bcc).
     *
     * @param array<mixed> $atts
     * @param list<string> $recipients
     */
    private static function native(array $atts, array $recipients): bool
    {
        $headers = $atts['headers'] ?? [];
        $headers = array_values(array_filter(\is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", (string) $headers)), static function ($line): bool {
            return \is_string($line) && trim($line) !== '' && preg_match('/^\s*b?cc\s*:/i', $line) !== 1;
        }));

        self::$bypass = true;

        try {
            return wp_mail($recipients, (string) ($atts['subject'] ?? ''), (string) ($atts['message'] ?? ''), $headers, $atts['attachments'] ?? []);
        } finally {
            self::$bypass = false;
        }
    }

    /**
     * @param mixed $headers
     *
     * @return array{content-type?: string, from-name?: string, reply-to?: string, copies: list<string>}
     */
    private static function headers($headers): array
    {
        $lines = \is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", (string) $headers));
        $result = ['copies' => []];

        foreach ($lines as $line) {
            if (!\is_string($line) || strpos($line, ':') === false) {
                continue;
            }

            [$name, $value] = array_map('trim', explode(':', $line, 2));

            switch (strtolower($name)) {
                case 'content-type':
                    $result['content-type'] = $value;

                    break;
                case 'from':
                    if (preg_match('/^(.*?)\s*<[^>]+>$/', $value, $match) === 1 && trim($match[1], " \"'") !== '') {
                        $result['from-name'] = trim($match[1], " \"'");
                    }

                    break;
                case 'reply-to':
                    $address = self::addresses($value)[0] ?? null;

                    if ($address !== null) {
                        $result['reply-to'] = $address;
                    }

                    break;
                case 'cc':
                case 'bcc':
                    $result['copies'] = array_values(array_unique(array_merge($result['copies'], self::addresses($value))));

                    break;
            }
        }

        return $result;
    }

    /**
     * Attachments of the email; null when one of them cannot go through OneTrace.
     *
     * @param mixed $attachments
     *
     * @return list<array{name: string, type: string, content: string}>|null
     */
    private static function attachments($attachments): ?array
    {
        $attachments = \is_array($attachments) ? $attachments : explode("\n", str_replace("\r\n", "\n", (string) $attachments));
        $result = [];
        $bytes = 0;

        foreach ($attachments as $name => $path) {
            $path = trim((string) $path);

            if ($path === '') {
                continue;
            }

            $filename = \is_string($name) && trim($name) !== '' ? trim($name) : wp_basename($path);
            $type = self::TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? null;
            $size = is_file($path) && is_readable($path) ? filesize($path) : false;

            if ($type === null || $size === false || \count($result) >= self::MAX_ATTACHMENTS || ($bytes += $size) > self::MAX_BYTES) {
                return null;
            }

            $contents = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local attachment of wp_mail().

            if ($contents === false) {
                return null;
            }

            $result[] = Emails::attachment($filename, $type, $contents);
        }

        return $result;
    }

    /**
     * HTML of a plain text email (WordPress notifications): the text as it is, links clickable.
     */
    private static function html(string $text): string
    {
        $text = (string) preg_replace('#<(https?://[^>\s]+)>#i', '$1', $text);

        return '<div style="white-space:pre-wrap">' . make_clickable(esc_html($text)) . '</div>';
    }

    /**
     * Addresses of the shop owner and staff: the site admin email and the recipients of the WooCommerce emails to the
     * store (new, cancelled, failed order …). They always get the store's own email.
     *
     * @return list<string>
     */
    private static function staff(): array
    {
        $addresses = [(string) get_option('admin_email')];

        try {
            foreach (WC()->mailer()->get_emails() as $wc) {
                if (!$wc->is_customer_email()) {
                    $addresses = array_merge($addresses, self::addresses((string) $wc->get_option('recipient', '')));
                }
            }
        } catch (\Throwable $error) {
            // The admin email is enough.
        }

        return self::lower(self::addresses($addresses));
    }

    /**
     * The refund of a refund email (WooCommerce declares it as an order).
     *
     * @param mixed $refund
     */
    private static function refund($refund): ?\WC_Order_Refund
    {
        return $refund instanceof \WC_Order_Refund ? $refund : null;
    }

    private static function onHoldBefore(\WC_Order $order): bool
    {
        return isset(self::$onHold[$order->get_id()])
            || $order->get_meta(self::ON_HOLD_META, true) !== ''
            // Orders placed before this version: these gateways always go through on-hold.
            || \in_array($order->get_payment_method(), ['bacs', 'cheque'], true);
    }

    private static function unavailable(\Throwable $error): void
    {
        $lasting = $error instanceof AuthenticationException || $error instanceof PermissionException || $error instanceof NotFoundException;
        $class = explode('\\', \get_class($error));

        set_transient(self::SETTINGS_TRANSIENT, ['error' => mb_substr(end($class) . ': ' . $error->getMessage(), 0, 500)], $lasting ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS);
    }

    /**
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    private static function lower(array $addresses): array
    {
        return array_map('strtolower', $addresses);
    }
}
