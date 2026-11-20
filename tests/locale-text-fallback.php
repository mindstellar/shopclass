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

exit(harness_result());
