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

        return self::own($product);
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
