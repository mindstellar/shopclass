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
 * A refused menu names the item that caused it, and a draft keeps what was posted.
 * Usage: php tests/menu-items-errors.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

scratchdb_session('osc_menu_items_errors');

use mindstellar\menu\MenuItems;

if (!function_exists('__')) {
    function __($text, $domain = 'core')
    {
        return $text;
    }
}

$menu = new MenuItems(['en_US'], static fn (): bool => true, static fn (): bool => true);

harness_section('refusals name the item');
$menu->clean([['type' => 'link', 'url' => 'not-a-url', 'label' => ['en_US' => 'Hello']]]);
pin('a bad address names the link', ['The link "Hello" needs an address that starts with http://, https:// or /.'], $menu->errors());
$menu->clean([['type' => 'link', 'url' => 'https://example.test/a', 'label' => []]]);
pin('a missing label names the address', ['The link to "https://example.test/a" needs a label.'], $menu->errors());
$menu->clean([['type' => 'link', 'url' => '/sale', 'label' => ['en_US' => 'Sale']]]);
pin('a good link has no error', [], $menu->errors());

harness_section('draft');
$draft = $menu->draft([
    ['type' => 'link', 'url' => 'not-a-url', 'label' => ['en_US' => '<b>Hello</b>', 'xx_XX' => 'Dropped'], 'children' => [['type' => 'page', 'ref' => '7']]],
    ['type' => 'tag'],
    'junk',
]);
pin(
    'keeps what was posted, drops what cannot be drawn',
    [['type' => 'link', 'ref' => 0, 'url' => 'not-a-url', 'label' => ['en_US' => 'Hello'], 'children' => [['type' => 'page', 'ref' => 7, 'url' => '', 'label' => []]]]],
    $draft
);

exit(harness_result());
