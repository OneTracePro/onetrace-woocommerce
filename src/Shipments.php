<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\LineItem;

/**
 * Shipped and delivered orders (order_shipped, order_delivered). WooCommerce itself has no parcels or tracking
 * numbers, so the plugin reads the order meta "_wc_shipment_tracking_items" of the popular tracking plugins —
 * WooCommerce Shipment Tracking, Advanced Shipment Tracking (Zorem) and TrackShip on top of them: one order_shipped
 * per tracking item with the carrier, the tracking number and the link. Without tracking the completed status
 * ("fulfilled" in WooCommerce) or the "shipped" status is one order_shipped without tracking, unless a parcel was
 * already sent. order_delivered — the "delivered" status of AST and TrackShip, or TrackShip's shipment status
 * "delivered" of every parcel.
 *
 * Other tracking plugins supply parcels with the filter "onetrace_woocommerce_order_shipments" and call
 * do_action('onetrace_woocommerce_sync_shipments', $order) after saving a parcel.
 *
 * Every parcel is sent once: the order keeps the keys of the sent parcels ("_onetrace_shipped"), and the messageId
 * of the parcel (the tracking number) is deterministic, so a repeat is also dropped by the platform.
 *
 * WooCommerce has no shipped or delivered emails: with the OneTrace template of order_shipped / order_delivered on,
 * every new parcel and the delivery also schedule the template email (OrderEmails).
 */
final class Shipments
{
    /** Tracking items of WooCommerce Shipment Tracking, Advanced Shipment Tracking and TrackShip. */
    public const TRACKING_META = '_wc_shipment_tracking_items';

    /** Keys of the parcels sent as order_shipped; "" — shipped without tracking. */
    public const SHIPPED_META = '_onetrace_shipped';

    /** order_delivered was sent. */
    public const DELIVERED_META = '_onetrace_delivered';

    /** @var array<int, array<string, true>> parcels sent in this request, by order id (the order meta may be read from a stale copy) */
    private static $sent = [];

    /** @var array<int, true> */
    private static $delivered = [];

    public static function register(): void
    {
        // WooCommerce Shipment Tracking saves the items with save_meta_data(), AST with save() — both fire the
        // order meta actions of WooCommerce (HPOS and posts storage); older versions call update_post_meta().
        add_action('added_order_meta', [self::class, 'metaSaved'], 20, 4);
        add_action('updated_order_meta', [self::class, 'metaSaved'], 20, 4);
        add_action('added_post_meta', [self::class, 'metaSaved'], 20, 4);
        add_action('updated_post_meta', [self::class, 'metaSaved'], 20, 4);
        add_action('woocommerce_order_status_changed', [self::class, 'statusChanged'], 20, 4);
        // TrackShip: shipment status of one tracking number from its webhook.
        add_action('trackship_shipment_status_trigger', [self::class, 'trackshipStatus'], 20, 4);
        add_action('onetrace_woocommerce_sync_shipments', [self::class, 'syncAction']);
    }

    /**
     * @param mixed $metaId
     * @param mixed $orderId
     * @param mixed $key
     * @param mixed $value
     */
    public static function metaSaved($metaId, $orderId, $key, $value = null): void
    {
        if ($key !== self::TRACKING_META || !is_numeric($orderId) || !Settings::enabled('orders')) {
            return;
        }

        $order = wc_get_order((int) $orderId);

        if ($order instanceof \WC_Order) {
            self::sync($order, \is_array($value) ? $value : null);
        }
    }

    /**
     * @param int|string $orderId
     * @param string $from
     * @param string $to
     * @param \WC_Order|null $order
     */
    public static function statusChanged($orderId, $from, $to, $order = null): void
    {
        if (!Settings::enabled('orders')) {
            return;
        }

        $from = self::status($from);
        $to = self::status($to);
        $order = $order instanceof \WC_Order ? $order : wc_get_order((int) $orderId);

        if (!$order instanceof \WC_Order || $from === $to) {
            return;
        }

        if (\in_array($to, self::shippedStatuses($order), true)) {
            self::sync($order, null, true);
        } elseif (\in_array($to, ['partial-shipped', 'updated-tracking'], true)) {
            // AST statuses set right after a parcel is added: only parcels with tracking.
            self::sync($order);
        } elseif (\in_array($to, self::deliveredStatuses($order), true)) {
            self::delivered($order);
        }
    }

