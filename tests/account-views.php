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
 * Pins core's fallback pages for the account section, and the class vocabulary
 * they emit.
 *
 * Core renders these pages only when the theme ships no view of its own, so every
 * failure here is invisible until a site runs a theme that omits one:
 *
 *  - a partial renamed or moved, and osc_gui_account_view() falls through to the
 *    walk that has no else -- the blank page this whole fallback exists to stop;
 *  - a view dropped from the map, same result for that one page;
 *  - an .oe-* class renamed, and every theme styling the published name renders
 *    that element unstyled on a live site. These names are a permanent contract,
 *    documented in docs/site/developers/account-pages.md, which is why the doc is
 *    compared against the markup rather than trusted to be updated;
 *  - the flash message losing its live-region role, or regaining the dismiss
 *    control that was an <a> with no href -- neither is visible in a render.
 *
 * DB-free, and deliberately source-level: booting osc_gui_account_view() would
 * need the whole helper and translation layer, and what is worth pinning is the
 * agreement between the map, the files, the stylesheet and the doc.
 *
 * Usage:  php tests/account-views.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$guiDir    = ABS_PATH . 'oc-includes/osclass/gui/';
$accountIn = $guiDir . 'account/';
$hTheme    = file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hTheme.php');
$style     = file_get_contents($guiDir . 'page-style.php');
$doc       = file_get_contents(ABS_PATH . 'docs/site/developers/account-pages.md');
$messages  = file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hMessages.php');

// Every view core answers for. Adding one here without its partial, or shipping a
// partial nothing maps to, is the regression this list exists to catch.
$expected = array(
    'user-dashboard.php',
    'user-items.php',
    'user-alerts.php',
    'user-profile.php',
    'user-change_email.php',
    'user-change_password.php',
    'user-change_username.php',
    'user-login.php',
    'user-register.php',
    'user-recover.php',
    'user-forgot_password.php',
    'user-public-profile.php',
    'user-custom.php',
    'user-delete_account.php',
);

// ---------------------------------------------------------------- the map --

preg_match('/function osc_gui_account_view.*?\n}/s', $hTheme, $fn);
harness_section('the map');
check('osc_gui_account_view() is defined', !empty($fn));
$body = $fn[0];

preg_match_all("/'(user-[a-z_-]+\.php)'\s*=>/", $body, $m);
$mapped = $m[1];

check('no view is mapped twice', count($mapped) === count(array_unique($mapped)));
pin('every expected view is mapped', array(), array_values(array_diff($expected, $mapped)));
pin('no view is mapped that is not expected', array(), array_values(array_diff($mapped, $expected)));

harness_section('the content partials');
// -------------------------------------------------------------- the files --

// A page may answer more than one route: email, username and password are one
// page, so their views share a partial. The map is the source of truth for which.
$sharedPartial = array(
    'user-change_email.php'    => 'user-signin',
    'user-change_password.php' => 'user-signin',
    'user-change_username.php' => 'user-signin',
);

foreach ($expected as $view) {
    // user-delete_account keeps the path it shipped with; the rest live together.
    if ($view === 'user-delete_account.php') {
        $file = $guiDir . 'user-delete_account-content.php';
    } else {
        $partial = $sharedPartial[$view] ?? basename($view, '.php');
        $file    = $accountIn . $partial . '-content.php';
    }

    check("content partial exists for {$view}", file_exists($file));
}

// The routing table has to agree with the files, or a route renders the wrong page.
foreach ($sharedPartial as $view => $partial) {
    $line = '';
    foreach (explode("\n", $body) as $candidate) {
        if (strpos($candidate, "'" . $view . "'") !== false) {
            $line = $candidate;
            break;
        }
    }
    check(
        "osc_gui_account_view() routes {$view} to {$partial}",
        $line !== '' && strpos($line, "'content' => '" . $partial . "'") !== false
    );
}
check('the shared account nav partial exists', file_exists($accountIn . 'nav.php'));
check('the shared listing-row partial exists', file_exists($accountIn . 'parts/item-row.php'));

harness_section('the published class vocabulary');
// ------------------------------------------------------- the class vocabulary --

