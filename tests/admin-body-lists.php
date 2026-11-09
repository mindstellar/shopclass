<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The list screens open one shared admin body after the header and close it before the footer.
 *
 * Usage:  php tests/admin-body-lists.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$screens = array(
    'admins/index', 'billing/credits', 'billing/documents', 'billing/index', 'billing/packages',
    'comments/index', 'emails/index', 'fields/submissions', 'items/index', 'items/reported',
    'languages/index', 'media/index', 'messages/index', 'pages/index', 'settings/currencies',
    'users/alerts', 'users/ban', 'users/index',
);

foreach ($screens as $screen) {
    $src = (string)file_get_contents(ABS_PATH . 'oc-admin/themes/modern/' . $screen . '.php');
    harness_section($screen);
    $opens  = preg_match_all('/osc_admin_body_open\(/', $src);
    $closes = preg_match_all('/osc_admin_body_close\(/', $src);
    pin('one body opened', 1, $opens);
    pin('and closed', 1, $closes);
    $o = strpos($src, 'osc_admin_body_open(');
    $c = strpos($src, 'osc_admin_body_close(');
    $h = strpos($src, "parts/header.php");
    $f = strrpos($src, "parts/footer.php");
    check('opens after the header, closes before the footer', $h < $o && $o < $c && $c < $f);
    check('no relative wrapper div left', strpos($src, '<div class="relative">') === false);
}

$media = (string)file_get_contents(ABS_PATH . 'oc-admin/themes/modern/media/index.php');
check('media keeps its id and col-12 class', strpos($media, "'id' => 'media-library', 'class' => 'col-12'") !== false);
$list = (string)file_get_contents(ABS_PATH . 'oc-admin/themes/modern/admins/index.php');
check('datatablesForm kept', strpos($list, 'id="datatablesForm"') !== false);

check('languages import dialog has no mb- spacer', !str_contains((string)file_get_contents(ABS_PATH . 'oc-admin/themes/modern/languages/index.php'), 'mb-3'));

exit(harness_result());
