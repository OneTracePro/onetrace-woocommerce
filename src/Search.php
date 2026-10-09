<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\Customer;

/**
 * Product search of the store from OneTrace: the results page keeps the theme template, but its products and their
 * order come from the platform (word forms, typos, the wrong keyboard layout, the visitor's interests); the search
 * box gets suggestions of the tracker (data-cdp-search). Any API error falls back to the usual WordPress search.
 */
final class Search
{
    /** /?onetrace_category={id}: a category from the suggestions → its page (the tracker knows only the id). */
    public const CATEGORY_PARAM = 'onetrace_category';

    private const MAX_PER_PAGE = 48;

    public static function register(): void
    {
        if (!Settings::enabled('search') || Settings::url() === '' || (string) Settings::get('write_key') === '') {
            return;
        }

        add_filter('posts_pre_query', [self::class, 'results'], 10, 2);
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

        $text = trim((string) $query->get('s'));

        if ($text === '') {
            return null;
        }

        $page = max(1, (int) $query->get('paged'));
        $perPage = (int) $query->get('posts_per_page');
        $perPage = $perPage > 0 ? min(self::MAX_PER_PAGE, $perPage) : 12;

        try {
            $result = Connection::client('search')->search()->products($text, array_filter([
                'page' => $page,
                'per_page' => $perPage,
                'sort' => self::sort(self::orderby($query)),
                'anonymousId' => Connection::anonymousId(),
                // The page language (TranslatePress, WPML, Polylang): names and links of the catalog translations.
                'language' => get_locale(),
            ]));
        } catch (\Throwable $e) {
            Queue::log('Search fell back to WordPress: ' . $e->getMessage());

            return null;
        }

        $ids = Products::postIds(array_values(array_map(static function (array $item): string {
            return (string) ($item['id'] ?? '');
        }, (array) ($result['items'] ?? []))));

        $query->found_posts = (int) ($result['total'] ?? 0);
        $query->max_num_pages = (int) ceil($query->found_posts / $perPage);

        if ($page === 1) {
            self::track($text, $query->found_posts, (string) ($result['request_id'] ?? ''));
        }

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(array_map('get_post', $ids), static function ($post): bool {
            return $post instanceof \WP_Post;
        }));
    }

    /**
     * Search forms of the theme and WooCommerce: suggestions of the tracker in the query field.
     */
    public static function form(string $html): string
    {
        $attributes = sprintf(' data-cdp-search data-category-url="%s"', esc_attr(add_query_arg(self::CATEGORY_PARAM, '{id}', home_url('/'))));

        return (string) preg_replace('/<input(?![^>]*data-cdp-search)([^>]*\bname=(["\'])s\2)/i', '<input' . $attributes . '$1', $html, 1);
    }

    /**
     * The search block of the editor and the product search block of WooCommerce.
     *
     * @param array<string, mixed> $block
     */
    public static function block(string $html, array $block): string
    {
        return \in_array($block['blockName'] ?? '', ['core/search', 'woocommerce/product-search'], true) ? self::form($html) : $html;
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
