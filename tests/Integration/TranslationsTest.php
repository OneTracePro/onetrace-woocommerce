<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\WooCommerce\Catalog;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * Catalog languages from TranslatePress (tests/env/translatepress.php: en_US and ru_RU).
 */
final class TranslationsTest extends TestCase
{
    /** @var list<string> */
    private $originals = [];

    protected function tearDown(): void
    {
        global $wpdb;

        $table = \TRP_Translate_Press::get_trp_instance()->get_component('query')->get_table_name('ru_RU');

        foreach ($this->originals as $original) {
            $wpdb->delete($table, ['original' => $original]);
        }

        parent::tearDown();
    }

    private function translate(string $original, string $translated, int $status = 2): void
    {
        global $wpdb;

        $wpdb->insert(\TRP_Translate_Press::get_trp_instance()->get_component('query')->get_table_name('ru_RU'), ['original' => $original, 'translated' => $translated, 'status' => $status]);
        $this->originals[] = $original;
    }

    public function testUploadsNamesCategoriesAttributesAndLinksInTheOtherLanguage(): void
    {
        $suffix = (string) wp_rand();
        $category = wp_insert_term('Dresses ' . $suffix, 'product_cat');

        $attribute = new \WC_Product_Attribute();
        $attribute->set_name('Color');
        $attribute->set_options(['Blue ' . $suffix]);
        $attribute->set_visible(true);

        $product = $this->product(['name' => 'Linen Dress ' . $suffix, 'category_ids' => [$category['term_id']], 'attributes' => [$attribute]]);
        $this->translate('Linen Dress ' . $suffix, 'Льняное платье ' . $suffix);
        $this->translate('Dresses ' . $suffix, 'Платья ' . $suffix);
        $this->translate('Blue ' . $suffix, 'Синий ' . $suffix);
        $this->translate('Untranslated ' . $suffix, '', 0);

        [$items, $categories] = Catalog::items([$product->get_id()]);
        $translation = $items[0]['translations']['ru-RU'];

        self::assertSame('Linen Dress ' . $suffix, $items[0]['name'], 'The name of the default language stays.');
        self::assertSame('Льняное платье ' . $suffix, $translation['name']);
        self::assertSame(['Color' => 'Синий ' . $suffix], $translation['params']);
        self::assertStringContainsString('/ru/', $translation['url']);
        self::assertSame(['ru-RU' => ['name' => 'Платья ' . $suffix]], $categories[0]['translations']);

        wp_delete_term($category['term_id'], 'product_cat');
    }

    public function testSkipsALanguageWithoutTranslatedStrings(): void
    {
        $product = $this->product(['name' => 'Wool Scarf ' . wp_rand()]);

        [$items] = Catalog::items([$product->get_id()]);

        self::assertArrayNotHasKey('name', $items[0]['translations']['ru-RU'] ?? [], 'No dictionary entry — no translated name.');
    }
}
