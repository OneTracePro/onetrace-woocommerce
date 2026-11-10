<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\WooCommerce\Search;
use OneTrace\WooCommerce\SearchWidget;
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
        // AI search is off in the project unless a test turns it on.
        set_transient(SearchWidget::TRANSIENT, ['enabled' => false, 'ai' => null], HOUR_IN_SECONDS);
    }

    protected function tearDown(): void
    {
        foreach (Search::AI_PARAMS as $param) {
            unset($_GET[$param]);
        }

        delete_transient(SearchWidget::TRANSIENT);
        parent::tearDown();
    }

    /**
     * AI search on in the project; $ai answers POST /search/ai, $usual — GET /search.
     *
     * @param array<string, mixed> $ai
     * @param array<string, mixed> $usual
     * @param list<array<string, mixed>> $bodies the bodies of POST /search/ai
     */
    private function respondAi(array $ai, array $usual, array &$bodies): void
    {
        set_transient(SearchWidget::TRANSIENT, ['enabled' => false, 'ai' => ['mode' => 'auto', 'answer' => true]], HOUR_IN_SECONDS);
        $urls = &$this->urls;
        $this->platform->responder = static function (Request $request) use ($ai, $usual, &$urls, &$bodies): ?Response {
            if (strpos($request->getUrl(), '/api/v1/search') === false) {
                return null;
            }

            $urls[] = $request->getUrl();
            $isAi = strpos($request->getUrl(), '/api/v1/search/ai') !== false;

            if ($isAi) {
                $bodies[] = (array) json_decode((string) $request->getBody(), true);
            }

            return new Response(200, ['content-type' => 'application/json'], (string) json_encode($isAi ? $ai : $usual));
        };
    }

    public function testShowsTheProductsAiFoundWithTheAiBlockAboveThem(): void
    {
        $boot = $this->product(['name' => 'Hiking Boot']);
        $runner = $this->product(['name' => 'Trail Runner']);
        $bodies = [];
        $_COOKIE['cdp_aid'] = 'browser-1';
        $state = ['text' => 'hiking shoes', 'categories' => ['shoes'], 'brands' => [], 'attributes' => [], 'price_min' => null, 'price_max' => 700, 'exclude' => ['contoso'], 'sort' => 'relevance', 'refinements' => []];
        $this->respondAi(['request_id' => 'req-ai', 'total' => 2, 'items' => [['id' => (string) $runner->get_id()], ['id' => (string) $boot->get_id()]], 'ai' => [
            'understood' => [['type' => 'price', 'key' => 'price', 'label' => '–700']], 'refinements' => ['waterproof'], 'relaxed' => 0, 'state' => $state,
        ]], ['total' => 0, 'items' => []], $bodies);

        $query = $this->searchPage(['s' => 'hiking shoes under 700, not Contoso', 'posts_per_page' => 12]);
        $this->flush();
        $block = Search::pendingAiBlock();

        self::assertSame([$runner->get_id(), $boot->get_id()], wp_list_pluck($query->posts, 'ID'));
        self::assertCount(1, $this->urls, 'The usual search is not asked.');
        self::assertSame('hiking shoes under 700, not Contoso', $bodies[0]['q']);
        self::assertSame(12, $bodies[0]['per_page']);
        self::assertSame('browser-1', $bodies[0]['anonymousId']);
        self::assertSame(['query' => 'hiking shoes under 700, not Contoso', 'results' => 2, 'request_id' => 'req-ai'], $this->platform->events('search')[0]['properties']);
        self::assertStringStartsWith('<div class="onetrace-search-ai" data-cdp-search-ai="', $block);
        preg_match('/data-cdp-search-ai="([^"]*)"/', $block, $match);
        $data = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
        self::assertSame(['hiking shoes under 700, not Contoso'], $data['turns']);
        self::assertSame([(string) $runner->get_id(), (string) $boot->get_id()], $data['products']);
        self::assertSame($state, $data['state']);
        self::assertSame(['waterproof'], $data['refinements']);

        // The block is printed once, before the products.
        ob_start();
        Search::aiBlock();
        self::assertSame($block, ob_get_clean());
        self::assertSame('', Search::pendingAiBlock());
    }

    public function testRefinesRemovesChipsAndTurnsAiOffByTheLinksOfTheBlock(): void
    {
        $dress = $this->product(['name' => 'Linen Dress']);
        $bodies = [];
        $encode = static function (array $value): string {
            return rtrim(strtr(base64_encode((string) json_encode($value)), '+/', '-_'), '=');
        };
        $this->respondAi(['ai' => null], ['total' => 1, 'items' => [['id' => (string) $dress->get_id()]]], $bodies);

        // A refinement: the phrase with the previous parse; AI does not want it — the usual search of the query.
        $_GET['ai_refine'] = 'cheaper';
        $_GET['ai_previous'] = $encode(['text' => 'dress']);
        $query = $this->searchPage(['s' => 'linen dress']);
        self::assertSame([$dress->get_id()], wp_list_pluck($query->posts, 'ID'));
        self::assertSame(['q' => 'cheaper', 'previous' => ['text' => 'dress']], array_intersect_key($bodies[0], ['q' => 1, 'previous' => 1]));
        self::assertStringContainsString('q=linen%20dress', $this->urls[1]);
        self::assertSame('', Search::pendingAiBlock());

        // A removed chip: the parse without it; a broken parameter is not sent.
        unset($_GET['ai_refine'], $_GET['ai_previous']);
        $_GET['ai_state'] = $encode(['text' => 'dress', 'brands' => []]);
        $this->searchPage(['s' => 'linen dress']);
        self::assertSame(['text' => 'dress', 'brands' => []], $bodies[1]['state']);
        $_GET['ai_state'] = 'not json!';
        $this->searchPage(['s' => 'linen dress']);
        self::assertArrayNotHasKey('state', $bodies[2]);

        // "Regular search": AI is not asked.
        $_GET['ai'] = '0';
        $this->searchPage(['s' => 'linen dress']);
        self::assertCount(3, $bodies);
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
        self::assertStringContainsString('language=' . get_locale(), $this->urls[0]);
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

    public function testSearchesTheVisitorsTextWhenATranslationPluginEmptiedTheQuery(): void
    {
        $dress = $this->product(['name' => 'Linen Dress']);
        $this->respond(200, ['total' => 1, 'items' => [['id' => (string) $dress->get_id()]]]);
        // TranslatePress on /ru/: "s" is emptied, the posts of its dictionary search go to post__in.
        $translatePress = static function (\WP_Query $query): void {
            $query->set('s', '');
            $query->set('post__in', [PHP_INT_MAX]);
        };
        add_action('pre_get_posts', $translatePress, 99999999);

        $query = $this->searchPage(['s' => 'платье']);
        remove_action('pre_get_posts', $translatePress, 99999999);

        self::assertSame([$dress->get_id()], wp_list_pluck($query->posts, 'ID'));
        self::assertStringContainsString('q=' . rawurlencode('платье'), $this->urls[0]);
    }

    public function testOpensThePageOfARedirectRuleInsteadOfTheResults(): void
    {
        $dress = $this->product(['name' => 'Linen Dress']);
        $this->respond(200, ['total' => 1, 'items' => [['id' => (string) $dress->get_id()]], 'redirect' => 'https://shop.example.com/delivery']);

        $this->searchPage(['s' => 'delivery']);

        self::assertSame('https://shop.example.com/delivery', Search::pendingRedirect());

        $this->respond(200, ['total' => 1, 'items' => [['id' => (string) $dress->get_id()]], 'redirect' => 'javascript:alert(1)']);
        $this->searchPage(['s' => 'linen']);

        self::assertNull(Search::pendingRedirect());
    }

    public function testFallsBackToTheWordPressSearchWhenThePlatformFails(): void
    {
        $dress = $this->product(['name' => 'Linen Dress']);
        $this->respond(500, ['message' => 'Server error']);

        $query = $this->searchPage(['s' => 'Linen']);

        self::assertSame([$dress->get_id()], wp_list_pluck($query->posts, 'ID'));
    }

    public function testFallsBackToTheWordPressSearchOverTheMonthlySearchLimitWithoutRetrying(): void
    {
        $dress = $this->product(['name' => 'Linen Dress']);
        $this->respond(402, ['code' => 'quota_exceeded', 'message' => 'The monthly limit of search requests is spent.']);

        $query = $this->searchPage(['s' => 'Linen']);
        $this->flush();

        self::assertSame([$dress->get_id()], wp_list_pluck($query->posts, 'ID'));
        self::assertCount(1, $this->urls, 'A 402 is not retried.');
        self::assertSame([], $this->platform->events('search'));
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
