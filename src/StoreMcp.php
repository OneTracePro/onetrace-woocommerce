<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\CustomerTokens;
use OneTrace\StoreMcp\Server;
use OneTrace\StoreMcp\ToolError;
use OneTrace\StoreMcp\Verification;

/**
 * MCP server of the store for the OneTrace AI chat bot (store MCP contract v1): POST /wp-json/onetrace/v1/mcp. The bot
 * reads orders, tracking, stock, delivery and the return policy, and — when the owner allows it in the settings —
 * cancels unpaid orders and passes return and delivery change requests to the shop. Customers are confirmed by the
 * login on the website (cdp.chatCustomer()) or by a code the shop sends by email. Customer ids: "user:<id>" or
 * "email:<address>" (guest orders).
 */
final class StoreMcp
{
    public const KEY_OPTION = 'onetrace_store_mcp_key';
    public const SECRET_OPTION = 'onetrace_store_mcp_secret';
    public const STATUS_OPTION = 'onetrace_store_mcp_status';

    public const READ_TOOLS = ['store_start_verification', 'store_verify', 'store_get_customer', 'store_find_orders', 'store_get_order', 'store_get_tracking', 'store_check_availability', 'store_get_delivery_options', 'store_get_return_policy'];
    public const ACTIONS = ['store_cancel_order', 'store_create_return', 'store_change_delivery'];

    private const CONTRACT_STATUSES = ['pending' => 'pending', 'processing' => 'processing', 'on-hold' => 'on_hold', 'completed' => 'completed', 'cancelled' => 'cancelled', 'refunded' => 'refunded', 'failed' => 'failed', 'shipped' => 'shipped', 'delivered' => 'delivered'];

    public static function register(): void
    {
        if (Settings::enabled('store_mcp')) {
            add_action('rest_api_init', [self::class, 'route']);
        }
    }

    public static function route(): void
    {
        register_rest_route('onetrace/v1', '/mcp', [
            'methods' => 'POST',
            'callback' => [self::class, 'serve'],
            // The platform authenticates with the store key inside the MCP server.
            'permission_callback' => '__return_true',
        ]);
    }

    public static function serve(\WP_REST_Request $request): \WP_REST_Response
    {
        $response = self::server()->handle('POST', ['Authorization' => (string) $request->get_header('authorization')], $request->get_body());
        $data = $response['body'] === '' ? null : json_decode($response['body'], true);

        return new \WP_REST_Response($data, $response['status']);
    }

    public static function url(): string
    {
        return rest_url('onetrace/v1/mcp');
    }

    public static function key(): string
    {
        return self::secret(self::KEY_OPTION);
    }

    public static function tokens(): CustomerTokens
    {
        return new CustomerTokens(self::secret(self::SECRET_OPTION));
    }

    /**
     * The chat token of the signed-in customer for cdp.chatCustomer() (not for shop staff).
     */
    public static function customerToken(): ?string
    {
        $user = wp_get_current_user();

        if (!Settings::enabled('store_mcp') || !$user->ID || user_can($user, 'manage_woocommerce')) {
            return null;
        }

        return self::tokens()->issue('user:' . $user->ID);
    }

