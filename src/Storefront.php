<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

/**
 * The storefront: the tracker (after the analytics consent when the WP Consent API is in use), product views,
 * linking the browser to the signed-in customer, recommendation widgets, the "Notify when available" form, the
 * "Ask AI about this product" block and Web Push.
 */
final class Storefront
{
    /** Methods the stub queues until cdp.js loads: the same list as in the platform's install code. */
    private const STUB_METHODS = ['init', 'page', 'track', 'identify', 'alias', 'reset', 'setLanguage', 'widgets', 'renderRecommendations', 'chatCustomer'];

    /** @var array<int, true> products whose "Notify when available" place is already on the page */
    private static $notified = [];

    /** @var array<int, true> products whose "Ask AI" place is already on the page */
    private static $asked = [];

    public static function register(): void
    {
        if (Settings::storefront() && Settings::enabled('tracker')) {
            add_action('wp_head', [self::class, 'tracker'], 1);
            add_action('wp_footer', [self::class, 'productView']);

            if (Settings::enabled('notify')) {
                // Classic templates: after the price, the stock and the add to cart form. Block themes: after the
                // "Add to cart form" / "Add to cart with options" block of the single product template.
                add_action('woocommerce_single_product_summary', [self::class, 'notifyForm'], 35);
                add_filter('render_block_woocommerce/add-to-cart-form', [self::class, 'notifyBlock'], 10, 3);
                add_filter('render_block_woocommerce/add-to-cart-with-options', [self::class, 'notifyBlock'], 10, 3);
            }

            if (Settings::enabled('ask_ai')) {
                // The same places, after the "Notify when available" form.
                add_action('woocommerce_single_product_summary', [self::class, 'askForm'], 36);
                add_filter('render_block_woocommerce/add-to-cart-form', [self::class, 'askBlock'], 11, 3);
                add_filter('render_block_woocommerce/add-to-cart-with-options', [self::class, 'askBlock'], 11, 3);
            }
        }

        add_shortcode('onetrace_widget', [self::class, 'widgetShortcode']);
        add_shortcode('onetrace_ask', [self::class, 'askShortcode']);
        add_shortcode('onetrace_push_button', [self::class, 'pushButton']);

        if ((string) Settings::get('widget_product') !== '') {
            add_action('woocommerce_after_single_product_summary', [self::class, 'productWidget'], 15);
        }

        if ((string) Settings::get('widget_cart') !== '') {
            add_action('woocommerce_after_cart_table', [self::class, 'cartWidget']);
        }

        if (Settings::enabled('push')) {
            add_action('parse_request', [self::class, 'serviceWorker'], 0);
        }
    }

    public static function tracker(): void
    {
        $host = Settings::url();
        $options = ['host' => $host, 'language' => 'page'];
        $user = wp_get_current_user();
        $userId = $user->ID && !user_can($user, 'manage_woocommerce') ? (string) $user->ID : '';
        $waits = Settings::enabled('wait_for_consent') && \function_exists('wp_has_consent');

        /**
         * Options of cdp.init() on the storefront.
         *
         * @param array<string, mixed> $options
         */
        $options = apply_filters('onetrace_woocommerce_tracker_options', $options);

        $script = sprintf(
            "!function(w,c){c=w.cdp=w.cdp||[];if(c.version)return;%s.forEach(function(m){c[m]=c[m]||function(){c.push([m].concat([].slice.call(arguments)))}})}(window);\n"
            . "(function(d,u,s,load){load=function(){if(s)return;s=d.createElement('script');s.async=1;s.src=u;d.head.appendChild(s)};%s})(document,%s);\n"
            . "cdp.init(%s, %s);\ncdp.page();\n"
            // The browser follows the signed-in customer: identify after login, reset after logout.
            . "(function(u,k){try{k=localStorage.getItem('cdp_uid')}catch(e){}if(u&&k!==u)cdp.identify(u);else if(!u&&k)cdp.reset()})(%s);",
            wp_json_encode(self::STUB_METHODS),
            $waits
                ? "if(typeof wp_has_consent!=='function'||wp_has_consent('statistics'))load();else d.addEventListener('wp_listen_for_consent_change',function(e){if(e.detail&&e.detail.statistics==='allow')load()});"
                : 'load();',
            wp_json_encode($host . '/tracker/cdp.js'),
            wp_json_encode((string) Settings::get('write_key')),
            wp_json_encode($options, JSON_UNESCAPED_SLASHES),
            wp_json_encode($userId)
        );

        // Store MCP: the signed-in customer is confirmed for the AI chat bot without a code.
        $chatToken = StoreMcp::customerToken();

        if ($chatToken !== null) {
            $script .= "\ncdp.chatCustomer(" . wp_json_encode($chatToken) . ');';
        }

        wp_print_inline_script_tag($script, ['id' => 'onetrace-tracker']);
    }

