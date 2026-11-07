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
 * Pins the "To finish later" list on the installer's done step.
 *
 * DB-free.  Usage: php tests/install-finish-later.php
 */

namespace {
    define('ABS_PATH', dirname(__DIR__) . '/');
    define('LIB_PATH', ABS_PATH . 'oc-includes/');

    function __($text)
    {
        return $text;
    }

    require_once LIB_PATH . 'classes/core/install/ShopSetup.php';
    require_once LIB_PATH . 'classes/core/install/FinishLater.php';
    require_once __DIR__ . '/lib/harness.php';

    use mindstellar\install\FinishLater;

    $items = static fn (array $flags, array $names = array(), ?array $notice = null): array => (new FinishLater($flags, $names, $notice))->items();

    harness_section('clean install');
    check('no flags, no items', $items(array()) === array());
    check('zero flags, no items', $items(array('install_starter_failed' => 0, 'install_location_failed' => '')) === array());
    check('an ok shop notice adds nothing', $items(array(), array(), array('ok' => true, 'text' => 'Shop mode is on.')) === array());

    harness_section('one flag, one item');
    $one = $items(array('install_location_failed' => 'IN'));
    check('places', count($one) === 1 && str_contains($one[0]['how'], 'Settings → Locations'));
    $one = $items(array('install_starter_failed' => 1));
    check('categories', count($one) === 1 && str_contains($one[0]['how'], 'Listings → Categories'));
    $one = $items(array('install_storefront_failed' => 1));
    check('storefront', count($one) === 1 && str_contains($one[0]['how'], 'Appearance → Add theme'));
    $one = $items(array('install_sample_photos_failed' => 1));
    check('photos', count($one) === 1 && str_contains($one[0]['how'], 'have no photos'));
    $one = $items(array('install_sample_warning' => 1));
    check('example listings', count($one) === 1 && str_contains($one[0]['what'], 'example listings'));

    harness_section('plugins');
    $failed = array(
        'paypal' => array('type' => 'plugin', 'message' => 'No answer.'),
        'mystery' => array('type' => 'plugin', 'message' => ''),
    );
    $list = $items(array('install_shop_failed' => $failed), array('paypal' => 'PayPal'));
    check('two failed plugins, two items', count($list) === 2);
    check('named from the catalogue', $list[0]['what'] === 'PayPal');
    check('slug is the fallback name', $list[1]['what'] === 'mystery');
    check('fix and message', $list[0]['how'] === 'No answer. Add it under Plugins → Add plugin.' && $list[1]['how'] === 'Add it under Plugins → Add plugin.');

    harness_section('shop notice');
    $notice = array('ok' => false, 'text' => 'The Aisle theme could not be installed: nope.');
    $list   = $items(array(), array(), $notice);
    check('a failing notice is one item with its text', count($list) === 1 && $list[0]['how'] === $notice['text']);
    $themeFail = array('aisle' => array('type' => 'theme', 'message' => 'nope.'));
    $list      = $items(array('install_shop_failed' => $themeFail), array(), $notice);
    check('theme failure is not listed twice', count($list) === 1 && $list[0]['how'] === $notice['text']);

    exit(harness_result());
}
