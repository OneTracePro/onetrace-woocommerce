<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Exception\AuthenticationException;
use OneTrace\Exception\NotFoundException;
use OneTrace\Exception\PaymentRequiredException;
use OneTrace\Exception\PermissionException;

/**
 * The OneTrace search widget (setting "OneTrace search widget", Site → Search of the platform): the product search
 * page keeps the theme's header and footer, and the tracker draws the results, facets, sorting and infinite scroll
 * into <div data-cdp-search-results> and sends the search event itself — the server does not search. The
 * "Add to cart" button of the widget posts to the wc-ajax endpoint of the store; the mini-cart of the theme is
 * refreshed after it. The widget turned off on the platform, the tracker off or waiting for the statistics consent,
 * or any API error — the results page of the store, as without the setting.
 */
final class SearchWidget
{
    public const TRANSIENT = 'onetrace_search_widget';

    /** WooCommerce AJAX action: /?wc-ajax=onetrace_add_to_cart&product={id}. */
    public const CART_ACTION = 'onetrace_add_to_cart';

    /** @var bool|null the widget is on for this request */
    private static $active;

    public static function register(): void
    {
        self::$active = null;

        if (!self::enabled()) {
            return;
        }

        add_action('wc_ajax_' . self::CART_ACTION, [self::class, 'addToCart']);
        // Before the server search of Search::results(): the widget page runs no product query at all.
        add_filter('posts_pre_query', [self::class, 'results'], 5, 2);
        add_filter('template_include', [self::class, 'template'], 100);
    }

    /** The setting is on and the tracker that draws the widget is on the storefront. */
    public static function enabled(): bool
    {
        return Settings::enabled('search_widget') && Settings::enabled('tracker') && Settings::storefront();
    }

    /**
     * The widget draws the search of this visitor: the setting, the statistics consent the tracker waits for and the
     * widget turned on in OneTrace (GET /search/widget, cached for 10 minutes; an error — off for a minute).
     */
    public static function active(): bool
    {
        if (self::$active === null) {
            self::$active = self::enabled() && self::consented() && self::onPlatform();
        }

        return self::$active;
    }

    /** The widget is read again: the plugin settings were saved. */
    public static function forget(): void
    {
        self::$active = null;
        delete_transient(self::TRANSIENT);
    }

    /**
     * No products for the main product search query: the widget searches in the browser.
     *
     * @param array<int, \WP_Post|int>|null $posts
     *
     * @return array<int, \WP_Post|int>|null
     */
    public static function results($posts, \WP_Query $query): ?array
    {
        if ($posts !== null || is_admin() || !$query->is_main_query() || !self::searchPage($query) || !self::active()) {
            return $posts;
        }

        $query->found_posts = 0;
        $query->max_num_pages = 0;
        // WP_Query caches whatever posts_pre_query returns: not the empty page as the store search after the widget.
        $query->set('cache_results', false);

        return [];
    }

    /**
     * The theme's template with the widget instead of the results: block themes keep their header and footer
     * template parts, classic themes get templates/search-results.php with get_header() and get_footer().
     */
    public static function template(string $template): string
    {
        global $wp_query, $_wp_current_template_content;

        if (!$wp_query instanceof \WP_Query || !self::searchPage($wp_query) || !self::active()) {
            return $template;
        }

        add_action('wp_footer', [self::class, 'miniCart']);

        if (basename($template) === 'template-canvas.php') {
            $_wp_current_template_content = self::blockTemplate((string) $_wp_current_template_content);

            return $template;
        }

        return \dirname(ONETRACE_WC_FILE) . '/templates/search-results.php';
    }

    /**
     * Template parts of the block template (header, footer) around the main group with the widget.
     */
    public static function blockTemplate(string $content): string
    {
        $main = sprintf(
            "<!-- wp:group {\"tagName\":\"main\",\"layout\":{\"inherit\":true,\"type\":\"constrained\"}} -->\n<main class=\"wp-block-group\">\n<!-- wp:html -->\n%s\n<!-- /wp:html -->\n</main>\n<!-- /wp:group -->\n",
            self::container()
        );
        $html = '';
        $parts = 0;
        $placed = false;

        // The main group takes the place of the first block that is not a template part.
        foreach (parse_blocks($content) as $block) {
            if ($block['blockName'] === 'core/template-part') {
                $html .= serialize_block($block) . "\n";
                ++$parts;
            } elseif ($block['blockName'] !== null && !$placed) {
                $html .= $main;
                $placed = true;
            }
        }

        // A template without top-level template parts (everything in one group): the usual header and footer.
        if ($parts === 0 || !$placed) {
            return "<!-- wp:template-part {\"slug\":\"header\",\"tagName\":\"header\"} /-->\n" . $main . "<!-- wp:template-part {\"slug\":\"footer\",\"tagName\":\"footer\"} /-->\n";
        }

        return $html;
    }