    /**
     * @param mixed $orderId
     * @param mixed $previous
     * @param mixed $status
     * @param mixed $trackingNumber
     */
    public static function trackshipStatus($orderId, $previous = '', $status = '', $trackingNumber = ''): void
    {
        if ($status !== 'delivered' || !is_numeric($orderId) || !Settings::enabled('orders')) {
            return;
        }

        $order = wc_get_order((int) $orderId);

        if ($order instanceof \WC_Order && self::trackshipDelivered($order)) {
            self::delivered($order);
        }
    }

    /**
     * do_action('onetrace_woocommerce_sync_shipments', $order or its id): parcels of the filter were saved.
     *
     * @param mixed $order
     */
    public static function syncAction($order): void
    {
        $order = $order instanceof \WC_Order ? $order : (is_numeric($order) ? wc_get_order((int) $order) : null);

        if ($order instanceof \WC_Order && Settings::enabled('orders')) {
            self::sync($order);
        }
    }

    /**
     * Sends the parcels of the order that were not sent yet; $fulfilled — the order is shipped by its status, so
     * without parcels it is one order_shipped without tracking.
     *
     * @param array<mixed>|null $items tracking items just saved (the order may still hold the previous ones)
     */
    public static function sync(\WC_Order $order, ?array $items = null, bool $fulfilled = false): void
    {
        $model = Orders::model($order);

        // An order without an account, email, phone and browser id cannot be linked to a profile.
        if ($model->customer->ids() === []) {
            return;
        }

        $shipments = self::shipments($order, $items);
        $sent = self::sent($order);
        $messages = Connection::messages();
        $new = [];

        foreach ($shipments as $shipment) {
            $key = $shipment['tracking_number'] ?? $shipment['id'] ?? '';

            if (isset($sent[$key]) || isset($new[$key])) {
                continue;
            }

            $new[$key] = true;
            Queue::event($messages->orderShipped(
                $model,
                $shipment['carrier'] ?? null,
                $shipment['tracking_number'] ?? null,
                $shipment['tracking_url'] ?? null,
                $shipment['estimated_delivery'] ?? null,
                $shipment['shipped_at'] ?? null,
                isset($shipment['tracking_number']) ? null : ($shipment['id'] ?? null),
                $shipment['lines'] ?? []
            ));
            OrderEmails::schedule($order, 'order_shipped', (string) $key);
        }

        if ($fulfilled && $shipments === [] && $sent === [] && self::ships($order)) {
            $new[''] = true;
            Queue::event($messages->orderShipped($model));
            OrderEmails::schedule($order, 'order_shipped');
        }

        if ($new !== []) {
            self::$sent[$order->get_id()] = $sent + $new;
            self::remember($order, self::SHIPPED_META, array_map('strval', array_keys($sent + $new)));
        }
    }

    /**
     * Parcels of the order: the tracking items and what the filter adds.
     *
     * @param array<mixed>|null $items
     *
     * @return list<array{id?: string, carrier?: string, tracking_number?: string, tracking_url?: string, shipped_at?: \DateTimeInterface, estimated_delivery?: \DateTimeInterface, lines?: list<LineItem>}>
     */
    public static function shipments(\WC_Order $order, ?array $items = null): array
    {
        $items = $items ?? $order->get_meta(self::TRACKING_META, true);
        $shipments = [];

        foreach (\is_array($items) ? $items : [] as $item) {
            if (\is_array($item) && ($shipment = self::fromTrackingItem($order, $item)) !== null) {
                $shipments[] = $shipment;
            }
        }

        /**
         * Parcels of the order for order_shipped, for tracking plugins that do not keep "_wc_shipment_tracking_items".
         * Each parcel: carrier (the name the customer sees), tracking_number, tracking_url, shipped_at (timestamp or
         * DateTimeInterface), estimated_delivery ("YYYY-MM-DD" or DateTimeInterface), items (order item id =>
         * quantity of a partial shipment), id (when there is no tracking number). Call
         * do_action('onetrace_woocommerce_sync_shipments', $order) after a parcel is saved.
         *
         * @param array<mixed> $shipments parcels from the tracking items (arrays)
         * @param \WC_Order $order
         */
        $filtered = apply_filters('onetrace_woocommerce_order_shipments', $shipments, $order);

        if ($filtered === $shipments) {
            return $shipments;
        }

        $result = [];

        foreach ((array) $filtered as $shipment) {
            if (\is_array($shipment) && ($shipment = self::normalize($order, $shipment)) !== null) {
                $result[] = $shipment;
            }
        }

        return $result;
    }

