<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\CatalogItem;

/**
 * Product catalog of the store on the platform: changes go through the queue, a full upload runs daily and from
 * the settings (Action Scheduler, page by page). Variations are not separate products: their changes upload the
 * parent product.
 */
final class Catalog
{
    public const SYNC_HOOK = 'onetrace_sync_catalog';

    /** Products per page of the full upload. */
    public const PAGE = 200;

    public static function register(): void
    {
        add_action('woocommerce_new_product', [self::class, 'changed']);
        add_action('woocommerce_update_product', [self::class, 'changed']);
        add_action('woocommerce_new_product_variation', [self::class, 'variationChanged']);
        add_action('woocommerce_update_product_variation', [self::class, 'variationChanged']);
        add_action('woocommerce_product_set_stock_status', [self::class, 'changed']);
        add_action('woocommerce_variation_set_stock_status', [self::class, 'variationChanged']);
        add_action('untrashed_post', [self::class, 'postChanged']);
        add_action('wp_trash_post', [self::class, 'postRemoved']);
        add_action('before_delete_post', [self::class, 'postRemoved']);
        add_action(self::SYNC_HOOK, [self::class, 'syncPage']);
    }

    /**
     * @param int|string $productId
     */
    public static function changed($productId): void
    {
        Queue::product((int) $productId);
    }

    /**
     * @param int|string $variationId
     */
    public static function variationChanged($variationId): void
    {
        $parent = wp_get_post_parent_id((int) $variationId);

        if ($parent) {
            Queue::product($parent);
        }
    }

    /**
     * @param int|string $postId
     */
    public static function postChanged($postId): void
    {
        if (get_post_type((int) $postId) === 'product') {
            Queue::product((int) $postId);
        }
    }

    /**
     * @param int|string $postId
     */
    public static function postRemoved($postId): void
    {
        if (get_post_type((int) $postId) === 'product') {
            Queue::delete((int) $postId, Products::id((int) $postId));
        }
    }

    /**
     * Starts the full upload: page 1 now, the next pages one after another.
     */
    public static function syncAll(): void
    {
        if (\function_exists('as_enqueue_async_action') && !as_has_scheduled_action(self::SYNC_HOOK, null, Queue::GROUP)) {
            as_enqueue_async_action(self::SYNC_HOOK, [1], Queue::GROUP);
        }
    }

    /**
     * @param int|string $page
     */
    public static function syncPage($page): void
    {
        if (!Settings::server() || !Settings::enabled('catalog')) {
            return;
        }

        $ids = wc_get_products(['status' => 'publish', 'type' => array_keys(wc_get_product_types()), 'limit' => self::PAGE, 'page' => max(1, (int) $page), 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids']);

        foreach ((array) $ids as $id) {
            Queue::product((int) $id);
        }

        if (\count((array) $ids) === self::PAGE) {
            as_enqueue_async_action(self::SYNC_HOOK, [(int) $page + 1], Queue::GROUP);
        }
    }

    /**
     * Catalog items for products, their categories with ancestors, and the ids of products that no longer exist.
     *
     * @param list<int> $productIds
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, string>>, 2: list<string>}
     */
    public static function items(array $productIds): array
    {
        $items = [];
        $categoryIds = [];
        $missing = [];

        foreach ($productIds as $id) {
            $product = wc_get_product($id);

            if (!$product instanceof \WC_Product || $product->is_type('variation')) {
                // By SKU the id of a product that is gone is unknown: its deletion was sent when it was deleted.
                if (!Products::bySku()) {
                    $missing[] = (string) $id;
                }

                continue;
            }

            $categories = self::categoryIds($product);
            $categoryIds = array_merge($categoryIds, $categories);
            $items[] = self::item($product, $categories);
        }

        return [$items, self::categories(array_unique($categoryIds)), $missing];
    }

    /**
     * @param list<int> $categories the deepest first
     *
     * @return array<string, mixed>
     */
    public static function item(\WC_Product $product, array $categories): array
    {
        [$price, $regular] = self::prices($product);
        $image = $product->get_image_id() ? wp_get_attachment_image_url((int) $product->get_image_id(), 'full') : null;
        $published = $product->get_status() === 'publish' && $product->get_catalog_visibility() !== 'hidden';

        return CatalogItem::make(
            Products::id($product),
            $product->get_name(),
            (string) get_permalink($product->get_id()),
            \is_string($image) ? $image : null,
            $price,
            get_woocommerce_currency(),
            $published && $product->is_in_stock() && $product->is_purchasable(),
            $categories,
            $regular,
            self::brand($product),
            self::attributes($product)
        );
    }

    /**
     * Deepest category of the product: product_id events carry it as category_id.
     */
    public static function categoryId(\WC_Product $product): ?string
    {
        $categories = self::categoryIds($product->get_parent_id() ? (wc_get_product($product->get_parent_id()) ?: $product) : $product);

        return $categories !== [] ? (string) $categories[0] : null;
    }

    /**
     * Displayed price and the price before the sale; variable products — the lowest variation price.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private static function prices(\WC_Product $product): array
    {
        if ($product instanceof \WC_Product_Variable) {
            $price = $product->get_variation_price('min', true);
            $regular = $product->get_variation_regular_price('min', true);

            return [$price !== '' ? (float) $price : null, $regular !== '' ? (float) $regular : null];
        }

        $price = $product->get_price();

        if ($price === '') {
            return [null, null];
        }

        $regular = $product->get_regular_price();

        return [(float) wc_get_price_to_display($product), $product->is_on_sale() && $regular !== '' ? (float) wc_get_price_to_display($product, ['price' => $regular]) : null];
    }

    /**
     * @return list<int> the deepest category first
     */
    private static function categoryIds(\WC_Product $product): array
    {
        $ids = array_map('intval', $product->get_category_ids());
        $depth = [];

        foreach ($ids as $id) {
            $depth[$id] = \count(get_ancestors($id, 'product_cat', 'taxonomy'));
        }

        usort($ids, static function (int $a, int $b) use ($depth): int {
            return $depth[$b] <=> $depth[$a];
        });

        return $ids;
    }

    /**
     * @param array<int> $ids
     *
     * @return list<array<string, string>>
     */
    private static function categories(array $ids): array
    {
        $all = [];

        foreach ($ids as $id) {
            foreach (array_merge([$id], get_ancestors($id, 'product_cat', 'taxonomy')) as $termId) {
                if (isset($all[$termId])) {
                    continue;
                }

                $term = get_term((int) $termId, 'product_cat');

                if ($term instanceof \WP_Term) {
                    $all[$termId] = CatalogItem::category($term->term_id, html_entity_decode($term->name), $term->parent ?: null);
                }
            }
        }

        return array_values($all);
    }

    private static function brand(\WC_Product $product): ?string
    {
        $id = $product->get_id();

        foreach (['product_brand', 'pwb-brand', 'pa_brand'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $terms = get_the_terms($id, $taxonomy);

                if (\is_array($terms) && $terms !== []) {
                    return html_entity_decode($terms[0]->name);
                }
            }
        }

        return null;
    }

    /**
     * Visible attributes: "Color" => "White, Black".
     *
     * @return array<string, string>
     */
    private static function attributes(\WC_Product $product): array
    {
        $params = [];

        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof \WC_Product_Attribute || !$attribute->get_visible()) {
                continue;
            }

            $values = $attribute->is_taxonomy()
                ? wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names'])
                : $attribute->get_options();
            $params[wc_attribute_label($attribute->get_name(), $product)] = html_entity_decode(implode(', ', array_map('strval', (array) $values)));
        }

        return $params;
    }
}
