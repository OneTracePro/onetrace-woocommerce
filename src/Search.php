<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\Customer;

/**
 * Product search of the store from OneTrace: the results page keeps the theme template, but its products and their
 * order come from the platform (word forms, typos, the wrong keyboard layout, the visitor's interests); the search
 * box gets suggestions of the tracker (data-cdp-search). Any API error falls back to the usual WordPress search.
 * A query with a redirect rule (Site → Search of the platform) opens its page instead of the results. With the
 * OneTrace search widget (SearchWidget) the results page is drawn by the tracker and the server does not search.
 *
 * AI search (the mode of Site → Search on the platform): the query goes to AI first — it reads it as catalog filters
 * and the page shows the products found by them; the parse is waited for up to AI_WAIT seconds, otherwise (or when
 * the mode does not want the query) the usual results. Above the products of the first page — <div
 * data-cdp-search-ai>: the tracker writes the short AI answer there and draws the chips of what AI understood and the
 * refinements; they open the results again with ai_state (a removed chip), ai_refine + ai_previous (a refinement) or
 * ai=0 (the usual search).
 */
final class Search
{
    /** /?onetrace_category={id}: a category from the suggestions → its page (the tracker knows only the id). */
    public const CATEGORY_PARAM = 'onetrace_category';

    private const MAX_PER_PAGE = 48;

    /** Seconds the results page waits for the AI parse of a query that is not in the cache yet. */
    public const AI_WAIT = 2.5;

    /** Parameters of the AI block links (the tracker sets them). */
    public const AI_PARAMS = ['ai', 'ai_state', 'ai_refine', 'ai_previous'];

    /** @var array<string, mixed>|null the AI block of this results page */
    private static $aiBlock;

    /** @var string|null the page of a redirect rule for the current search */
    private static $redirect;

    public static function register(): void
    {
        $search = Settings::enabled('search');

        if (!($search || SearchWidget::enabled()) || !Settings::storefront()) {
            return;
        }

        if ($search) {
            add_filter('posts_pre_query', [self::class, 'results'], 10, 2);
            add_action('template_redirect', [self::class, 'redirect']);
        }

        add_action('woocommerce_before_shop_loop', [self::class, 'aiBlock'], 5);
        add_filter('get_product_search_form', [self::class, 'form']);
        add_filter('get_search_form', [self::class, 'form']);
        add_filter('render_block', [self::class, 'block'], 10, 2);
        add_action('template_redirect', [self::class, 'category']);
    }

