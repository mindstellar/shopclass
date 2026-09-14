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
 * osc_admin_date() / osc_admin_date_format() — the compact date shown in admin list
 * tables, with the site's long format kept on hover and for screen readers.
 *
 * Pure functions: osc_date_format()/osc_time_format()/osc_apply_filter() are stubbed,
 * osc_format_date() and the two under test run for real.
 *   php tests/admin-date-format.php
 */

$GLOBALS['adminDateFilter'] = null;
$GLOBALS['dateFormat']      = 'F j, Y';
$GLOBALS['timeFormat']      = 'g:i a';

function osc_date_format()
{
    return $GLOBALS['dateFormat'];
}
function osc_time_format()
{
    return $GLOBALS['timeFormat'];
}
function osc_apply_filter($hook, $content, ...$args)
{
    if ($hook === 'admin_date_format' && $GLOBALS['adminDateFilter'] !== null) {
        return $GLOBALS['adminDateFilter'];
    }

    return $content;
}
function __($key, $domain = 'core')
{
    return $key;
}

require_once __DIR__ . '/../oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/../oc-includes/osclass/helpers/hUtils.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

harness_section('format');

pin('date-time format is Y-m-d H:i', 'Y-m-d H:i', osc_admin_date_format());
pin('date-only format is Y-m-d', 'Y-m-d', osc_admin_date_format(true));

$dt = '2026-09-14 06:14:00';

pin(
    'date-time cell',
    '<time datetime="2026-09-14T06:14" title="September 14, 2026 6:14 am">2026-09-14 06:14</time>',
    osc_admin_date($dt)
);
pin(
    'date-only cell',
    '<time datetime="2026-09-14" title="September 14, 2026 6:14 am">2026-09-14</time>',
    osc_admin_date($dt, true)
);
pin('empty date renders nothing', '', osc_admin_date(''));

harness_section('filter override');

$GLOBALS['adminDateFilter'] = 'd/m/Y H:i';
pin('a plugin can change the compact format', 'd/m/Y H:i', osc_admin_date_format());
check(
    'the override reaches osc_admin_date()',
    strpos(osc_admin_date($dt), '>14/09/2026 06:14<') !== false,
    osc_admin_date($dt)
);
$GLOBALS['adminDateFilter'] = null;

harness_section('escaping');

// A literal quote in the site's date format (date() passes non-letters through as-is)
// must not break out of the title attribute.
$GLOBALS['dateFormat'] = '"Y-m-d"';
$GLOBALS['timeFormat'] = 'H:i';
check(
    'a quote in the long title is escaped',
    strpos(osc_admin_date($dt), 'title="&quot;2026-09-14&quot; 06:14"') !== false,
    osc_admin_date($dt)
);
$GLOBALS['dateFormat'] = 'F j, Y';
$GLOBALS['timeFormat'] = 'g:i a';

exit(harness_result());

/* file end: ./tests/admin-date-format.php */