    /**
     * The place of the results: the query is the "s" parameter, the button adds to the cart of this store.
     */
    public static function container(): string
    {
        return sprintf(
            '<div class="onetrace-search-results alignwide" data-cdp-search-results data-query-param="s" data-cart-url="%s"></div>',
            esc_attr(self::cartUrl())
        );
    }

    /** The add to cart endpoint with {id} — the id of the product in the OneTrace catalog. */
    public static function cartUrl(): string
    {
        return add_query_arg('product', '{id}', \WC_AJAX::get_endpoint(self::CART_ACTION));
    }

    /**
     * After "Add to cart" of the widget: the cart fragments of classic themes and the Mini-Cart block reload the cart.
     */
    public static function miniCart(): void
    {
        wp_print_inline_script_tag(
            "addEventListener('cdp:add-to-cart',function(){if(window.jQuery)jQuery(document.body).trigger('wc_fragment_refresh');"
            . "document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart',{bubbles:true,cancelable:true,detail:{preserveCartData:false}}))});",
            ['id' => 'onetrace-search-cart']
        );
    }

    /**
     * POST /?wc-ajax=onetrace_add_to_cart&product={id} with quantity. Only with X-Requested-With: a form of another
     * site cannot set the header, and a script of another site cannot without a CORS preflight the store never allows.
     */
    public static function addToCart(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD'])) : '';
        $requestedWith = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? sanitize_key(wp_unslash((string) $_SERVER['HTTP_X_REQUESTED_WITH'])) : '';

        if ($method !== 'post' || $requestedWith !== 'xmlhttprequest') {
            wp_send_json(['ok' => false], 400);
        }

        // phpcs:disable WordPress.Security.NonceVerification -- X-Requested-With above: cached pages carry no valid nonce.
        $id = isset($_GET['product']) ? sanitize_text_field(wp_unslash((string) $_GET['product'])) : '';
        $quantity = isset($_POST['quantity']) ? absint(wp_unslash((string) $_POST['quantity'])) : 1;
        // phpcs:enable WordPress.Security.NonceVerification

        [$status, $body] = self::add($id, $quantity);
        wp_send_json($body, $status);
    }

    /**
     * Adds a product of the catalog to the cart: {ok: true, count} — the number of items in the cart; {ok: false,
     * redirect} — the product page when the visitor chooses options there (a variable or grouped product, not for
     * sale, out of stock, rejected by the add to cart validation), the shop page for an unknown product.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function add(string $id, int $quantity): array
    {
        $postIds = $id !== '' ? Products::postIds([$id]) : [];
        $product = $postIds !== [] ? wc_get_product($postIds[0]) : null;

        if (!$product instanceof \WC_Product || $product->get_status() !== 'publish' || WC()->cart === null) {
            return [404, ['ok' => false, 'redirect' => wc_get_page_permalink('shop')]];
        }

        $page = [200, ['ok' => false, 'redirect' => $product->get_permalink()]];

        if (!$product->supports('ajax_add_to_cart') || !$product->is_purchasable() || !$product->is_in_stock()) {
            return $page;
        }

        $productId = (int) apply_filters('woocommerce_add_to_cart_product_id', $product->get_id());
        $quantity = max(1, $quantity);

        // The usual checks of WooCommerce and other plugins: stock, purchase limits, required fields.
        if (!apply_filters('woocommerce_add_to_cart_validation', true, $productId, $quantity) || WC()->cart->add_to_cart($productId, $quantity) === false) {
            return $page;
        }

        do_action('woocommerce_ajax_added_to_cart', $productId);

        return [200, ['ok' => true, 'count' => WC()->cart->get_cart_contents_count()]];
    }

    /**
     * The product search page: the main query searches products (the search form of WooCommerce and themes).
     */
    private static function searchPage(\WP_Query $query): bool
    {
        $type = $query->get('post_type');

        return $query->is_search() && ($type === 'product' || $type === ['product']);
    }

    /**
     * The tracker that draws the widget is loaded: it waits for the statistics consent of the WP Consent API.
     */
    private static function consented(): bool
    {
        return !Settings::enabled('wait_for_consent') || !\function_exists('wp_has_consent') || wp_has_consent('statistics');
    }

    private static function onPlatform(): bool
    {
        $cached = get_transient(self::TRANSIENT);

        if (\is_array($cached)) {
            return ($cached['enabled'] ?? false) === true;
        }

        try {
            $widget = Connection::client('search')->search()->widget(get_locale());
            $enabled = $widget['enabled'] === true;
            set_transient(self::TRANSIENT, ['enabled' => $enabled], 10 * MINUTE_IN_SECONDS);

            return $enabled;
        } catch (\Throwable $error) {
            // The plan without search or a wrong key does not change in a minute.
            $lasting = $error instanceof PaymentRequiredException || $error instanceof AuthenticationException || $error instanceof PermissionException || $error instanceof NotFoundException;
            Queue::log('The search widget is off: ' . $error->getMessage());
            set_transient(self::TRANSIENT, ['enabled' => false], $lasting ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS);

            return false;
        }
    }
}
