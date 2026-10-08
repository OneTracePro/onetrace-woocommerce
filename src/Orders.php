<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\LineItem;
use OneTrace\Commerce\Order;

/**
 * Orders from the server: placed (classic and block checkout), paid, cancelled and refunded. Each event has a
 * deterministic messageId, so the same order event is never counted twice.
 */
final class Orders
{
    public static function register(): void
    {
        add_action('woocommerce_checkout_create_order', [self::class, 'remember']);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [self::class, 'remember']);
        add_action('woocommerce_checkout_order_processed', [self::class, 'classicPlaced'], 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'placed'], 20);
        add_action('woocommerce_order_status_changed', [self::class, 'statusChanged'], 20, 4);
        add_action('woocommerce_order_refunded', [self::class, 'refunded'], 20, 2);
    }

    /**
     * Keeps the visitor's browser id with the order: the order and the visits before it end up in one profile.
     */
    public static function remember(\WC_Order $order): void
    {
        $anonymousId = Connection::anonymousId();

        if ($anonymousId !== null) {
            $order->update_meta_data('_onetrace_anonymous_id', $anonymousId);
        }
    }

    /**
     * @param int|string $orderId
     * @param array<string, mixed> $data
     * @param \WC_Order|null $order
     */
    public static function classicPlaced($orderId, $data = [], $order = null): void
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order((int) $orderId);

        if ($order instanceof \WC_Order) {
            self::placed($order);
        }
    }

    public static function placed(\WC_Order $order): void
    {
        if (!Settings::enabled('orders')) {
            return;
        }

        $messages = Connection::messages();
        $model = self::model($order);

        if ($model->customer->ids() === []) {
            return;
        }

        Queue::event($messages->identify($model->customer, Customers::orderConsents($order), $model->placedAt));
        Queue::event($messages->orderCompleted($model));

        // Paid right away (instant payment) — the status change came before the order was processed.
        if (\in_array($order->get_status(), self::paidStatuses($order), true)) {
            Queue::event($messages->orderPaid($model, self::paidAt($order)));
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

        $order = $order instanceof \WC_Order ? $order : wc_get_order((int) $orderId);

        if (!$order instanceof \WC_Order) {
            return;
        }

        $paid = self::paidStatuses($order);
        $model = self::model($order);

        // An order without an account, email, phone and browser id cannot be linked to a profile.
        if ($model->customer->ids() === []) {
            return;
        }

        if (\in_array($to, $paid, true) && !\in_array($from, $paid, true)) {
            Queue::event(Connection::messages()->orderPaid($model, self::paidAt($order)));
        } elseif ($to === 'cancelled' && $from !== 'cancelled') {
            Queue::event(Connection::messages()->orderCancelled($model, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        }
    }

    /**
     * @param int|string $orderId
     * @param int|string $refundId
     */
    public static function refunded($orderId, $refundId): void
    {
        $order = wc_get_order((int) $orderId);
        $refund = wc_get_order((int) $refundId);

        if (!Settings::enabled('orders') || !$order instanceof \WC_Order || !$refund instanceof \WC_Order_Refund) {
            return;
        }

        $model = self::model($order);

        if ($model->customer->ids() === []) {
            return;
        }

        $created = $refund->get_date_created();
        Queue::event(Connection::messages()->orderRefunded(
            $model,
            (float) $refund->get_amount(),
            $refund->get_id(),
            $created ? new \DateTimeImmutable('@' . $created->getTimestamp()) : null,
            self::lines($refund, true)
        ));
    }

    public static function model(\WC_Order $order): Order
    {
        $created = $order->get_date_created();
        $coupons = $order->get_coupon_codes();

        return (new Order(
            $order->get_order_number(),
            (float) $order->get_total(),
            $order->get_currency(),
            $created ? new \DateTimeImmutable('@' . $created->getTimestamp()) : new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            Customers::fromOrder($order)->language(self::language($order)),
            self::lines($order)
        ))->with([
            'subtotal' => (float) $order->get_subtotal(),
            'shipping' => (float) $order->get_shipping_total(),
            'tax' => (float) $order->get_total_tax(),
            'discount' => (float) $order->get_discount_total(),
            'coupon' => implode(',', $coupons),
            'payment_method' => $order->get_payment_method(),
            'shipping_method' => implode(',', array_map(static function ($item): string {
                return $item instanceof \WC_Order_Item_Shipping ? (string) $item->get_method_id() : '';
            }, array_values($order->get_items('shipping')))),
        ]);
    }

    /**
     * @param \WC_Order|\WC_Order_Refund $order
     *
     * @return list<LineItem>
     */
    private static function lines($order, bool $refund = false): array
    {
        $lines = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $quantity = abs((int) $item->get_quantity());
            $total = abs((float) $item->get_total());

            if ($quantity === 0 || $refund && $total === 0.0) {
                continue;
            }

            $product = $item->get_product();
            $lines[] = new LineItem(
                $item->get_product_id(),
                $item->get_name(),
                $total / max(1, $quantity),
                max(1, $quantity),
                $item->get_variation_id() ?: null,
                $product instanceof \WC_Product ? Catalog::categoryId($product) : null
            );
        }

        return $lines;
    }

    /**
     * Statuses in which the money is received. WooCommerce counts "processing" as paid, but cash on delivery is
     * paid only when the order is completed.
     *
     * @return list<string>
     */
    public static function paidStatuses(\WC_Order $order): array
    {
        $statuses = $order->get_payment_method() === 'cod' ? ['completed'] : wc_get_is_paid_statuses();

        /**
         * Order statuses that mean the order is paid (order_paid).
         *
         * @param array<string> $statuses
         * @param \WC_Order $order
         */
        return array_values((array) apply_filters('onetrace_woocommerce_paid_statuses', $statuses, $order));
    }

    private static function paidAt(\WC_Order $order): \DateTimeImmutable
    {
        $paid = $order->get_date_paid();

        return $paid ? new \DateTimeImmutable('@' . $paid->getTimestamp()) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Storefront language of the order: WPML and Polylang store it with the order.
     */
    private static function language(\WC_Order $order): ?string
    {
        foreach (['wpml_language', 'pll_language'] as $key) {
            $value = $order->get_meta($key);

            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return is_admin() && !wp_doing_ajax() ? null : determine_locale();
    }
}
