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
 * AdminMenu::current(): which sidebar entry a page highlights, including two entries that
 * share one link.
 *
 * DB-free.  Usage:  php tests/admin-menu-current.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php';

$base  = 'https://example.test/oc-admin/index.php';
$bases = [$base . '?', 'https://example.test/oc-admin/'];
$menu  = [
    'dash'     => ['Dashboard', 'https://example.test/oc-admin/', 'dash', 'moderator'],
    'plugins'  => ['Plugins', $base . '?page=plugins', 'plugins', 'administrator', 'sub' => [
        ['Plugins', $base . '?page=plugins', 'plugins_manage', 'administrator'],
        ['API keys (Settings > API)', $base . '?page=settings&action=api', 'listing-import-keys', 'administrator'],
    ]],
    'settings' => ['Settings', $base . '?page=settings', 'settings', 'administrator', 'sub' => [
        ['General', $base . '?page=settings', 'settings_general', 'administrator'],
        ['API', $base . '?page=settings&action=api', 'settings_api', 'administrator'],
    ]],
];

harness_section('AdminMenu::current');
pin('a plugin shortcut to a core screen does not take its highlight', ['settings', 'settings_api'], AdminMenu::current($menu, 'page=settings&action=api', 'settings', false, $bases));
pin('...whichever section comes first', ['settings', 'settings_api'], AdminMenu::current(array_reverse($menu, true), 'page=settings&action=api', 'settings', false, $bases));
pin('the longest matching link still wins', ['settings', 'settings_general'], AdminMenu::current($menu, 'page=settings&action=general', 'settings', false, $bases));
pin('a plugin page highlights its own entry', ['plugins', 'plugins_manage'], AdminMenu::current($menu, 'page=plugins', 'plugins', false, $bases));
pin('no query string is the dashboard', ['dash', ''], AdminMenu::current($menu, '', null, false, $bases));

exit(harness_result());

/* file end: ./tests/admin-menu-current.php */
