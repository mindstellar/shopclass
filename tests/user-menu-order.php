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
 * Pins the order osc_private_user_menu() renders account entries in.
 *
 * user_menu_filter is how a plugin adds an entry to the account menu, and appending
 * is the natural way to write that -- which put every plugin entry BELOW the log-out
 * row, and displaced log out into the middle of the list, since the render gives the
 * final entry its own slot after the user_menu hook. Log out is moved back to last
 * after filtering; a theme that passes its own options without an opt_logout entry
 * (bender has none) must be left exactly as it was.
 *
 * The helper only reaches osc_apply_filter() and osc_run_hook(), both stubbed here, so
 * this runs with no database and no bootstrap.  Usage:  php tests/user-menu-order.php
 */

$GLOBALS['appended'] = array();

// The filter stub appends whatever $GLOBALS['appended'] holds, standing in for a plugin
// hooked on user_menu_filter. Defined before the helper loads, so there is no redeclaration.
function osc_apply_filter($hook, $content, ...$args)
{
    if (!empty($GLOBALS['filter_empties'])) {
        return array();
    }
    foreach ($GLOBALS['appended'] as $extra) {
        $content[] = $extra;
    }

    return $content;
}
function osc_run_hook($hook)
{
    echo '<!--user_menu_hook-->';
}

require_once __DIR__ . '/../oc-includes/osclass/helpers/hUtils.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/** The rendered entries' classes, in the order they appear. */
function menu_order(array $options): string
{
    ob_start();
    osc_private_user_menu($options);
    $html = ob_get_clean();
    preg_match_all('/<li class="([^"]*)"/', $html, $matches);

    return implode(' > ', array_map('trim', $matches[1]));
}

/** A theme whose list ends in log out -- core's own defaults, and what most themes pass. */
$withLogout = array(
    array('name' => 'Public Profile', 'url' => '#', 'class' => 'opt_publicprofile'),
    array('name' => 'Dashboard', 'url' => '#', 'class' => 'opt_dashboard'),
    array('name' => 'Logout', 'url' => '#', 'class' => 'opt_logout'),
);

/** A theme with no log-out entry at all (bender's shape) -- nothing to move. */
$withoutLogout = array(
    array('name' => 'Listings', 'url' => '#', 'class' => 'opt_items'),
    array('name' => 'Delete account', 'url' => '#', 'class' => 'opt_delete_account'),
);

$plugin = array(
    array('name' => 'Credits', 'url' => '#', 'class' => 'opt_billing_wallet'),
    array('name' => 'Buy credits', 'url' => '#', 'class' => 'opt_billing_buy'),
);

$GLOBALS['appended'] = array();
pin(
    'with no plugin hooked, a list ending in log out is unchanged',
    'opt_publicprofile > opt_dashboard > opt_logout',
    menu_order($withLogout)
);
pin(
    'with no plugin hooked, a list without log out is unchanged',
    'opt_items > opt_delete_account',
    menu_order($withoutLogout)
);

$GLOBALS['appended'] = $plugin;
pin(
    'appended plugin entries land above log out, not below it',
    'opt_publicprofile > opt_dashboard > opt_billing_wallet > opt_billing_buy > opt_logout',
    menu_order($withLogout)
);
pin(
    'a list with no opt_logout entry keeps appending at the end',
    'opt_items > opt_delete_account > opt_billing_wallet > opt_billing_buy',
    menu_order($withoutLogout)
);

/* The final entry is rendered after osc_run_hook('user_menu') -- which is only the
   intended "inject just above log out" position while log out is genuinely last. */
$GLOBALS['appended'] = $plugin;
ob_start();
osc_private_user_menu($withLogout);
$html = ob_get_clean();
preg_match('/<!--user_menu_hook-->(.*)<\/ul>/s', $html, $after);
check(
    'the user_menu hook fires immediately above log out',
    isset($after[1]) && strpos($after[1], 'opt_logout') !== false
);
check(
    '...and nothing else follows it',
    isset($after[1]) && strpos($after[1], 'opt_billing') === false
);

/* A filter is free to return nothing; the final-entry render must not reach for a key
   that is not there. Note this is the only way in: osc_private_user_menu(array()) takes
   the core-defaults branch instead, since array() == null is true in PHP. */
$GLOBALS['appended']       = array();
$GLOBALS['filter_empties'] = true;
pin('a filter that removes every entry renders nothing rather than warning', '', menu_order($withLogout));
$GLOBALS['filter_empties'] = false;

exit(harness_result());
