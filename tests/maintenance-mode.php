<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Maintenance lockout defaults on (missing pref ⇒ 503) so existing sites and
 * the upgrade path that touches `.maintenance` keep taking the public site
 * down. Only an explicit `'0'` is a soft banner. The saved message is plain
 * text, not HTML.
 *
 * DB-free. Usage:  php tests/maintenance-mode.php
 */

require_once __DIR__ . '/../oc-includes/osclass/helpers/hMaintenance.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

harness_section('lockout pref defaults on');
check('null is on', osc_maintenance_lockout_from_pref(null) === true);
check('false is on', osc_maintenance_lockout_from_pref(false) === true);
check('empty string is on', osc_maintenance_lockout_from_pref('') === true);
check('whitespace is on', osc_maintenance_lockout_from_pref('  ') === true);
check('1 is on', osc_maintenance_lockout_from_pref('1') === true);
check('true string is on', osc_maintenance_lockout_from_pref('true') === true);
check('explicit 0 is off', osc_maintenance_lockout_from_pref('0') === false);
check('integer 0 is off', osc_maintenance_lockout_from_pref(0) === false);
check('padded 0 is off', osc_maintenance_lockout_from_pref(' 0 ') === false);

harness_section('503 vs banner vs CLI');
check(
    'lockout 503s the public site',
    osc_maintenance_should_lockout_request(true, true, false, false) === true
);
check(
    'soft banner does not 503',
    osc_maintenance_should_lockout_request(true, false, false, false) === false
);
check(
    'admins never 503',
    osc_maintenance_should_lockout_request(true, true, true, false) === false
);
check(
    'CLI cron is not 503d',
    osc_maintenance_should_lockout_request(true, true, false, true) === false
);
check(
    'no file means no lockout',
    osc_maintenance_should_lockout_request(false, true, false, false) === false
);

harness_section('message sanitizer');
pin('plain text kept', 'Back soon', osc_sanitize_maintenance_message('  Back soon  '));
pin('tags stripped', 'Back soon', osc_sanitize_maintenance_message('<b>Back soon</b>'));
// strip_tags() removes the tags, not the text between them. XSS is stopped
// at render by osc_esc_html(), not by trying to parse script contents here.
pin('script tags stripped, text kept', 'alert(1)hello', osc_sanitize_maintenance_message('<script>alert(1)</script>hello'));
pin('array rejected', '', osc_sanitize_maintenance_message(array('x')));
pin('null rejected', '', osc_sanitize_maintenance_message(null));
$long = str_repeat('a', OSC_MAINTENANCE_MESSAGE_MAX + 20);
pin(
    'capped at ' . OSC_MAINTENANCE_MESSAGE_MAX,
    OSC_MAINTENANCE_MESSAGE_MAX,
    strlen(osc_sanitize_maintenance_message($long))
);
pin('empty after tags', '', osc_sanitize_maintenance_message('<p></p>'));

exit(harness_result());