    /**
     * Products of the main product search query from OneTrace; null — the query runs as usual.
     *
     * @param array<int, \WP_Post|int>|null $posts
     *
     * @return array<int, \WP_Post|int>|null
     */
    public static function results($posts, \WP_Query $query): ?array
    {
        if ($posts !== null || is_admin() || !$query->is_main_query() || !$query->is_search() || !self::forProducts($query)) {
            return $posts;
        }

        // TranslatePress empties "s" on other languages and searches its own dictionary: the visitor's text stays in
        // the original query.
        $text = trim((string) ($query->get('s') ?: ($query->query['s'] ?? '')));

        if ($text === '') {
            return null;
        }

        $page = max(1, (int) $query->get('paged'));
        $perPage = (int) $query->get('posts_per_page');
        $perPage = $perPage > 0 ? min(self::MAX_PER_PAGE, $perPage) : 12;

        $params = array_filter([
            'page' => $page,
            'per_page' => $perPage,
            'sort' => self::sort(self::orderby($query)),
            'anonymousId' => Connection::anonymousId(),
            // The page language (TranslatePress, WPML, Polylang): names and links of the catalog translations.
            'language' => get_locale(),
        ]);
        self::$aiBlock = null;

        try {
            $result = self::ai($text, $params) ?? Connection::client('search')->search()->products($text, $params);
        } catch (\Throwable $e) {
            Queue::log('Search fell back to WordPress: ' . $e->getMessage());

            return null;
        }

        $ids = Products::postIds(array_values(array_map(static function (array $item): string {
            return (string) ($item['id'] ?? '');
        }, (array) ($result['items'] ?? []))));

        $query->found_posts = (int) ($result['total'] ?? 0);
        $query->max_num_pages = (int) ceil($query->found_posts / $perPage);
        // WP_Query caches whatever posts_pre_query returns: with a persistent object cache the platform's answer
        // would come back as the WordPress search after an error or with the search turned off.
        $query->set('cache_results', false);

        if ($page === 1) {
            self::track($text, $query->found_posts, (string) ($result['request_id'] ?? ''));
        }

        // Only http(s) pages: esc_url_raw() drops other schemes.
        $redirect = esc_url_raw((string) ($result['redirect'] ?? ''), ['http', 'https']);
        self::$redirect = $redirect !== '' ? $redirect : null;

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(array_map('get_post', $ids), static function ($post): bool {
            return $post instanceof \WP_Post;
        }));
    }

    /**
     * AI results of the query: the AI search mode is on in the project and wants the query, the parse came in AI_WAIT
     * seconds; null — the usual search. The first page remembers the AI block.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private static function ai(string $text, array $params): ?array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- public links of the results page.
        $off = isset($_GET['ai']) && sanitize_key(wp_unslash((string) $_GET['ai'])) === '0';
        $asked = isset($_GET['ai']) && sanitize_key(wp_unslash((string) $_GET['ai'])) === '1';
        $refine = isset($_GET['ai_refine']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['ai_refine']))) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $state = self::decoded('ai_state');
        $previous = $refine !== '' ? self::decoded('ai_previous') : null;

        if ($off || SearchWidget::aiMode() === null) {
            return null;
        }

        $ask = $params + array_filter(['asked' => $asked, 'state' => $state, 'previous' => $previous]);
        $question = $previous !== null ? mb_substr($refine, 0, 200) : $text;

        try {
            $result = Connection::client('search')->search()->ai($question, $ask, self::AI_WAIT);
        } catch (\Throwable $e) {
            Queue::log('AI search fell back to the usual search: ' . $e->getMessage());

            return null;
        }

        $ai = $result['ai'] ?? null;

        if (!\is_array($ai)) {
            return null;
        }

        if ((int) ($params['page'] ?? 1) === 1) {
            self::$aiBlock = [
                'url' => add_query_arg(['s' => $text, 'post_type' => 'product'], home_url('/')),
                'turns' => $previous !== null ? [$text, $question] : [$text],
                'state' => $ai['state'] ?? null,
                'understood' => $ai['understood'] ?? [],
                'refinements' => $ai['refinements'] ?? [],
                'relaxed' => (int) ($ai['relaxed'] ?? 0),
                'products' => array_slice(array_values(array_map(static function (array $item): string {
                    return (string) ($item['id'] ?? '');
                }, (array) ($result['items'] ?? []))), 0, 6),
            ];
        }

        return $result;
    }

    /**
     * The AI block above the products of the results page: the tracker draws the answer, chips and refinements.
     */
    public static function aiBlock(): void
    {
        $block = self::pendingAiBlock();

        if ($block !== '') {
            self::$aiBlock = null;
            echo $block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pendingAiBlock().
        }
    }

    /** The markup of the AI block of this request; '' — no AI results. */
    public static function pendingAiBlock(): string
    {
        return self::$aiBlock !== null ? '<div class="onetrace-search-ai" data-cdp-search-ai="' . esc_attr((string) wp_json_encode(self::$aiBlock)) . '"></div>' : '';
    }

    /**
     * A parse in a link of the AI block: base64url of JSON.
     *
     * @return array<string, mixed>|null
     */
    private static function decoded(string $param): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public link; the platform checks the parse against the catalog.
        $raw = isset($_GET[$param]) ? sanitize_text_field(wp_unslash((string) $_GET[$param])) : '';

        if ($raw === '' || \strlen($raw) > 8000 || preg_match('/^[A-Za-z0-9_-]+$/', $raw) !== 1) {
            return null;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the format of the link.
        $json = base64_decode(strtr($raw, '-_', '+/'), true);
        $value = $json !== false ? json_decode($json, true) : null;

        return \is_array($value) ? $value : null;
    }

    /**
     * Search forms of the theme and WooCommerce: suggestions of the tracker in the query field.
     */
    public static function form(string $html): string
    {
        // Only the search widget: the layer of the tracker joins the fields while the widget is on in OneTrace.
        if (!Settings::enabled('search') && !SearchWidget::active()) {
            return $html;
        }

        $attributes = sprintf(' data-cdp-search data-category-url="%s"', esc_attr(add_query_arg(self::CATEGORY_PARAM, '{id}', home_url('/'))));

        return (string) preg_replace('/<input(?![^>]*data-cdp-search)([^>]*\bname=(["\'])s\2)/i', '<input' . $attributes . '$1', $html, 1);
    }

    /**
     * The search block of the editor and the product search block of WooCommerce; the AI block before the product
     * collection.
     *
     * @param array<string, mixed> $block
     */
    public static function block(string $html, array $block): string
    {
        // Block themes: the AI block before the product collection of the results template.
        if (($block['blockName'] ?? '') === 'woocommerce/product-collection' && self::$aiBlock !== null) {
            $ai = self::pendingAiBlock();
            self::$aiBlock = null;

            return $ai . $html;
        }

        return \in_array($block['blockName'] ?? '', ['core/search', 'woocommerce/product-search'], true) ? self::form($html) : $html;
    }

    /**
     * The page of a redirect rule of the query, set by the store owner on the platform.
     */
    public static function redirect(): void
    {
        $url = self::pendingRedirect();

        if ($url !== null) {
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the rule may lead to another site of the store.
            wp_redirect($url, 302, 'OneTrace');
            exit;
        }
    }

    /**
     * The redirect the search of this request asks for.
     */
    public static function pendingRedirect(): ?string
    {
        return self::$redirect;
    }

    public static function category(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public link.
        $id = isset($_GET[self::CATEGORY_PARAM]) ? absint(wp_unslash((string) $_GET[self::CATEGORY_PARAM])) : 0;

        if ($id <= 0) {
            return;
        }

        $link = get_term_link($id, 'product_cat');

        if (\is_string($link)) {
            wp_safe_redirect($link);
            exit;
        }
    }

    /**
     * The search of products: post_type=product (the product search form of WooCommerce and themes).
     */
    private static function forProducts(\WP_Query $query): bool
    {
        $type = $query->get('post_type');

        return $type === 'product' || $type === ['product'];
    }

    /**
     * Sorting chosen by the visitor (?orderby= of the catalog ordering form), otherwise of the query.
     */
    private static function orderby(\WP_Query $query): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public sorting link.
        return isset($_GET['orderby']) ? sanitize_key(wp_unslash((string) $_GET['orderby'])) : (string) $query->get('orderby');
    }

    /**
     * WooCommerce sorting → sorting of the platform.
     */
    private static function sort(string $orderby): ?string
    {
        switch ($orderby) {
            case 'price':
                return 'price_asc';
            case 'price-desc':
                return 'price_desc';
            case 'popularity':
                return 'popular';
            default:
                return null;
        }
    }

    /**
     * The search event of the results page: from the server, so it is counted with page caching and blockers too.
     */
    private static function track(string $text, int $results, string $requestId): void
    {
        $user = wp_get_current_user();
        $userId = $user->ID && !user_can($user, 'manage_woocommerce') ? (string) $user->ID : null;
        $anonymousId = Connection::anonymousId();

        if ($userId === null && $anonymousId === null) {
            return;
        }

        $message = Connection::messages()->search(new Customer($userId, $anonymousId), $text, $results, $requestId !== '' ? $requestId : null);

        if ($message !== null) {
            Queue::event($message);
        }
    }
}