    /**
     * Statuses in which the order is handed over to delivery: "completed" (fulfilled) and "shipped" of tracking
     * plugins.
     *
     * @return list<string>
     */
    public static function shippedStatuses(\WC_Order $order): array
    {
        /**
         * Order statuses that mean the order is shipped (order_shipped without tracking when it has no parcels).
         *
         * @param array<string> $statuses
         * @param \WC_Order $order
         */
        return array_values(array_map([self::class, 'status'], (array) apply_filters('onetrace_woocommerce_shipped_statuses', ['completed', 'shipped'], $order)));
    }

    /**
     * @return list<string>
     */
    public static function deliveredStatuses(\WC_Order $order): array
    {
        /**
         * Order statuses that mean the customer received the order (order_delivered).
         *
         * @param array<string> $statuses
         * @param \WC_Order $order
         */
        return array_values(array_map([self::class, 'status'], (array) apply_filters('onetrace_woocommerce_delivered_statuses', ['delivered'], $order)));
    }

    private static function delivered(\WC_Order $order): void
    {
        $id = $order->get_id();

        if (isset(self::$delivered[$id]) || $order->get_meta(self::DELIVERED_META, true) !== '') {
            return;
        }

        $model = Orders::model($order);

        if ($model->customer->ids() === []) {
            return;
        }

        self::$delivered[$id] = true;
        Queue::event(Connection::messages()->orderDelivered($model, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        OrderEmails::schedule($order, 'order_delivered');
        self::remember($order, self::DELIVERED_META, (string) time());
    }

    /**
     * TrackShip: every tracking number of the order is delivered (it sets the order status "delivered" itself only
     * with its "Delivered" status setting on).
     */
    private static function trackshipDelivered(\WC_Order $order): bool
    {
        if (!\function_exists('trackship_for_woocommerce')) {
            return false;
        }

        try {
            $actions = trackship_for_woocommerce()->actions ?? null;
            $rows = \is_object($actions) && method_exists($actions, 'get_shipment_rows') ? (array) $actions->get_shipment_rows($order->get_id()) : [];
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($rows as $row) {
            if (!\is_object($row) || ($row->shipment_status ?? '') !== 'delivered') {
                return false;
            }
        }

        return $rows !== [];
    }

    /**
     * A tracking item of WooCommerce Shipment Tracking / AST: tracking_provider (slug) or custom_tracking_provider,
     * tracking_number, custom_tracking_link, date_shipped (timestamp), tracking_id; AST PRO — products_list.
     *
     * @param array<mixed> $item
     *
     * @return array{id?: string, carrier?: string, tracking_number?: string, tracking_url?: string, shipped_at?: \DateTimeInterface, estimated_delivery?: \DateTimeInterface, lines?: list<LineItem>}|null
     */
    private static function fromTrackingItem(\WC_Order $order, array $item): ?array
    {
        $formatted = self::formatted($order, $item);
        $items = [];

        foreach (\is_array($item['products_list'] ?? null) ? $item['products_list'] : [] as $product) {
            $product = (array) $product;

            if (is_numeric($product['item_id'] ?? null)) {
                $items[(int) $product['item_id']] = is_numeric($product['qty'] ?? null) ? (int) $product['qty'] : 0;
            }
        }

        return self::normalize($order, [
            'id' => $item['tracking_id'] ?? null,
            'carrier' => self::first([$formatted['formatted_tracking_provider'] ?? null, $item['formatted_tracking_provider'] ?? null, $item['custom_tracking_provider'] ?? null, $item['tracking_provider'] ?? null]),
            'tracking_number' => $item['tracking_number'] ?? null,
            'tracking_url' => self::first([$formatted['formatted_tracking_link'] ?? null, $item['formatted_tracking_link'] ?? null, $item['custom_tracking_link'] ?? null, $formatted['ast_tracking_link'] ?? null, $item['tracking_page_link'] ?? null]),
            'shipped_at' => $item['date_shipped'] ?? null,
            'items' => $items,
        ]);
    }

    /**
     * The carrier name and link as the tracking plugin shows them: its provider list knows the name of the slug and
     * the link with the number.
     *
     * @param array<mixed> $item
     *
     * @return array<string, mixed>
     */
    private static function formatted(\WC_Order $order, array $item): array
    {
        try {
            if (\function_exists('wc_advanced_shipment_tracking')) {
                $plugin = wc_advanced_shipment_tracking();
            } elseif (\function_exists('wc_shipment_tracking')) {
                $plugin = wc_shipment_tracking();
            } else {
                return [];
            }

            $actions = \is_object($plugin) ? ($plugin->actions ?? null) : null;

            if (\is_object($actions) && method_exists($actions, 'get_formatted_tracking_item')) {
                $formatted = $actions->get_formatted_tracking_item($order->get_id(), $item);

                return \is_array($formatted) ? $formatted : [];
            }
        } catch (\Throwable $e) {
            // A broken tracking plugin must not stop the order save; the raw item is enough.
        }

        return [];
    }

    /**
     * @param array<mixed> $shipment
     *
     * @return array{id?: string, carrier?: string, tracking_number?: string, tracking_url?: string, shipped_at?: \DateTimeInterface, estimated_delivery?: \DateTimeInterface, lines?: list<LineItem>}|null
     */
    private static function normalize(\WC_Order $order, array $shipment): ?array
    {
        $result = [];

        foreach (['id', 'carrier', 'tracking_number', 'tracking_url'] as $field) {
            $value = $shipment[$field] ?? null;

            if (\is_scalar($value) && trim((string) $value) !== '') {
                $result[$field] = trim((string) $value);
            }
        }

        $shipped = self::date($shipment['shipped_at'] ?? null);

        if ($shipped !== null) {
            // A shipping date of today (tracking plugins keep the day) is "now".
            $result['shipped_at'] = $shipped->format('Y-m-d') === gmdate('Y-m-d') ? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) : $shipped;
        }

        $estimated = self::date($shipment['estimated_delivery'] ?? null);

        if ($estimated !== null) {
            $result['estimated_delivery'] = $estimated;
        }

        $lines = [];

        foreach (\is_array($shipment['items'] ?? null) ? $shipment['items'] : [] as $itemId => $quantity) {
            $item = $order->get_item((int) $itemId);
            $line = $item instanceof \WC_Order_Item_Product ? Orders::line($item, is_numeric($quantity) && (int) $quantity > 0 ? (int) $quantity : null) : null;

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if ($lines !== []) {
            $result['lines'] = $lines;
        }

        return $result !== [] ? $result : null;
    }

    /**
     * Only orders with something to ship: a virtual or downloadable order completed is not shipped.
     */
    public static function ships(\WC_Order $order): bool
    {
        foreach ($order->get_items() as $item) {
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;

            if ($item instanceof \WC_Order_Item_Product && (!$product instanceof \WC_Product || $product->needs_shipping())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private static function sent(\WC_Order $order): array
    {
        $stored = $order->get_meta(self::SHIPPED_META, true);
        $sent = self::$sent[$order->get_id()] ?? [];

        foreach (\is_array($stored) ? $stored : [] as $key) {
            if (\is_scalar($key)) {
                $sent[(string) $key] = true;
            }
        }

        return $sent;
    }

    /**
     * Keeps a mark on a fresh copy of the order with save_meta_data(): the tracking plugins call this from inside
     * their own save, and the order itself is not saved again.
     *
     * @param mixed $value
     */
    private static function remember(\WC_Order $order, string $key, $value): void
    {
        $fresh = wc_get_order($order->get_id());

        if ($fresh instanceof \WC_Order) {
            $fresh->update_meta_data($key, $value);
            $fresh->save_meta_data();
        }
    }

    /**
     * @param mixed $value
     */
    private static function date($value): ?\DateTimeImmutable
    {
        try {
            if ($value instanceof \DateTimeInterface) {
                return new \DateTimeImmutable('@' . $value->getTimestamp());
            }

            if (is_numeric($value) && (int) $value > 0) {
                return new \DateTimeImmutable('@' . (int) $value);
            }

            if (\is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
                return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * @param array<mixed> $values
     */
    private static function first(array $values): ?string
    {
        foreach ($values as $value) {
            if (\is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param mixed $status
     */
    private static function status($status): string
    {
        $status = (string) $status;

        return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
    }
}
