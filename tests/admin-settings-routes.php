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
 * Every action a settings form posts must be routed by CAdminSettings::doModel().
 *
 * The switch there dispatches ?page=settings on its action, and its default arm renders
 * the General settings page. So an action the switch has no case for does not fail: the
 * request lands on a different settings page, the handler that would have written the
 * preferences never runs, and the values are discarded with no error and no flash. That
 * is how four of the billing page's five forms ("Pricing" -- which owns the seller
 * listing limit -- plus offline payments, upgrades and seller limits) silently saved
 * nothing while only the enable/disable toggle worked.
 *
 * Nothing here needs a database or a bootstrap: the views are scanned for the actions
 * they post, the controller for the cases it routes, and the two are compared. A new
 * settings form is covered the moment it is written.  Usage:
 *   php tests/admin-settings-routes.php
 */

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$viewDir    = __DIR__ . '/../oc-admin/themes/modern/settings';
$controller = __DIR__ . '/../oc-includes/osclass/classes/controller/admin/CAdminSettings.php';

check('the settings view directory is where it is expected', is_dir($viewDir));
check('CAdminSettings.php is where it is expected', is_file($controller));

/** Actions the settings views post, as `<input type="hidden" name="action" value="...">`. */
$posted = array();
foreach (glob($viewDir . '/*.php') as $view) {
    if (preg_match_all('/name="action"\s+value="([a-z_]+)"/', (string) file_get_contents($view), $m)) {
        foreach ($m[1] as $action) {
            $posted[$action] = basename($view);
        }
    }
}
ksort($posted);

/** Actions the router has a case for. */
$routed = array();
if (preg_match_all("/case \('([a-z_]+)'\)/", (string) file_get_contents($controller), $m)) {
    $routed = array_flip($m[1]);
}

check('the views post at least one action (the scan found something to check)', $posted !== array());
check('the router declares cases (the scan found something to check)', $routed !== array());

$unrouted = array();
foreach ($posted as $action => $view) {
    if (!isset($routed[$action])) {
        $unrouted[] = $action . ' (' . $view . ')';
    }
}

pin(
    'every action a settings form posts has a case in CAdminSettings::doModel()',
    '',
    implode(', ', $unrouted)
);

/* The billing page is the one that had four of its five forms unrouted; name them
   explicitly so a future edit to the router cannot quietly drop them again. */
foreach (array(
    'billing',
    'billing_post',
    'billing_pricing_post',
    'billing_offline_post',
    'billing_upgrades_post',
    'billing_limits_post',
) as $action) {
    check('routed: ' . $action, isset($routed[$action]));
}

exit(harness_result());
