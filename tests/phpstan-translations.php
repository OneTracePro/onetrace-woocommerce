<?php

// Translation plugins the catalog reads when they are active (Translations), for static analysis.

class TRP_Translate_Press
{
    public static function get_trp_instance(): self
    {
        return new self();
    }

    /**
     * @return mixed TRP_Query, TRP_Url_Converter …
     */
    public function get_component(string $component)
    {
        return $component === 'query' ? new TRP_Query() : new TRP_Url_Converter();
    }
}

class TRP_Query
{
    /**
     * @param list<string> $strings_array
     *
     * @return array<string, mixed> rows (original, translated, status) by the original
     */
    public function get_existing_translations(array $strings_array, string $language_code): array
    {
        return [];
    }
}

class TRP_Url_Converter
{
    public function get_url_for_language(string $language, string $url, string $trp_link_is_processed): string
    {
        return $url;
    }
}

/**
 * @param array<string, mixed> $args
 *
 * @return list<mixed>
 */
function pll_languages_list(array $args = []): array
{
    return [];
}

/**
 * @return string|false
 */
function pll_default_language(string $field = 'slug')
{
    return false;
}

/**
 * @return int|false|null
 */
function pll_get_post(int $post_id, string $lang = '')
{
    return null;
}

/**
 * @return int|false|null
 */
function pll_get_term(int $term_id, string $lang = '')
{
    return null;
}
