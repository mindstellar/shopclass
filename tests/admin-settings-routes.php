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
$formDir    = __DIR__ . '/../oc-includes/osclass/classes/admin/form';
$controller = __DIR__ . '/../oc-includes/osclass/classes/controller/admin/CAdminSettings.php';

check('the settings view directory is where it is expected', is_dir($viewDir));
check('the declared-form directory is where it is expected', is_dir($formDir));
check('CAdminSettings.php is where it is expected', is_file($controller));

/**
 * Actions the settings views post. Two spellings, because a view may declare its form
 * either way: osc_admin_form_open(array('action' => 'x')) is the current one, and a
 * hand-written `<input type="hidden" name="action" value="x">` still works and still has
 * to be routed. An action built at runtime is not scannable and is not checked here.
 */
$posted = array();
foreach (glob($viewDir . '/*.php') as $view) {
    $src = (string) file_get_contents($view);
    foreach (array('/name="action"\s+value="([a-z_]+)"/', "/'action'\s*=>\s*'([a-z_]+)'/") as $pattern) {
        if (preg_match_all($pattern, $src, $m)) {
            foreach ($m[1] as $action) {
                $posted[$action] = basename($view);
            }
        }
    }
}

/*
 * A declared form names its action in the declaration rather than in the view, so the same
 * check has to read there too, or migrating a screen onto the declarative layer takes it
 * out of this test's sight -- which is precisely the failure the file exists for.
 */
foreach (glob($formDir . '/*.php') as $form) {
    $src = (string) file_get_contents($form);
    if (strpos($src, 'CoreSettings::') === false) {
        // An entity screen with a controller of its own -- admins, ban rules -- posts to
        // that controller, not to ?page=settings, so its actions are not this router's.
        continue;
    }
    if (preg_match_all("/CoreSettings::vars\(\s*[^,]+,\s*'([a-z_]+)'/", $src, $m)) {
        foreach ($m[1] as $action) {
            $posted[$action] = basename($form);
        }
    }
    // A screen with more than one form lists them, so the action is a bare literal rather
    // than an argument in the call. Any *_post spelled out in a declaration is one.
    if (preg_match_all("/'([a-z_]+_post)'/", $src, $m)) {
        foreach ($m[1] as $action) {
            $posted[$action] = basename($form);
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

/* The sitemap screen's two forms moved into a declaration, so the scan now finds their actions
   there and nowhere else; name them so the move cannot take them out of its sight. */
foreach (array('sitemap_settings_post', 'sitemap_robots_post') as $action) {
    pin('scanned from SitemapSettingsForm.php: ' . $action, 'SitemapSettingsForm.php', $posted[$action] ?? '');
    check('routed: ' . $action, isset($routed[$action]));
}

exit(harness_result());
