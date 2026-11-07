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
 * Pins the installer shell for assistive tech: html lang and dir from the locale, the phone
 * step line read aloud, and the requirements heading order.
 *
 * DB-free.  Usage: php tests/install-shell-a11y.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

function __($text)
{
    return $text;
}

function _e($text)
{
    echo $text;
}

function _n($single, $plural, $count)
{
    return (int) $count === 1 ? $single : $plural;
}

function get_absolute_url()
{
    return 'https://example.test/shop/';
}

require_once LIB_PATH . 'vendor/autoload.php';
require_once LIB_PATH . 'osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/harness.php';

$gui = ABS_PATH . 'oc-includes/osclass/installer/gui/install.php';

$render = static function (string $locale, array $locales) use ($gui): string {
    $GLOBALS['current_locale'] = $locale;
    $GLOBALS['locales'] = $locales;
    $step = 1;
    $error = false;
    $requirements = array(array('requirement' => 'PHP', 'fn' => true, 'solution' => ''));
    $jsonLocales = array('en_US' => 'English');
    $already_installed = false;
    $password = '';
    $shop_offer = array();
    $site_choice_saved = false;
    $current_locale = $locale;
    $locales = $locales;
    ob_start();
    include $gui;
    return (string) ob_get_clean();
};

$html = $render('hi_IN', array('hi_IN' => array('direction' => 'ltr')));

harness_section('lang and dir');
check('lang uses a dash', str_contains($html, '<html lang="hi-IN" dir="ltr">'));
$rtl = $render('he_IL', array('he_IL' => array('direction' => 'rtl')));
check('rtl locale gets dir=rtl', str_contains($rtl, '<html lang="he-IL" dir="rtl">'));
$junk = $render('en_US', array('en_US' => array('direction' => '"><script>')));
check('junk direction falls back to ltr', str_contains($junk, 'dir="ltr"') && !str_contains($junk, '<script>alert'));
$bad = $render('x"><b>', array());
check('odd locale code is not echoed raw', !str_contains($bad, 'lang="x"') && str_contains($bad, '<html lang="en" dir="ltr">'));

harness_section('phone step line');
check('step line is readable', (bool) preg_match('/<div class="ins-mstep">/', $html));
check('segments stay hidden', str_contains($html, '<div class="ins-segs" aria-hidden="true">'));
check('step label and count are present', str_contains($html, 'id="ins-mstep-label"') && str_contains($html, 'Step 1 of 6'));

harness_section('headings and disclosure');
check('one h1 on step 1', substr_count($html, '<h1') === 1);
check('checks fold is a details with a summary', str_contains($html, '<details class="ins-reqs-more">') && str_contains($html, '<summary>'));
check('h2 groups follow the h1', strpos($html, '<h1') < strpos($html, '<h2'));
check('reload string is not shipped to JS', !str_contains($html, '"reload"'));

exit(harness_result());
