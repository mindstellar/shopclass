<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Per-language text: the visitor's language, then the default language, then the single
 * value from before, each read once. osc_settings_text_value() follows the same order.
 * Usage: php tests/locale-text-fallback.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

api_admin_boot('osc_locale_text_fallback');
Preference::getInstance()->set('language', 'en_US');

$reads = static function (?string $locale, array $stored) use (&$seen): array {
    $seen = array();
    $text = _osc_locale_text(static function (string $code) use (&$seen, $stored) {
        $seen[] = $code;

        return $stored[$code] ?? '';
    }, $locale);

    return array($text, $seen);
};

harness_section('fallback order');
pin('the visitor\'s language first', array('Hallo', array('de_DE')), $reads('de_DE', array('de_DE' => 'Hallo', 'en_US' => 'Hello')));
pin('then the default language', array('Hello', array('de_DE', 'en_US')), $reads('de_DE', array('en_US' => 'Hello')));
pin('then the single value', array('Old', array('de_DE', 'en_US', '')), $reads('de_DE', array('' => 'Old')));
pin('a visitor on the default language reads it once', array('Old', array('en_US', '')), $reads('en_US', array('' => 'Old')));

harness_section('settings text');
Preference::getInstance()->set('pageTitle', 'Single');
Preference::getInstance()->set('pageTitlede_DE', 'Titel');
pin('a stored language wins, others fall back', array('Titel', 'Single'), array(osc_settings_text_value('pageTitle', 'osclass', 'de_DE'), osc_settings_text_value('pageTitle', 'osclass', 'en_US')));

harness_section('site title');
$titles = new ReflectionProperty(Preference::class, 'pref');
pin('the title in the visitor\'s language', 'Titel', _osc_site_text('pageTitle', 'de_DE'));
$map = $titles->getValue(Preference::getInstance());
$map['osclass']['pageTitlede_DE'] = 'Unseen';
$titles->setValue(Preference::getInstance(), $map);
pin('read once a page, not again on the next call', 'Titel', _osc_site_text('pageTitle', 'de_DE'));
Preference::getInstance()->set('pageTitlede_DE', 'Neu');
pin('a title set in the request shows at once', 'Neu', _osc_site_text('pageTitle', 'de_DE'));

harness_section('default language');
pin('the default language', 'en_US', osc_language());
$map = $titles->getValue(Preference::getInstance());
$map['osclass']['language'] = 'de_DE';
$titles->setValue(Preference::getInstance(), $map);
pin('read once, not on every call', 'en_US', osc_language());
osc_reset_preferences();
Preference::getInstance()->set('language', 'de_DE');
pin('a change in the request shows at once', 'de_DE', osc_language());

harness_section('a language switched in the request');
if (!function_exists('osc_translations_path')) {
    function osc_translations_path()
    {
        return sys_get_temp_dir() . '/osc-no-translations/';
    }
}
$locales = OSCLocale::getInstance();
foreach (array('xx_XX', 'en_US') as $code) {
    if (!$locales->findByCode($code)) {
        $locales->insertLocaleInfo(array('locale_code' => $code, 'name' => $code, 'short_name' => $code, 'description' => $code, 'version' => '1', 'direction' => 'ltr', 'author_name' => '', 'author_url' => '', 'currency_format' => '{NUMBER}', 'date_format' => 'Y-m-d'), $code);
    }
    \mindstellar\language\LocaleStore::update($code, array('b_enabled' => 1, 'b_enabled_bo' => 1));
}
osc_reset_preferences();
Preference::getInstance()->set('language', 'xx_XX');
pin('an enabled default language is kept', 'xx_XX', osc_language());
\mindstellar\language\LocaleStore::update('xx_XX', array('b_enabled' => 0));
pin('a language turned off shows at once', 'en_US', osc_language());
\mindstellar\language\LocaleStore::update('xx_XX', array('b_enabled' => 1));
pin('and turned on again', 'xx_XX', osc_language());

\OSCLocale::getInstance()->deleteLocale('xx_XX');

exit(harness_result());
