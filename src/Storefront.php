<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

/**
 * The storefront: the tracker (after the analytics consent when the WP Consent API is in use), product views,
 * linking the browser to the signed-in customer, recommendation widgets and Web Push.
 */
final class Storefront
{
    /** Methods the stub queues until cdp.js loads: the same list as in the platform's install code. */
    private const STUB_METHODS = ['init', 'page', 'track', 'identify', 'alias', 'reset', 'setLanguage', 'widgets', 'renderRecommendations'];

    public static function register(): void
    {
        if (Settings::storefront() && Settings::enabled('tracker')) {
            add_action('wp_head', [self::class, 'tracker'], 1);
            add_action('wp_footer', [self::class, 'productView']);
        }

        add_shortcode('onetrace_widget', [self::class, 'widgetShortcode']);
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
        $attributes = shortcode_atts(['label' => __('Get notifications', 'onetrace-woocommerce')], \is_array($attributes) ? $attributes : [], 'onetrace_push_button');

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
