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
 * Pins that the default language's folder is checked once per request, not on every language
 * lookup, and checked again after osc_reset_preferences().
 *
 * DB-free.  Usage: php tests/locale-folder-check.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';

$GLOBALS['lfc_dir']   = sys_get_temp_dir() . '/lfc-' . getmypid() . '/';
$GLOBALS['lfc_calls'] = 0;
mkdir($GLOBALS['lfc_dir'] . 'en_US', 0777, true);

function osc_translations_path()
{
    $GLOBALS['lfc_calls']++;

    return $GLOBALS['lfc_dir'];
}

harness_section('Default language folder');
$seen = array();
for ($i = 0; $i < 5; $i++) {
    $seen[] = _osc_enabled_locale('en_US', false);
}
pin('a language with its folder is kept', array_fill(0, 5, 'en_US'), $seen);
pin('the folder is checked once for five lookups', 1, $GLOBALS['lfc_calls']);
_osc_enabled_locale('en_US', true);
pin('the admin side shares that check', 1, $GLOBALS['lfc_calls']);
_osc_enabled_locale('', false, true);
_osc_enabled_locale('en_US', false);
pin('a reset checks the folder again', 2, $GLOBALS['lfc_calls']);

rmdir($GLOBALS['lfc_dir'] . 'en_US');
rmdir($GLOBALS['lfc_dir']);

exit(harness_result());
