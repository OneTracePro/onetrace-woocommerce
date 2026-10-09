<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\WooCommerce\Search;
use OneTrace\WooCommerce\Settings;
use OneTrace\WooCommerce\Tests\TestCase;

final class SearchTest extends TestCase
{
    /** @var list<string> */
    private $urls = [];

    protected function setUp(): void
    {
        parent::setUp();
        update_option(Settings::OPTION, ['search' => 'yes'] + (array) get_option(Settings::OPTION));
        remove_all_filters('posts_pre_query');
        Search::register();
    }

    /**
     * The main product search query of a results page.
     *
     * @param array<string, mixed> $vars
     */
    private function searchPage(array $vars): \WP_Query
    {
        $query = new \WP_Query();
        $GLOBALS['wp_the_query'] = $query;
        $query->query($vars + ['post_type' => 'product', 'post_status' => 'publish']);

        return $query;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body = []): void
    {
        $urls = &$this->urls;
        $this->platform->responder = static function (Request $request) use ($status, $body, &$urls): ?Response {
            if (strpos($request->getUrl(), '/api/v1/search') === false) {
                return null;
            }

            $urls[] = $request->getUrl();

            return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
        };
    }

    public function testShowsProductsOfThePlatformInItsOrderWithItsPagesAndCountsTheSearch(): void
    {
        $dress = $this->product(['name' => 'Linen Dress']);
        $skirt = $this->product(['name' => 'Linen Skirt']);
        $_COOKIE['cdp_aid'] = 'browser-1';
        $this->respond(200, ['request_id' => 'req-1', 'total' => 7, 'items' => [['id' => (string) $skirt->get_id()], ['id' => (string) $dress->get_id()], ['id' => '999999']]]);

        // Опечатка: обычный поиск WordPress не нашёл бы ничего.
        $query = $this->searchPage(['s' => 'lnen dres', 'posts_per_page' => 2]);
        $this->flush();
        $search = $this->platform->events('search');

        self::assertSame([$skirt->get_id(), $dress->get_id()], wp_list_pluck($query->posts, 'ID'));
        self::assertSame(7, $query->found_posts);
        self::assertSame(4, $query->max_num_pages);
        self::assertStringContainsString('q=lnen%20dres', $this->urls[0]);
        self::assertStringContainsString('per_page=2', $this->urls[0]);
        self::assertStringContainsString('anonymousId=browser-1', $this->urls[0]);
        self::assertCount(1, $search);
        self::assertSame(['query' => 'lnen dres', 'results' => 7, 'request_id' => 'req-1'], $search[0]['properties']);
        self::assertContract($search[0]);

        // Вторая страница и сортировка магазина — тем же запросом к платформе, без нового события.
        $_GET['orderby'] = 'price';
        $this->searchPage(['s' => 'lnen dres', 'posts_per_page' => 2, 'paged' => 2]);
        unset($_GET['orderby']);
        $this->flush();

        self::assertStringContainsString('page=2', $this->urls[1]);
        self::assertStringContainsString('sort=price_asc', $this->urls[1]);
        self::assertCount(1, $this->platform->events('search'));
    }

    public function testFallsBackToTheWordPressSearchWhenThePlatformFails(): void
    {
        $dress = $this->product(['name' => 'Linen Dress']);
        $this->respond(500, ['message' => 'Server error']);

        $query = $this->searchPage(['s' => 'Linen']);

        self::assertSame([$dress->get_id()], wp_list_pluck($query->posts, 'ID'));
    }

    public function testLeavesOtherQueriesAlone(): void
    {
        $this->respond(200, ['total' => 0, 'items' => []]);
        $this->product(['name' => 'Linen Dress']);

        $this->searchPage(['s' => 'Linen', 'post_type' => 'post']);
        (new \WP_Query())->query(['s' => 'Linen', 'post_type' => 'product']);

        self::assertSame([], $this->urls);
    }

    public function testAddsSuggestionsToSearchFormsAndOpensCategoriesFromThem(): void
    {
        $html = Search::form('<form><input type="search" class="search-field" name="s" value=""><input type="hidden" name="post_type" value="product"></form>');

        self::assertSame(1, substr_count($html, 'data-cdp-search'));
        self::assertStringContainsString('data-category-url="' . esc_attr(home_url('/?onetrace_category={id}')) . '"', $html);
        self::assertSame($html, Search::form($html));
        self::assertStringContainsString('data-cdp-search', Search::block('<input name="s">', ['blockName' => 'woocommerce/product-search']));
        self::assertSame('<input name="s">', Search::block('<input name="s">', ['blockName' => 'core/paragraph']));
    }
}