    /**
     * Connects the server to the bot of the project (after the settings are saved) or disconnects it.
     */
    public static function connect(): void
    {
        if (!Settings::server()) {
            return;
        }

        try {
            if (!Settings::enabled('store_mcp')) {
                if (get_option(self::STATUS_OPTION)) {
                    Connection::client()->chats()->disconnectStoreMcp(self::url());
                    delete_option(self::STATUS_OPTION);
                }

                return;
            }

            $result = Connection::client()->chats()->connectStoreMcp(self::url(), self::key(), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), 'woocommerce');
            update_option(self::STATUS_OPTION, ['status' => (string) ($result['status'] ?? 'error'), 'error' => (string) ($result['error'] ?? ''), 'tools' => array_values(array_filter((array) ($result['tools'] ?? []), 'is_string')), 'at' => time()], false);
        } catch (\Throwable $e) {
            update_option(self::STATUS_OPTION, ['status' => 'error', 'error' => $e->getMessage(), 'tools' => [], 'at' => time()], false);
        }
    }

    public static function server(): Server
    {
        $handlers = [
            'store_get_customer' => [self::class, 'customer'],
            'store_find_orders' => [self::class, 'findOrders'],
            'store_get_order' => [self::class, 'getOrder'],
            'store_get_tracking' => [self::class, 'tracking'],
            'store_check_availability' => [self::class, 'availability'],
            'store_get_delivery_options' => [self::class, 'delivery'],
            'store_get_return_policy' => [self::class, 'returnPolicy'],
            'store_cancel_order' => [self::class, 'cancel'],
            'store_create_return' => [self::class, 'createReturn'],
            'store_change_delivery' => [self::class, 'changeDelivery'],
        ];
        $enabled = Settings::get('store_mcp_tools');

        return new Server(self::key(), self::tokens(), $handlers, [
            'name' => 'woocommerce:' . wp_parse_url(home_url(), PHP_URL_HOST),
            'version' => ONETRACE_WC_VERSION,
            'enabled' => \is_array($enabled) ? array_values(array_filter($enabled, 'is_string')) : self::READ_TOOLS,
            'verification' => new Verification(new TransientCodeStore(), [self::class, 'findCustomer'], [self::class, 'sendCode']),
        ]);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function customer(array $args, Context $ctx): array
    {
        [$user, $email] = self::who((string) $ctx->customerId);

        return array_filter([
            'id' => $ctx->customerId,
            'email' => $user !== null ? $user->user_email : $email,
            'phone' => $user !== null ? (string) get_user_meta($user->ID, 'billing_phone', true) : null,
            'first_name' => $user !== null ? $user->first_name : null,
        ], static function ($value): bool {
            return $value !== null && $value !== '';
        });
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function findOrders(array $args, Context $ctx): array
    {
        $limit = max(1, min(20, is_numeric($args['limit'] ?? null) ? (int) $args['limit'] : 5));
        $orders = [];

        foreach (self::orders((string) $ctx->customerId, $limit) as $order) {
            $status = self::status($order);

            if (\is_string($args['status'] ?? null) && $args['status'] !== '' && $args['status'] !== $status) {
                continue;
            }

            $orders[] = [
                'order' => $order->get_order_number(),
                'created_at' => self::date($order->get_date_created()),
                'status' => $status,
                'status_label' => wc_get_order_status_name($order->get_status()),
                'total' => self::money((float) $order->get_total(), $order->get_currency()),
                'items_count' => $order->get_item_count(),
            ];
        }

        return ['orders' => $orders];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function getOrder(array $args, Context $ctx): array
    {
        $order = self::order($args, $ctx);
        $shipment = Shipments::shipments($order)[0] ?? [];
        $items = [];

        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $product = $item->get_product();
                $items[] = ['name' => $item->get_name(), 'sku' => $product instanceof \WC_Product ? $product->get_sku() : '', 'quantity' => $item->get_quantity(), 'price' => self::money((float) $order->get_item_total($item, true), $order->get_currency())];
            }
        }

        return [
            'order' => $order->get_order_number(),
            'created_at' => self::date($order->get_date_created()),
            'status' => self::status($order),
            'status_label' => wc_get_order_status_name($order->get_status()),
            'items' => $items,
            'total' => self::money((float) $order->get_total(), $order->get_currency()),
            'payment' => ['method' => $order->get_payment_method_title(), 'status' => $order->is_paid() ? 'paid' : 'unpaid'],
            'shipping' => array_filter([
                'method' => $order->get_shipping_method(),
                'address' => trim($order->get_shipping_city() . ', ' . $order->get_shipping_address_1(), ', '),
                'carrier' => $shipment['carrier'] ?? null,
                'tracking_number' => $shipment['tracking_number'] ?? null,
                'tracking_url' => $shipment['tracking_url'] ?? null,
                'estimated_delivery' => isset($shipment['estimated_delivery']) ? $shipment['estimated_delivery']->format('Y-m-d') : null,
            ], static function ($value): bool {
                return $value !== null && $value !== '';
            }),
            'can_cancel' => self::cancellable($order),
            'can_return' => $order->has_status(['completed', 'delivered']),
        ];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function tracking(array $args, Context $ctx): array
    {
        $order = self::order($args, $ctx);
        $shipment = Shipments::shipments($order)[0] ?? null;

        if ($shipment === null) {
            throw new ToolError(ToolError::NOT_FOUND, __('The order has not been shipped yet.', 'onetrace-for-woocommerce'));
        }

        return array_filter([
            'carrier' => $shipment['carrier'] ?? null,
            'tracking_number' => $shipment['tracking_number'] ?? null,
            'tracking_url' => $shipment['tracking_url'] ?? null,
            'status' => self::status($order),
            'estimated_delivery' => isset($shipment['estimated_delivery']) ? $shipment['estimated_delivery']->format('Y-m-d') : null,
            'events' => isset($shipment['shipped_at']) ? [['at' => $shipment['shipped_at']->format(DATE_ATOM), 'status' => 'shipped', 'location' => null]] : [],
        ], static function ($value): bool {
            return $value !== null;
        });
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function availability(array $args, Context $ctx): array
    {
        $products = [];

        if (\is_string($args['sku'] ?? null) && $args['sku'] !== '') {
            $products = array_filter([wc_get_product(wc_get_product_id_by_sku($args['sku']))]);
        } elseif (is_scalar($args['product_id'] ?? null) && (string) $args['product_id'] !== '') {
            $products = array_filter([wc_get_product((int) $args['product_id'])]);
        } elseif (\is_string($args['query'] ?? null) && trim($args['query']) !== '') {
            $found = wc_get_products(['s' => trim($args['query']), 'status' => 'publish', 'limit' => 10, 'visibility' => 'catalog']);
            $products = \is_array($found) ? $found : [];
        }

        $result = [];

        foreach ($products as $product) {
            if (!$product instanceof \WC_Product || $product->get_status() !== 'publish') {
                continue;
            }

            $variants = [];

            if ($product instanceof \WC_Product_Variable) {
                foreach ($product->get_children() as $childId) {
                    $variation = wc_get_product($childId);

                    if ($variation instanceof \WC_Product_Variation) {
                        $variants[] = ['attributes' => $variation->get_variation_attributes(false), 'sku' => $variation->get_sku(), 'in_stock' => $variation->is_in_stock()];
                    }
                }
            }

            $result[] = array_filter([
                'product_id' => (string) $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'url' => $product->get_permalink(),
                'price' => self::money((float) wc_get_price_to_display($product), get_woocommerce_currency()),
                'in_stock' => $product->is_in_stock(),
                'quantity' => $product->managing_stock() ? $product->get_stock_quantity() : null,
                'variants' => $variants,
            ], static function ($value): bool {
                return $value !== null && $value !== '' && $value !== [];
            });
        }

        return ['products' => $result];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function delivery(array $args, Context $ctx): array
    {
        $country = \is_string($args['country'] ?? null) && $args['country'] !== '' ? strtoupper($args['country']) : WC()->countries->get_base_country();
        $zone = \WC_Shipping_Zones::get_zone_matching_package(['destination' => [
            'country' => $country,
            'state' => '',
            'postcode' => \is_string($args['postcode'] ?? null) ? $args['postcode'] : '',
            'city' => \is_string($args['city'] ?? null) ? $args['city'] : '',
        ]]);
        $methods = [];

        foreach ($zone->get_shipping_methods(true) as $method) {
            $cost = $method->get_option('cost');
            $min = $method->get_option('min_amount');
            $methods[] = array_filter([
                'name' => $method->get_title(),
                'price' => is_numeric($cost) ? self::money((float) $cost, get_woocommerce_currency()) : ($method->id === 'free_shipping' ? self::money(0.0, get_woocommerce_currency()) : null),
                'free_from' => $method->id === 'free_shipping' && is_numeric($min) && (float) $min > 0 ? self::money((float) $min, get_woocommerce_currency()) : null,
            ], static function ($value): bool {
                return $value !== null;
            });
        }

        return ['methods' => $methods];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function returnPolicy(array $args, Context $ctx): array
    {
        $page = (int) get_option('woocommerce_refund_returns_page_id');
        $post = $page > 0 ? get_post($page) : null;

        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            throw new ToolError(ToolError::NOT_FOUND, __('The shop has not published a return policy.', 'onetrace-for-woocommerce'));
        }

        return ['text' => mb_substr(trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes($post->post_content)))), 0, 3000), 'url' => (string) get_permalink($post)];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function cancel(array $args, Context $ctx): array
    {
        $order = self::order($args, $ctx);

        if ($order->has_status('cancelled')) {
            return ['order' => $order->get_order_number(), 'status' => 'cancelled', 'message' => __('The order is cancelled.', 'onetrace-for-woocommerce')];
        }

        if (!self::cancellable($order)) {
            throw new ToolError(ToolError::NOT_ALLOWED, __('This order can no longer be cancelled in the chat, an operator will help.', 'onetrace-for-woocommerce'));
        }

        $order->update_status('cancelled', __('Cancelled by the customer in the OneTrace chat.', 'onetrace-for-woocommerce') . (\is_string($args['reason'] ?? null) && $args['reason'] !== '' ? ' ' . $args['reason'] : ''));

        return ['order' => $order->get_order_number(), 'status' => 'cancelled', 'message' => __('The order is cancelled.', 'onetrace-for-woocommerce')];
    }

    /**
     * WooCommerce has no returns of its own: the request goes to the shop as an order note and an email.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function createReturn(array $args, Context $ctx): array
    {
        $order = self::order($args, $ctx);

        if (!$order->has_status(['completed', 'delivered'])) {
            throw new ToolError(ToolError::NOT_ALLOWED, __('A return is possible after the order is received.', 'onetrace-for-woocommerce'));
        }

        $items = [];

        foreach (\is_array($args['items'] ?? null) ? $args['items'] : [] as $item) {
            if (\is_array($item) && is_scalar($item['sku'] ?? null)) {
                $items[] = (string) $item['sku'] . ' × ' . (is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 1);
            }
        }

        /* translators: 1: returned items (SKU × quantity), 2: the reason the customer gave */
        $request = sprintf(__('Return request from the OneTrace chat: %1$s. Reason: %2$s', 'onetrace-for-woocommerce'), implode(', ', $items), \is_string($args['reason'] ?? null) ? $args['reason'] : '');

        return self::request($order, $ctx, $request, 'return');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function changeDelivery(array $args, Context $ctx): array
    {
        $order = self::order($args, $ctx);

        if (!$order->has_status(['pending', 'on-hold', 'processing'])) {
            throw new ToolError(ToolError::NOT_ALLOWED, __('The order has already been shipped, the delivery can no longer be changed.', 'onetrace-for-woocommerce'));
        }

        $parts = array_filter([
            /* translators: %s: the new delivery address */
            \is_string($args['address'] ?? null) ? sprintf(__('address: %s', 'onetrace-for-woocommerce'), $args['address']) : null,
            /* translators: %s: the new delivery date */
            \is_string($args['date'] ?? null) ? sprintf(__('date: %s', 'onetrace-for-woocommerce'), $args['date']) : null,
            /* translators: %s: the new delivery time window */
            \is_string($args['time_window'] ?? null) ? sprintf(__('time: %s', 'onetrace-for-woocommerce'), $args['time_window']) : null,
        ]);

        /* translators: %s: what to change (address, date, time) */
        return self::request($order, $ctx, sprintf(__('Delivery change request from the OneTrace chat: %s', 'onetrace-for-woocommerce'), implode(', ', $parts)), 'delivery');
    }

    public static function findCustomer(string $contact, ?string $order): ?string
    {
        $email = strpos($contact, '@') !== false ? sanitize_email($contact) : '';
        $user = $email !== '' ? get_user_by('email', $email) : null;
        $customer = $user instanceof \WP_User ? 'user:' . $user->ID : null;

        if ($customer === null) {
            $orders = wc_get_orders(array_filter([
                'limit' => 1,
                'billing_email' => $email !== '' ? $email : null,
                'billing_phone' => $email === '' ? $contact : null,
                'return' => 'objects',
            ]));
            $first = \is_array($orders) ? ($orders[0] ?? null) : null;

            if ($first instanceof \WC_Order) {
                $customer = $first->get_customer_id() ? 'user:' . $first->get_customer_id() : 'email:' . strtolower($first->get_billing_email());
            }
        }

        // With an order number the order must belong to this customer.
        if ($customer !== null && $order !== null && $order !== '') {
            try {
                self::order(['order' => $order], new Context($customer, 'en', null, false, null));
            } catch (ToolError $e) {
                return null;
            }
        }

        return $customer;
    }

    public static function sendCode(string $customerId, string $contact, string $code, string $channel): void
    {
        /**
         * Send the confirmation code yourself (e.g. by SMS): return true when sent.
         *
         * @param bool $sent
         * @param string $contact
         * @param string $code
         * @param string $channel email or sms
         */
        if (apply_filters('onetrace_woocommerce_send_code', false, $contact, $code, $channel) === true) {
            return;
        }

        if ($channel !== 'email') {
            throw new ToolError(ToolError::UNAVAILABLE, __('The shop sends codes only by email.', 'onetrace-for-woocommerce'));
        }

        $mailer = WC()->mailer();
        $heading = __('Confirmation code', 'onetrace-for-woocommerce');
        /* translators: %s: the confirmation code */
        $mailer->send($contact, $heading, $mailer->wrap_message($heading, '<p>' . sprintf(esc_html__('Your code for the chat: %s. It is valid for 10 minutes.', 'onetrace-for-woocommerce'), '<strong>' . esc_html($code) . '</strong>') . '</p>'));
    }

    /**
     * @return array{0: \WP_User|null, 1: string|null}
     */
    private static function who(string $customer): array
    {
        if (strpos($customer, 'user:') === 0) {
            $user = get_user_by('id', (int) substr($customer, 5));

            return [$user instanceof \WP_User ? $user : null, $user instanceof \WP_User ? $user->user_email : null];
        }

        return [null, preg_match('/^email:(.+)$/', $customer, $match) === 1 ? $match[1] : null];
    }

    /**
     * Orders of the customer, newest first: by the account and by the billing email (guest orders).
     *
     * @return list<\WC_Order>
     */
    private static function orders(string $customer, int $limit): array
    {
        [$user, $email] = self::who($customer);
        $owners = array_values(array_filter([$user !== null ? $user->ID : null, $email]));

        if ($owners === []) {
            return [];
        }

        $orders = wc_get_orders(['customer' => $owners, 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order']);
        return \is_array($orders) ? array_values($orders) : [];
    }

    /**
     * The order of the customer by its number; an order of someone else is "not found".
     *
     * @param array<string, mixed> $args
     */
    private static function order(array $args, Context $ctx): \WC_Order
    {
        $number = is_scalar($args['order'] ?? null) ? trim((string) $args['order'], " #\t") : '';
        // Sequential order number plugins map the number to the id with this filter of the order tracking form.
        $id = (int) apply_filters('woocommerce_shortcode_order_tracking_order_id', $number);
        $order = $id > 0 ? wc_get_order($id) : null;
        [$user, $email] = self::who((string) $ctx->customerId);

        if (!$order instanceof \WC_Order
            || !(($user !== null && $order->get_customer_id() === $user->ID) || ($email !== null && strtolower($order->get_billing_email()) === strtolower($email)))) {
            throw new ToolError(ToolError::NOT_FOUND, __('No such order of yours.', 'onetrace-for-woocommerce'));
        }

        return $order;
    }

    /**
     * Unpaid orders can be cancelled in the chat by default.
     */
    private static function cancellable(\WC_Order $order): bool
    {
        /**
         * Statuses of orders the bot may cancel.
         *
         * @param list<string> $statuses
         * @param \WC_Order $order
         */
        $statuses = (array) apply_filters('onetrace_woocommerce_cancellable_statuses', ['pending', 'on-hold', 'failed'], $order);

        return $order->has_status($statuses);
    }

    /**
     * A request for the shop: an order note and an email to the shop; repeated with the same idempotency key — the
     * same answer.
     *
     * @return array<string, mixed>
     */
    private static function request(\WC_Order $order, Context $ctx, string $text, string $kind): array
    {
        $key = 'onetrace_mcp_request_' . md5((string) $ctx->idempotencyKey);
        $done = $ctx->idempotencyKey !== null ? get_transient($key) : false;

        if (\is_array($done)) {
            return $done;
        }

        $note = $order->add_order_note($text);
        /* translators: 1: order number, 2: "return request" or "delivery change request" */
        wp_mail((string) get_option('admin_email'), sprintf(__('Order %1$s: %2$s', 'onetrace-for-woocommerce'), $order->get_order_number(), $kind === 'return' ? __('return request', 'onetrace-for-woocommerce') : __('delivery change request', 'onetrace-for-woocommerce')), $text . "\n\n" . $order->get_edit_order_url());
        $result = $kind === 'return'
            ? ['return_id' => 'R' . $order->get_order_number() . '-' . $note, 'status' => 'requested', 'instructions' => __('The shop will contact you about the return.', 'onetrace-for-woocommerce')]
            : ['order' => $order->get_order_number(), 'message' => __('The request is passed to the shop; it will confirm the change.', 'onetrace-for-woocommerce')];

        if ($ctx->idempotencyKey !== null) {
            set_transient($key, $result, DAY_IN_SECONDS);
        }

        return $result;
    }

    private static function status(\WC_Order $order): string
    {
        $status = self::CONTRACT_STATUSES[$order->get_status()] ?? 'processing';

        return $status === 'processing' && Shipments::shipments($order) !== [] ? 'shipped' : $status;
    }

    /**
     * @return array{amount: float, currency: string}
     */
    private static function money(float $amount, string $currency): array
    {
        return ['amount' => round($amount, 2), 'currency' => $currency];
    }

    private static function date(?\WC_DateTime $date): ?string
    {
        return $date !== null ? $date->format(DATE_ATOM) : null;
    }

    private static function secret(string $option): string
    {
        $value = get_option($option);

        if (!\is_string($value) || \strlen($value) < 32) {
            $value = wp_generate_password(48, false);
            update_option($option, $value, false);
        }

        return $value;
    }
}