    /**
     * product_viewed on the product page: in the browser, so it is counted with page caching too.
     */
    public static function productView(): void
    {
        if (!is_product()) {
            return;
        }

        $product = wc_get_product(get_queried_object_id());

        if (!$product instanceof \WC_Product) {
            return;
        }

        $properties = array_filter([
            'product_id' => Products::id($product),
            'product_name' => $product->get_name(),
            'price' => (float) wc_get_price_to_display($product),
            'currency' => get_woocommerce_currency(),
            'category_id' => Catalog::categoryId($product),
        ], static function ($value): bool {
            return $value !== null;
        });

        wp_print_inline_script_tag(sprintf("cdp.track('product_viewed', %s);", wp_json_encode($properties)), ['id' => 'onetrace-product-view']);
    }

    /**
     * The place of the "Notify when available" form on the page of a product that is out of stock: the tracker
     * draws the form of the kind "Notify when available" from Site → Forms of the platform into it.
     */
    public static function notifyForm(): void
    {
        global $product;

        echo self::notify($product instanceof \WC_Product ? $product : wc_get_product(get_the_ID())); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notify().
    }

    /**
     * @param mixed $content HTML of the add to cart block
     * @param mixed $block parsed block
     * @param mixed $instance
     *
     * @return mixed
     */
    public static function notifyBlock($content, $block = [], $instance = null)
    {
        $postId = $instance instanceof \WP_Block && isset($instance->context['postId']) ? (int) $instance->context['postId'] : (int) get_the_ID();

        return \is_string($content) ? $content . self::notify($postId > 0 ? wc_get_product($postId) : null) : $content;
    }

    /**
     * `<div data-cdp-notify="{product id}">` for a product that cannot be bought now: a product out of stock, a
     * variable product without a variation in stock. Once per product on the page.
     *
     * @param mixed $product
     */
    public static function notify($product): string
    {
        if (!$product instanceof \WC_Product || $product->is_type('variation') || isset(self::$notified[$product->get_id()]) || self::inStock($product)) {
            return '';
        }

        self::$notified[$product->get_id()] = true;

        return sprintf('<div class="onetrace-notify" data-cdp-notify="%s"></div>', esc_attr(Products::id($product)));
    }

    /**
     * The place of the "Ask AI about this product" block on the product page: the tracker loads the widget of
     * OneTrace → Site → Product questions into it when the widget is on there; otherwise it stays empty.
     */
    public static function askForm(): void
    {
        global $product;

        echo self::ask($product instanceof \WC_Product ? $product : wc_get_product(get_the_ID())); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in ask().
    }

    /**
     * @param mixed $content HTML of the add to cart block
     * @param mixed $block parsed block
     * @param mixed $instance
     *
     * @return mixed
     */
    public static function askBlock($content, $block = [], $instance = null)
    {
        $postId = $instance instanceof \WP_Block && isset($instance->context['postId']) ? (int) $instance->context['postId'] : (int) get_the_ID();

        return \is_string($content) ? $content . self::ask($postId > 0 ? wc_get_product($postId) : null) : $content;
    }

    /**
     * [onetrace_ask] — the "Ask AI" block of the current product; [onetrace_ask product="123"] — of the product
     * with this post ID. Works without the setting: the setting places the block automatically.
     *
     * @param array<string, string>|string $attributes
     */
    public static function askShortcode($attributes): string
    {
        global $product;

        $attributes = shortcode_atts(['product' => ''], \is_array($attributes) ? $attributes : [], 'onetrace_ask');
        $id = absint($attributes['product']);

        if ($id > 0) {
            return self::ask(wc_get_product($id));
        }

        if ($product instanceof \WC_Product) {
            return self::ask($product);
        }

        return self::ask(is_product() ? wc_get_product(get_queried_object_id()) : wc_get_product(get_the_ID()));
    }