$partials = glob($accountIn . '*.php');
$partials = array_merge($partials, glob($accountIn . 'parts/*.php'));
$partials[] = $guiDir . 'user-delete_account-content.php';

$emitted = array();
foreach ($partials as $file) {
    $src = file_get_contents($file);

    // Rationale must never reach the browser: an HTML comment renders, a PHP one
    // does not, and the two look alike in a template.
    check('no HTML comment in ' . basename($file), strpos($src, '<!--') === false);

    preg_match_all('/class="([^"]*)"/', $src, $cm);
    foreach ($cm[1] as $attr) {
        foreach (preg_split('/\s+/', trim($attr)) as $token) {
            if (strpos($token, 'oe-') === 0) {
                $emitted[$token] = true;
            }
        }
    }
}
$emitted = array_keys($emitted);
sort($emitted);
check('the partials emit .oe-* classes at all', $emitted !== array());

foreach ($emitted as $class) {
    check("gui/page-style.php styles .{$class}", strpos($style, '.' . $class) !== false);
    check("account-pages.md documents .{$class}", strpos($doc, '`.' . $class . '`') !== false);
}

harness_section('flash messages');
// ------------------------------------------------------------ flash messages --

check('a flash message carries a role, so it announces without JavaScript', strpos($messages, "role=\"' . \$role . '\"") !== false);
check('an error is assertive and everything else is polite', strpos($messages, "\$role = (\$type === 'error') ? 'alert' : 'status';") !== false);
// Bender and storefront both bind dismissal to `.flashmessage a.ico-close` and
// read data-oc-close-label off it. Removing the element, or changing its tag,
// silently costs both themes their close button on the next core upgrade.
check('the dismiss control is still an <a class="ico-close">', strpos($messages, 'ico-close') !== false);
check('it still carries data-oc-close-label', strpos($messages, 'data-oc-close-label') !== false);
// Core ships no front-end script, so it must not claim the control is a button:
// on a theme that ships none either, that is a focusable control that does nothing.
check(
    'core does not announce the inert control as a button',
    strpos($messages, 'role="button"') === false
);
// Frozen names. Themes and plugins nobody here can see style these exact strings.
check('the flashmessage-<type> class is still emitted', strpos($messages, "strtolower(\$class) . '-'") !== false);
check('the flash_js mount is printed once, not once per message', substr_count($messages, "<div id=\"flash_js\"></div>") === 1);

harness_section('extension points');

$slotPages = array(
    'user-dashboard'      => $accountIn . 'user-dashboard-content.php',
    'user-items'          => $accountIn . 'user-items-content.php',
    'user-alerts'         => $accountIn . 'user-alerts-content.php',
    'user-profile'        => $accountIn . 'user-profile-content.php',
    'user-signin'         => $accountIn . 'user-signin-content.php',
    'user-custom'         => $accountIn . 'user-custom-content.php',
    'user-delete_account' => $guiDir . 'user-delete_account-content.php',
);
foreach ($slotPages as $slug => $file) {
    $src = (string) file_get_contents($file);
    check("{$slug} fires account_page_before", strpos($src, "osc_run_hook('account_page_before', '{$slug}')") !== false);
    check("{$slug} fires account_page_after", strpos($src, "osc_run_hook('account_page_after', '{$slug}')") !== false);
}

$row = (string) file_get_contents($accountIn . 'parts/item-row.php');
foreach (array('listing_row_badges', 'listing_row_meta', 'listing_row_actions') as $filter) {
    check("the row applies {$filter}", strpos($row, "osc_apply_filter('{$filter}'") !== false);
}
// A blocked listing read "Published" when the row never asked whether it was enabled.
check('the row checks the blocked state before Published', strpos($row, 'osc_item_is_enabled()') !== false
    && strpos($row, 'osc_item_is_enabled()') < strpos($row, "_m('Published')"));

foreach (array('user-dashboard', 'user-items', 'user-alerts', 'user-public-profile') as $page) {
    $src = (string) file_get_contents($accountIn . $page . '-content.php');
    check("{$page} draws its list through osc_gui_listing_list()", strpos($src, 'osc_gui_listing_list(') !== false
        && strpos($src, 'parts/item-row.php') === false);
}

exit(harness_result());
