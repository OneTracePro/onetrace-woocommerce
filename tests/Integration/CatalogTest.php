<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\WooCommerce\Tests\TestCase;

final class CatalogTest extends TestCase
{
    public function testUploadsAVariableProductOnceWithItsCategoryTreeAndRemovesItWhenTrashed(): void
    {
        $parent = wp_insert_term('Shoes ' . wp_rand(), 'product_cat');
        $child = wp_insert_term('Sneakers ' . wp_rand(), 'product_cat', ['parent' => $parent['term_id']]);

        $attribute = new \WC_Product_Attribute();
        $attribute->set_name('Size');
        $attribute->set_options(['40', '41']);
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $product = new \WC_Product_Variable();
        $product->set_props(['name' => 'Runner', 'status' => 'publish', 'category_ids' => [$parent['term_id'], $child['term_id']], 'attributes' => [$attribute]]);
        $product->save();
        $this->remember($product->get_id());

        foreach (['40' => '59.00', '41' => '49.00'] as $size => $price) {
            $variation = new \WC_Product_Variation();
            $variation->set_props(['parent_id' => $product->get_id(), 'regular_price' => $price, 'attributes' => ['size' => $size]]);
            $variation->save();
        }

        \WC_Product_Variable::sync($product->get_id());
        $this->flush();

        $upload = array_values(array_filter($this->platform->requests, static function (array $request): bool {
            return $request['method'] === 'POST' && $request['path'] === '/api/v1/products';
        }));
        $item = end($upload)['body']['items'][0];

        self::assertSame((string) $product->get_id(), $item['id']);
        self::assertCount(1, end($upload)['body']['items'], 'Variations are not separate products.');
        self::assertSame(49.0, $item['price']);
        self::assertSame([(string) $child['term_id'], (string) $parent['term_id']], $item['category_ids'], 'The deepest category first.');
        self::assertSame('40, 41', $item['params']['Size']);
        self::assertContains(['id' => (string) $child['term_id'], 'name' => get_term($child['term_id'])->name, 'parent_id' => (string) $parent['term_id']], end($upload)['body']['categories']);

        wp_trash_post($product->get_id());
        $this->flush();

        $last = end($this->platform->requests);
        self::assertSame(['DELETE', ['ids' => [(string) $product->get_id()]]], [$last['method'], $last['body']]);

        wp_delete_term($child['term_id'], 'product_cat');
        wp_delete_term($parent['term_id'], 'product_cat');
    }

    public function testMarksHiddenAndOutOfStockProductsUnavailable(): void
    {
        $product = $this->product(['stock_status' => 'outofstock', 'sale_price' => '39.95']);
        $this->flush();

        $item = end($this->platform->requests)['body']['items'][0];

        self::assertFalse($item['available']);
        self::assertSame([39.95, 49.95], [$item['price'], $item['old_price']]);
        self::assertSame((string) $product->get_id(), $item['id']);
    }
}
