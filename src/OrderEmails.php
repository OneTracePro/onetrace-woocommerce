<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\Customer;
use OneTrace\Commerce\LineItem;
use OneTrace\Commerce\Retry;
use OneTrace\Exception\RateLimitException;
use OneTrace\Exception\ServerException;
use OneTrace\Exception\TransportException;
use OneTrace\Resource\Emails;

/**
 * Order emails built from OneTrace templates (POST /api/v1/emails with `order`): the order data of an email, and the
 * shipped and delivered emails that WooCommerce does not have. Those are sent in the background (Action Scheduler)
 * from the shipment hooks — once per parcel and once per delivery: the order keeps the idempotency keys of the sent
 * ones ("_onetrace_emails"), and the platform drops a repeated key too.
 */
final class OrderEmails
{
    public const HOOK = 'onetrace_order_email';

    /** Idempotency keys of the shipped and delivered template emails sent for the order. */
    public const SENT_META = '_onetrace_emails';

    /** Attempts of a background email: retries after 1, 5 and 30 minutes — a shipping notice a day later is no use. */
    private const ATTEMPTS = 4;

    /** @var array<string, true> keys sent in this request (the order meta may be read from a stale copy) */
    private static $sent = [];

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'run'], 10, 4);
    }

    /**
     * Schedules the template email of a parcel ($part — its tracking number or id, '' without tracking) or of the
     * delivery. Nothing when the cached settings have no template for the kind; settings not read yet — the job
     * decides, so the order save never waits for the platform.
     */
    public static function schedule(\WC_Order $order, string $kind, string $part = ''): void
    {
        if (!Settings::server() || !\function_exists('as_enqueue_async_action') || self::sent($order, self::key($order, $kind, $part))) {
            return;
        }

        $cached = StoreEmails::cached();

        if ($cached === false || \is_array($cached) && Emails::route($cached, $kind) !== Emails::TEMPLATE) {
            return;
        }

        as_enqueue_async_action(self::HOOK, [$order->get_id(), $kind, $part, 1], Queue::GROUP);
    }

    /**
     * Action Scheduler callback: sends a shipped or delivered email from the OneTrace template.
     *
     * @param int|string $orderId
     * @param mixed $kind
     * @param mixed $part
     * @param int|string $attempt
     */
    public static function run($orderId, $kind, $part = '', $attempt = 1): void
    {
        $order = wc_get_order((int) $orderId);
        $kind = (string) $kind;
        $part = (string) $part;

        if (!$order instanceof \WC_Order || !\in_array($kind, ['order_shipped', 'order_delivered'], true) || !Settings::server()) {
            return;
        }

        $key = self::key($order, $kind, $part);
        $to = (string) $order->get_billing_email();
        $settings = StoreEmails::settings();

        if ($settings === null) {
            // The platform is unavailable or the key lacks the permission: try again a little later.
            self::retry($order, $kind, $part, (int) $attempt);

            return;
        }

        if (Emails::route($settings, $kind) !== Emails::TEMPLATE || self::sent($order, $key) || !is_email($to)) {
            return;
        }

        $extra = $kind === 'order_shipped' ? self::shipment($order, $part) : [];

        if ($extra === null) {
            return;
        }

        try {
            Connection::client()->emails()->send(self::payload($order, $kind, $to, $extra), $key);
        } catch (\Throwable $error) {
            Queue::log(sprintf('Order email %s of order %s: %s', $kind, $order->get_order_number(), $error->getMessage()));

            if ($error instanceof TransportException || $error instanceof ServerException || $error instanceof RateLimitException) {
                self::retry($order, $kind, $part, (int) $attempt);
            }

            return;
        }

        self::remember($order, $key);
    }

    /**
     * The request of a template email: the order, the customer's language and first name (only when the email goes
     * to the customer of the order, not to a copy).
     *
     * @param array<string, mixed> $extra more order fields: carrier, tracking_number, tracking_url, refund_amount, reason …
     *
     * @return array<string, mixed>
     */
    public static function payload(\WC_Order $order, string $kind, string $to, array $extra = []): array
    {
        $customer = strtolower($to) === strtolower((string) $order->get_billing_email());
        $firstName = trim((string) $order->get_billing_first_name());

        return array_filter([
            'to' => $to,
            'kind' => $kind,
            'language' => $customer ? self::language($order) : null,
            'traits' => $customer && $firstName !== '' ? ['first_name' => $firstName] : null,
            'order' => self::data($order, $kind, $extra),
        ], static function ($value): bool {
            return $value !== null;
        });
    }

    /**
     * `order` of the email: the order with its lines (SKU, image, link, options), the totals, the payment and
     * shipping method as the customer sees them, the order page, the addresses and the customer's note.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function data(\WC_Order $order, string $kind, array $extra = []): array
    {
        $model = Orders::model($order)->with([
            'payment_method' => (string) $order->get_payment_method_title() ?: self::gatewayTitle($order),
            'shipping_method' => (string) $order->get_shipping_method(),
        ]);
        $model->lines = self::lines($order);

        return $model->emailData($extra + [
            'number' => $order->get_order_number(),
            'url' => $order->get_customer_id() > 0 ? $order->get_view_order_url() : $order->get_checkout_order_received_url(),
            'note' => $order->get_customer_note(),
            'payment_instructions' => $kind === 'order_placed' ? self::instructions($order) : null,
            'billing_address' => self::address($order, 'billing'),
            'shipping_address' => $order->has_shipping_address() ? self::address($order, 'shipping') : null,
        ]);
    }

    /**
     * Language of the customer: the storefront language of the order (WPML, Polylang, the visitor's locale), the
     * locale of the account, otherwise the store's.
     */
    public static function language(\WC_Order $order): ?string
    {
        $language = Orders::language($order);

        if ($language === null && $order->get_customer_id() > 0) {
            $locale = get_user_meta($order->get_customer_id(), 'locale', true);
            $language = \is_string($locale) && $locale !== '' ? $locale : null;
        }

        return Customer::languageTag($language ?? get_locale());
    }

    /**
     * Idempotency key of an order email: the store, the order, the kind and the part (parcel, refund).
     */
    public static function key(\WC_Order $order, string $kind, string $part = ''): string
    {
        return 'wc-' . StoreEmails::site() . '-' . $order->get_id() . '-' . $kind . ($part !== '' ? '-' . substr(sha1($part), 0, 16) : '');
    }

    public static function sent(\WC_Order $order, string $key): bool
    {
        $stored = $order->get_meta(self::SENT_META, true);

        return isset(self::$sent[$key]) || \is_array($stored) && \in_array($key, $stored, true);
    }

    /**
     * Keeps the key on a fresh copy of the order with save_meta_data(): called from inside order saves.
     */
    public static function remember(\WC_Order $order, string $key): void
    {
        self::$sent[$key] = true;
        $fresh = wc_get_order($order->get_id());

        if ($fresh instanceof \WC_Order) {
            $stored = $fresh->get_meta(self::SENT_META, true);
            $fresh->update_meta_data(self::SENT_META, array_values(array_unique(array_merge(\is_array($stored) ? array_map('strval', $stored) : [], [$key]))));
            $fresh->save_meta_data();
        }
    }

    /**
     * Tracking of the parcel for the shipped email; [] without tracking, null when the parcel is gone.
     *
     * @return array<string, mixed>|null
     */
    private static function shipment(\WC_Order $order, string $part): ?array
    {
        if ($part === '') {
            return [];
        }

        foreach (Shipments::shipments($order) as $shipment) {
            if (($shipment['tracking_number'] ?? $shipment['id'] ?? '') === $part) {
                return array_intersect_key($shipment, array_flip(['carrier', 'tracking_number', 'tracking_url', 'estimated_delivery']));
            }
        }

        return null;
    }

    /**
     * @return list<LineItem>
     */
    private static function lines(\WC_Order $order): array
    {
        $lines = [];

        foreach ($order->get_items() as $item) {
            $line = $item instanceof \WC_Order_Item_Product ? Orders::line($item) : null;

            if ($line === null) {
                continue;
            }

            $product = $item->get_product();
            $display = ['options' => self::options($item)];

            if ($product instanceof \WC_Product) {
                $image = (int) $product->get_image_id();

                if ($image === 0 && $product->get_parent_id() > 0) {
                    $parent = wc_get_product($product->get_parent_id());
                    $image = $parent instanceof \WC_Product ? (int) $parent->get_image_id() : 0;
                }

                $display['sku'] = (string) $product->get_sku();
                $display['image_url'] = $image > 0 ? (string) wp_get_attachment_image_url($image, 'woocommerce_thumbnail') : null;
                $display['url'] = (string) $product->get_permalink();
            }

            $lines[] = $line->withDisplay($display);
        }

        return $lines;
    }

    /**
     * Options of the line as WooCommerce shows them (variation attributes, product add-ons): «Size: M, Colour: White».
     */
    private static function options(\WC_Order_Item_Product $item): ?string
    {
        $options = [];

        foreach ($item->get_formatted_meta_data() as $meta) {
            $meta = (array) $meta;
            $key = trim(wp_strip_all_tags((string) ($meta['display_key'] ?? '')));
            $value = trim(wp_strip_all_tags((string) ($meta['display_value'] ?? '')));

            if ($key !== '' && $value !== '') {
                $options[] = $key . ': ' . $value;
            }
        }

        return $options !== [] ? html_entity_decode(implode(', ', $options), ENT_QUOTES, 'UTF-8') : null;
    }

    /**
     * An address with the names of the region and the country, as the email shows it.
     *
     * @return array<string, string>
     */
    private static function address(\WC_Order $order, string $type): array
    {
        $address = $order->get_address($type);
        $country = (string) ($address['country'] ?? '');
        $state = (string) ($address['state'] ?? '');
        $countries = WC()->countries;
        $states = $country !== '' ? $countries->get_states($country) : [];
        $names = $countries->get_countries();
        $text = static function ($value): string {
            return html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        };

        return [
            'name' => trim((string) ($address['first_name'] ?? '') . ' ' . (string) ($address['last_name'] ?? '')),
            'company' => (string) ($address['company'] ?? ''),
            'address1' => (string) ($address['address_1'] ?? ''),
            'address2' => (string) ($address['address_2'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'region' => \is_array($states) && isset($states[$state]) ? $text($states[$state]) : $state,
            'postcode' => (string) ($address['postcode'] ?? ''),
            'country' => isset($names[$country]) ? $text($names[$country]) : $country,
            'phone' => (string) ($address['phone'] ?? ''),
        ];
    }

    /**
     * Payment instructions of the gateway (bank transfer, cheque, cash on delivery).
     */
    private static function instructions(\WC_Order $order): ?string
    {
        $gateway = self::gateway($order);
        $instructions = $gateway !== null ? $gateway->get_option('instructions') : null;

        return \is_string($instructions) && trim($instructions) !== '' ? trim(wp_strip_all_tags($instructions)) : null;
    }

    /** Title of the payment method for orders that keep only its id. */
    private static function gatewayTitle(\WC_Order $order): string
    {
        $gateway = self::gateway($order);

        return $gateway !== null ? wp_strip_all_tags((string) $gateway->get_title()) : '';
    }

    private static function gateway(\WC_Order $order): ?\WC_Payment_Gateway
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway = $gateways[$order->get_payment_method()] ?? null;

        return $gateway instanceof \WC_Payment_Gateway ? $gateway : null;
    }

    private static function retry(\WC_Order $order, string $kind, string $part, int $attempt): void
    {
        $delay = Retry::delay($attempt);

        if ($attempt < self::ATTEMPTS && $delay !== null && \function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, self::HOOK, [$order->get_id(), $kind, $part, $attempt + 1], Queue::GROUP);
        }
    }
}
