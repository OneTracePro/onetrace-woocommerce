<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\WooCommerce\Search;
use OneTrace\WooCommerce\SearchWidget;
use OneTrace\WooCommerce\Settings;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * The OneTrace search widget: the search page of the theme with the results container instead of the store's
 * results and no server search; the widget off or OneTrace unavailable — the page as before; the add to cart
 * endpoint of the widget button.
 */
final class SearchWidgetTest extends TestCase
{
    private const CLASSIC = '/var/www/html/wp-content/plugins/woocommerce/templates/archive-product.php';

    private const CANVAS = '/var/www/html/wp-includes/template-canvas.php';

    /** @var array<string, mixed>|null answer of GET /search/widget; null — 500 */
    private $widget = ['enabled' => true, 'cart_url' => null];

    /** @var string */
    private $widgetUrl = '';

    protected function setUp(): void
    {
        parent::setUp();
        update_option(Settings::OPTION, ['search' => 'yes', 'search_widget' => 'yes'] + (array) get_option(Settings::OPTION));
        SearchWidget::forget();
        remove_all_filters('posts_pre_query');
        Search::register();
        SearchWidget::register();

        $this->platform->responder = function (Request $request): ?Response {
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);

            if ($path === '/api/v1/search/widget') {
                $this->widgetUrl = $request->getUrl();

                return $this->widget !== null
                    ? new Response(200, ['content-type' => 'application/json'], (string) json_encode($this->widget))
                    : new Response(500, ['content-type' => 'application/json'], '{"message":"Server error"}');
            }

            if ($path === '/api/v1/search') {
                return new Response(200, ['content-type' => 'application/json'], '{"total":0,"items":[]}');
            }

            return null;
        };
        wc_load_cart();
        WC()->cart->empty_cart();
    }

    protected function tearDown(): void
    {
        WC()->cart->empty_cart();
        remove_filter('posts_pre_query', [SearchWidget::class, 'results'], 5);
        remove_filter('template_include', [SearchWidget::class, 'template'], 100);
        remove_action('wp_footer', [SearchWidget::class, 'miniCart']);
        unset($GLOBALS['_wp_current_template_content']);
        SearchWidget::forget();
        parent::tearDown();
    }

    /**
     * The main query of the product search page.
     */
    private function searchPage(string $text): \WP_Query
    {
        $query = new \WP_Query();
        $GLOBALS['wp_the_query'] = $query;
        $GLOBALS['wp_query'] = $query;
        $query->query(['s' => $text, 'post_type' => 'product', 'post_status' => 'publish']);

        return $query;
    }

    /**
     * Paths of the search requests to the platform.
     *
     * @return list<string>
     */
    private function searches(): array
    {
        return array_values(array_filter(array_column($this->platform->requests, 'path'), static function (string $path): bool {
            return strpos($path, '/api/v1/search') === 0;
        }));
    }

    public function testTheSearchPageShowsTheWidgetInsteadOfTheResultsAndDoesNotSearchOnTheServer(): void
    {
        $this->product(['name' => 'Linen Dress']);
        $_COOKIE['cdp_aid'] = 'browser-1';

        $query = $this->searchPage('Linen');
        $this->flush();
        $container = '<div class="onetrace-search-results alignwide" data-cdp-search-results data-query-param="s" data-cart-url="' . esc_attr(home_url('/', 'relative') . '?wc-ajax=onetrace_add_to_cart&product={id}') . '"></div>';

        self::assertSame([], $query->posts);
        self::assertSame(['/api/v1/search/widget'], $this->searches(), 'No GET /search: the tracker searches.');
        self::assertSame([], $this->platform->events('search'), 'The tracker sends the search event.');
        self::assertSame($container, SearchWidget::container());
        self::assertSame(\dirname(ONETRACE_WC_FILE) . '/templates/search-results.php', SearchWidget::template(self::CLASSIC));
        self::assertSame(10, has_action('wp_footer', [SearchWidget::class, 'miniCart']));

        // Block theme: header and footer template parts of the template, the widget instead of the product collection.
        $GLOBALS['_wp_current_template_content'] = "<!-- wp:template-part {\"slug\":\"header\"} /-->\n\n"
            . "<!-- wp:group {\"tagName\":\"main\"} -->\n<main class=\"wp-block-group\"><!-- wp:woocommerce/product-collection /--></main>\n<!-- /wp:group -->\n\n"
            . "<!-- wp:template-part {\"slug\":\"footer\"} /-->";

        self::assertSame(self::CANVAS, SearchWidget::template(self::CANVAS));
        $blocks = array_values(array_filter(parse_blocks($GLOBALS['_wp_current_template_content']), static function (array $block): bool {
            return $block['blockName'] !== null;
        }));
        self::assertSame(['core/template-part', 'core/group', 'core/template-part'], array_column($blocks, 'blockName'));
        self::assertSame(['header', 'footer'], [$blocks[0]['attrs']['slug'], $blocks[2]['attrs']['slug']]);
        self::assertStringContainsString($container, $GLOBALS['_wp_current_template_content']);
        self::assertStringNotContainsString('product-collection', $GLOBALS['_wp_current_template_content']);
        self::assertStringContainsString($container, do_blocks($GLOBALS['_wp_current_template_content']));

        // The answer of the platform is cached: the next page asks nothing.
        SearchWidget::register();
        $this->searchPage('Linen');
        self::assertSame(['/api/v1/search/widget'], $this->searches());
        self::assertStringContainsString('language=' . get_locale(), $this->widgetUrl, 'The texts of the widget in the page language.');
    }

    public function testSearchFieldsGetTheLayerOfTheWidgetAlsoWithoutTheProductSearchSetting(): void
    {
        update_option(Settings::OPTION, ['search' => 'no'] + (array) get_option(Settings::OPTION));
        $form = '<form><input type="search" name="s" value=""></form>';

        self::assertStringContainsString('data-cdp-search', Search::form($form));

        $this->widget = ['enabled' => false];
        SearchWidget::forget();

        self::assertSame($form, Search::form($form), 'Without the widget and the product search the form stays as it is.');
    }

    public function testTheWidgetOffOrOneTraceUnavailableLeavesTheSearchPageAsBefore(): void
    {
        $dress = $this->product(['name' => 'Linen Dress']);
        self::assertSame([], $this->searchPage('Linen')->posts, 'The widget page.');

        foreach ([['enabled' => false], null] as $widget) {
            $this->widget = $widget;
            SearchWidget::forget();
            $this->platform->requests = [];

            $query = $this->searchPage('Linen');

            self::assertSame(['/api/v1/search/widget', '/api/v1/search'], $this->searches(), 'The server search of the store.');
            self::assertSame(self::CLASSIC, SearchWidget::template(self::CLASSIC));
            self::assertSame(0, $query->found_posts);
        }

        // Without the product search setting: the usual WordPress search.
        update_option(Settings::OPTION, ['search' => 'no'] + (array) get_option(Settings::OPTION));
        remove_all_filters('posts_pre_query');
        SearchWidget::register();

        self::assertSame([$dress->get_id()], wp_list_pluck($this->searchPage('Linen')->posts, 'ID'), 'Not the answer of the platform from the query cache.');
    }

    public function testTheButtonAddsASimpleProductAndAnswersTheNumberOfItemsInTheCart(): void
    {
        $sneakers = $this->product();
        $socks = $this->product(['name' => 'Socks', 'sku' => 'SOCKS-1']);

        self::assertSame([200, ['ok' => true, 'count' => 2]], SearchWidget::add((string) $sneakers->get_id(), 2));
        self::assertSame([200, ['ok' => true, 'count' => 3]], SearchWidget::add((string) $sneakers->get_id(), 0), 'At least one.');

        // The SKU mode: the id of the catalog is the SKU.
        update_option(Settings::OPTION, ['product_id' => 'sku'] + (array) get_option(Settings::OPTION));

        self::assertSame([200, ['ok' => true, 'count' => 4]], SearchWidget::add('SOCKS-1', 1));
        self::assertSame([$sneakers->get_id(), $socks->get_id()], array_values(array_unique(wp_list_pluck(WC()->cart->get_cart(), 'product_id'))));
    }

    public function testProductsWithOptionsOrNotForSaleOpenTheirPageAndUnknownOnesTheShop(): void
    {
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name('Size');
        $attribute->set_options(['40', '41']);
        $attribute->set_variation(true);
        $variable = new \WC_Product_Variable();
        $variable->set_props(['name' => 'Runner', 'status' => 'publish', 'attributes' => [$attribute]]);
        $variable->save();
        $this->remember($variable->get_id());
        $variation = new \WC_Product_Variation();
        $variation->set_props(['parent_id' => $variable->get_id(), 'regular_price' => '59.00', 'attributes' => ['size' => '40']]);
        $variation->save();
        \WC_Product_Variable::sync($variable->get_id());

        $outOfStock = $this->product(['stock_status' => 'outofstock']);
        $limited = $this->product(['name' => 'Limited']);
        $limit = static function (bool $passed, int $productId) use ($limited): bool {
            return $passed && $productId !== $limited->get_id();
        };
        add_filter('woocommerce_add_to_cart_validation', $limit, 10, 2);

        self::assertSame([200, ['ok' => false, 'redirect' => $variable->get_permalink()]], SearchWidget::add((string) $variable->get_id(), 1));
        self::assertSame([200, ['ok' => false, 'redirect' => $outOfStock->get_permalink()]], SearchWidget::add((string) $outOfStock->get_id(), 1));
        self::assertSame([200, ['ok' => false, 'redirect' => $limited->get_permalink()]], SearchWidget::add((string) $limited->get_id(), 1), 'The add to cart validation of WooCommerce and plugins.');
        self::assertSame([404, ['ok' => false, 'redirect' => wc_get_page_permalink('shop')]], SearchWidget::add('999999999', 1));
        self::assertSame([404, ['ok' => false, 'redirect' => wc_get_page_permalink('shop')]], SearchWidget::add('', 1));
        self::assertTrue(WC()->cart->is_empty());

        remove_filter('woocommerce_add_to_cart_validation', $limit, 10);
    }

    public function testTheEndpointOfTheStoreAnswersOnlyRequestsWithXRequestedWith(): void
    {
        $product = $this->product();
        $url = add_query_arg(['wc-ajax' => SearchWidget::CART_ACTION, 'product' => $product->get_id()], 'http://localhost/');

        $plain = wp_remote_post($url, ['body' => ['quantity' => 1]]);
        $get = wp_remote_get($url, ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
        $added = wp_remote_post($url, ['body' => ['quantity' => 2], 'headers' => ['X-Requested-With' => 'XMLHttpRequest']]);

        self::assertSame(400, wp_remote_retrieve_response_code($plain));
        self::assertSame(['ok' => false], json_decode(wp_remote_retrieve_body($plain), true));
        self::assertSame(400, wp_remote_retrieve_response_code($get));
        self::assertSame(200, wp_remote_retrieve_response_code($added));
        self::assertSame(['ok' => true, 'count' => 2], json_decode(wp_remote_retrieve_body($added), true));
        self::assertStringContainsString('application/json', wp_remote_retrieve_header($added, 'content-type'));
        self::assertNotEmpty(array_filter(wp_remote_retrieve_cookies($added), static function (\WP_Http_Cookie $cookie): bool {
            return strpos($cookie->name, 'wp_woocommerce_session_') === 0;
        }), 'The guest keeps the cart in the WooCommerce session.');
    }
}
