<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

/**
 * product_id of a product in OneTrace: the post id, or the SKU when the store's catalog already lives under SKUs
 * (setting "Product id": a store moving from another platform keeps its history and recommendations). Variations
 * resolve to the parent product; a product without a SKU falls back to its post id.
 */
final class Products
{
    /**
     * @param \WC_Product|int $product
     */
    public static function id($product): string
    {
        $product = $product instanceof \WC_Product ? $product : wc_get_product($product);

        if (!$product instanceof \WC_Product) {
            return '';
        }

        if ($product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            $product = $parent instanceof \WC_Product ? $parent : $product;
        }

        // A translated copy (WPML, Polylang) is the product of the default language: one id in the catalog and in events.
        $original = Translations::original($product->get_id());

        if ($original !== $product->get_id()) {
            $product = wc_get_product($original) ?: $product;
        }

        return self::own($product);
    }

    /**
     * Post ids of catalog ids, in the same order: in the SKU mode by the SKU (a variation SKU → its parent), a
     * numeric id without a matching SKU — a post id; ids of products that are gone are skipped.
     *
     * @param list<string> $ids
     *
     * @return list<int>
     */
    public static function postIds(array $ids): array
    {
        $posts = [];

        foreach ($ids as $id) {
            $postId = self::bySku() ? (int) wc_get_product_id_by_sku($id) : 0;

            if ($postId === 0 && ctype_digit($id)) {
                $postId = (int) $id;
            }

            $product = $postId > 0 ? wc_get_product($postId) : null;

            if ($product instanceof \WC_Product) {
                $posts[] = Translations::current($product->get_parent_id() ?: $product->get_id());
            }
        }

        return array_values(array_unique($posts));
    }

    /**
     * variant_id of a variation: its SKU in the SKU mode, otherwise its post id.
     */
    public static function variantId(\WC_Product $variation): string
    {
        return self::own($variation);
    }

    public static function bySku(): bool
    {
        return Settings::get('product_id') === 'sku';
    }

    private static function own(\WC_Product $product): string
    {
        $sku = trim((string) $product->get_sku());

        return self::bySku() && $sku !== '' ? $sku : (string) $product->get_id();
    }
}
