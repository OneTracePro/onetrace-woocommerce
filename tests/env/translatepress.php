<?php

/**
 * TranslatePress with a second store language (ru_RU) for the catalog translation tests: wp eval-file in run.sh.
 */
$settings = (array) get_option('trp_settings', []);
$settings['default-language'] = 'en_US';
$settings['translation-languages'] = ['en_US', 'ru_RU'];
$settings['publish-languages'] = ['en_US', 'ru_RU'];
$settings['url-slugs'] = ['en_US' => 'en', 'ru_RU' => 'ru'];
update_option('trp_settings', $settings);

TRP_Translate_Press::get_trp_instance()->get_component('query')->check_table('en_US', 'ru_RU');