    /**
     * `<div data-cdp-ask data-product-id="{product id}">` — the product id of the events (a variation — its parent
     * product). Once per product on the page.
     *
     * @param mixed $product
     */
    public static function ask($product): string
    {
        if (!$product instanceof \WC_Product) {
            return '';
        }

        $id = Products::id($product);
        $key = $product->get_parent_id() ?: $product->get_id();

        if ($id === '' || isset(self::$asked[$key])) {
            return '';
        }

        self::$asked[$key] = true;

        return sprintf('<div class="onetrace-ask" data-cdp-ask data-product-id="%s"></div>', esc_attr($id));
    }

    private static function inStock(\WC_Product $product): bool
    {
        if (!$product instanceof \WC_Product_Variable) {
            return $product->is_in_stock();
        }

        foreach ($product->get_children() as $childId) {
            $variation = wc_get_product($childId);

            if ($variation instanceof \WC_Product && $variation->is_purchasable() && $variation->is_in_stock()) {
                return true;
            }
        }

        return false;
    }

    /**
     * [onetrace_widget id="k3x9…"] — a recommendation widget from the "Site" section of the platform. On product
     * and cart pages the current products are passed to the widget automatically.
     *
     * @param array<string, string>|string $attributes
     */
    public static function widgetShortcode($attributes): string
    {
        $attributes = shortcode_atts(['id' => '', 'item' => '', 'category' => ''], \is_array($attributes) ? $attributes : [], 'onetrace_widget');

        return self::widget((string) $attributes['id'], (string) $attributes['item'], (string) $attributes['category']);
    }

    public static function productWidget(): void
    {
        echo self::widget((string) Settings::get('widget_product')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in widget().
    }

    public static function cartWidget(): void
    {
        echo self::widget((string) Settings::get('widget_cart')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in widget().
    }

    /**
     * [onetrace_push_button] — a button that subscribes the browser to Web Push notifications.
     *
     * @param array<string, string>|string $attributes
     */
    public static function pushButton($attributes): string
    {
        $attributes = shortcode_atts(['label' => __('Get notifications', 'onetrace-for-woocommerce')], \is_array($attributes) ? $attributes : [], 'onetrace_push_button');

        return sprintf(
            '<button type="button" class="button onetrace-push" onclick="window.cdp&&cdp.push&&cdp.push.subscribe().then(function(){this.disabled=true}.bind(this))">%s</button>',
            esc_html((string) $attributes['label'])
        );
    }

    /**
     * /cdp-sw.js from the store root: the service worker of Web Push must come from the site's own domain.
     */
    public static function serviceWorker(): void
    {
        $path = (string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '', PHP_URL_PATH);

        if ($path !== '/cdp-sw.js' || Settings::url() === '') {
            return;
        }

        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: max-age=3600');
        echo 'importScripts(' . wp_json_encode(Settings::url() . '/tracker/cdp-sw.js', JSON_UNESCAPED_SLASHES) . ");\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in JavaScript.
        exit;
    }

    private static function widget(string $uid, string $items = '', string $category = ''): string
    {
        if (preg_match('/^[a-z0-9]{1,16}$/', $uid) !== 1) {
            return '';
        }

        $exclude = [];

        if ($items === '' && is_product()) {
            $items = Products::id(get_queried_object_id());
        }

        if (WC()->cart !== null && (is_cart() || is_checkout())) {
            $cart = array_values(array_unique(array_map(static function (array $item): string {
                return Products::id((int) $item['product_id']);
            }, WC()->cart->get_cart())));
            $items = $items !== '' ? $items : implode(',', $cart);
            $exclude = $cart;
        }

        if ($category === '' && is_product_category()) {
            $category = (string) get_queried_object_id();
        }

        return sprintf(
            '<div class="onetrace-widget" data-cdp-widget="%s"%s%s%s></div>',
            esc_attr($uid),
            $items !== '' ? ' data-item="' . esc_attr($items) . '"' : '',
            $category !== '' ? ' data-category="' . esc_attr($category) . '"' : '',
            $exclude !== [] ? ' data-exclude="' . esc_attr(implode(',', $exclude)) . '"' : ''
        );
    }
}
