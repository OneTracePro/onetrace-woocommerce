<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\CatalogItem;

/**
 * Catalog languages of the store from its translation plugin: TranslatePress (one product, a dictionary of strings),
 * WPML and Polylang (a translated copy of the product per language). The platform keeps one product with
 * translations of the name, the link and the attributes, so recommendations, search and emails show the visitor's
 * language; with WPML and Polylang every copy resolves to the product of the default language — the same id in the
 * catalog and in events.
 */
final class Translations
{
    /**
     * Locales of the other languages of the store ("ru_RU").
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        if (self::translatePress()) {
            $settings = get_option('trp_settings', []);

            if (!\is_array($settings)) {
                return [];
            }

            $languages = array_intersect(array_map('strval', (array) ($settings['publish-languages'] ?? [])), array_map('strval', (array) ($settings['translation-languages'] ?? [])));

            return array_values(array_diff($languages, [(string) ($settings['default-language'] ?? '')]));
        }

        if (self::wpml()) {
            $languages = (array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
            $default = (string) apply_filters('wpml_default_language', null);

            return array_values(array_filter(array_map(static function ($language) use ($default): string {
                return \is_array($language) && ($language['code'] ?? '') !== $default ? (string) ($language['default_locale'] ?? $language['code'] ?? '') : '';
            }, $languages)));
        }

        if (self::polylang()) {
            return array_values(array_diff(array_map('strval', (array) pll_languages_list(['fields' => 'locale'])), [(string) pll_default_language('locale')]));
        }

        return [];
    }

    /**
     * The product of the default language for a translated copy (WPML, Polylang); other products as they are.
     */
    public static function original(int $postId, string $type = 'product'): int
    {
        if (self::wpml()) {
            $original = (int) apply_filters('wpml_object_id', $postId, $type, true, apply_filters('wpml_default_language', null));

            return $original > 0 ? $original : $postId;
        }

        $default = self::polylang() ? pll_default_language() : false;

        if (\is_string($default) && \function_exists('pll_get_post')) {
            $original = $type === 'product_cat' ? (int) pll_get_term($postId, $default) : (int) pll_get_post($postId, $default);

            return $original > 0 ? $original : $postId;
        }

        return $postId;
    }

    /**
     * The copy of a product in the language of the current page (WPML, Polylang): search results link to it.
     */
    public static function current(int $postId): int
    {
        if (self::wpml()) {
            return (int) apply_filters('wpml_object_id', $postId, 'product', true) ?: $postId;
        }

        if (self::polylang() && \function_exists('pll_get_post')) {
            return (int) pll_get_post($postId) ?: $postId;
        }

        return $postId;
    }

    /**
     * Adds translations to catalog items and categories.
     *
     * @param list<array<string, mixed>> $items CatalogItem::make()
     * @param array<string, \WC_Product> $products by catalog id
     * @param list<array<string, mixed>> $categories CatalogItem::category()
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function apply(array $items, array $products, array $categories): array
    {
        $languages = self::languages();

        if ($languages === []) {
            return [$items, $categories];
        }

        if (self::translatePress()) {
            return self::fromDictionary($items, $products, $categories, $languages);
        }

        foreach ($items as $index => $item) {
            $product = $products[(string) $item['id']] ?? null;

            if (!$product instanceof \WC_Product) {
                continue;
            }

            foreach ($languages as $locale) {
                $copy = self::copy($product->get_id(), 'product', $locale);
                $translated = $copy > 0 && $copy !== $product->get_id() ? wc_get_product($copy) : null;

                if ($translated instanceof \WC_Product) {
                    $items[$index] = CatalogItem::translate($items[$index], $locale, html_entity_decode($translated->get_name()), (string) get_permalink($copy));
                }
            }
        }

        foreach ($categories as $index => $category) {
            foreach ($languages as $locale) {
                $copy = self::copy((int) $category['id'], 'product_cat', $locale);
                $term = $copy > 0 && $copy !== (int) $category['id'] ? get_term($copy, 'product_cat') : null;

                if ($term instanceof \WP_Term) {
                    $categories[$index] = CatalogItem::translateCategory($categories[$index], $locale, html_entity_decode($term->name));
                }
            }
        }

        return [$items, $categories];
    }

    /**
     * TranslatePress: names, category names and attribute values from its dictionary, links with the language.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, \WC_Product> $products
     * @param list<array<string, mixed>> $categories
     * @param list<string> $languages
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function fromDictionary(array $items, array $products, array $categories, array $languages): array
    {
        $trp = \TRP_Translate_Press::get_trp_instance();
        $query = $trp->get_component('query');
        $urls = $trp->get_component('url_converter');
        $strings = array_merge(array_column($items, 'name'), array_column($categories, 'name'));

        foreach ($items as $item) {
            foreach ((array) ($item['params'] ?? []) as $value) {
                $strings[] = (string) $value;
            }
        }

        $strings = array_values(array_unique(array_filter(array_map('strval', $strings))));

        foreach ($languages as $locale) {
            $dictionary = [];

            foreach ((array) $query->get_existing_translations($strings, $locale) as $original => $row) {
                if ($row instanceof \stdClass && (int) ($row->status ?? 0) !== 0 && (string) ($row->translated ?? '') !== '') {
                    $dictionary[(string) $original] = html_entity_decode((string) $row->translated);
                }
            }

            foreach ($items as $index => $item) {
                $params = [];

                foreach ((array) ($item['params'] ?? []) as $key => $value) {
                    if (isset($dictionary[(string) $value])) {
                        $params[$key] = $dictionary[(string) $value];
                    }
                }

                $url = isset($item['url']) && isset($products[(string) $item['id']]) ? (string) $urls->get_url_for_language($locale, (string) $item['url'], '') : null;
                $items[$index] = CatalogItem::translate($item, $locale, $dictionary[(string) $item['name']] ?? null, $url !== $item['url'] ? $url : null, $params);
            }

            foreach ($categories as $index => $category) {
                if (isset($dictionary[(string) $category['name']])) {
                    $categories[$index] = CatalogItem::translateCategory($category, $locale, $dictionary[(string) $category['name']]);
                }
            }
        }

        return [$items, $categories];
    }

    /**
     * Id of the copy of a post or a term in a language (WPML, Polylang), 0 if there is none.
     */
    private static function copy(int $id, string $type, string $locale): int
    {
        if (self::wpml()) {
            $code = '';

            foreach ((array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0]) as $language) {
                if (\is_array($language) && ($language['default_locale'] ?? '') === $locale) {
                    $code = (string) $language['code'];
                }
            }

            return $code !== '' ? (int) apply_filters('wpml_object_id', $id, $type, false, $code) : 0;
        }

        if (self::polylang()) {
            $locales = array_map('strval', (array) pll_languages_list(['fields' => 'locale']));
            $slugs = array_map('strval', (array) pll_languages_list(['fields' => 'slug']));
            $slug = $slugs[(int) array_search($locale, $locales, true)] ?? '';

            if (!\in_array($locale, $locales, true) || $slug === '') {
                return 0;
            }

            return $type === 'product_cat' ? (int) pll_get_term($id, $slug) : (int) pll_get_post($id, $slug);
        }

        return 0;
    }

    private static function translatePress(): bool
    {
        return class_exists('TRP_Translate_Press');
    }

    private static function wpml(): bool
    {
        return \defined('ICL_SITEPRESS_VERSION');
    }

    private static function polylang(): bool
    {
        return \function_exists('pll_languages_list') && \function_exists('pll_default_language');
    }
}
