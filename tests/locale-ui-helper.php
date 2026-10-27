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
 * Pins osc_current_ui_locale(): the admin's language inside the admin, the visitor's anywhere
 * else. OC_ADMIN is a constant, so each side runs in its own PHP process.
 *
 * DB-free.  Usage:  php tests/locale-ui-helper.php
 */

if (($argv[1] ?? '') === 'child') {
    define('ABS_PATH', dirname(__DIR__) . '/');
    if (($argv[2] ?? '') === 'admin') {
        define('OC_ADMIN', true);
    }
    require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
    function osc_language()
    {
        return 'fr_FR';
    }
    function osc_admin_language()
    {
        return 'de_DE';
    }
    echo osc_current_ui_locale();

    return;
}

require_once __DIR__ . '/lib/harness.php';

$run = static fn (string $side): string => (string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . $side . ' 2>&1'
);

harness_section('osc_current_ui_locale()');
pin('the visitor\'s language outside the admin', 'fr_FR', $run('public'));
pin('the admin\'s language inside the admin', 'de_DE', $run('admin'));

exit(harness_result());

/* file end: ./tests/locale-ui-helper.php */
