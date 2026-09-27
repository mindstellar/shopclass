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
        $attr = (string) preg_replace('/<\?php.*?\?>/s', ' ', $attr);
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

$rowParts = $row . (string) file_get_contents($accountIn . 'parts/row-actions.php');
check('a POST row action carries the CSRF token', strpos($rowParts, "osc_csrf_token_form()") !== false
    && strpos($rowParts, "'method'] ?? 'get') === 'post'") !== false);
check('the row offers paid upgrades on the owner list', strpos($row, 'osc_item_upgrade_offers(') !== false);
// A <form> inside a <p> closes the paragraph, so action lines must not be one.
check('action lines are not paragraphs', strpos($rowParts, '<p class="oe-meta oe-row-actions') === false);

$profile = (string) file_get_contents($accountIn . 'user-public-profile-content.php');
check('the public profile posts user contact_post', strpos($profile, "'hidden'  => array('page' => 'user', 'action' => 'contact_post'") !== false);
check('the public profile form fires user_contact_form', strpos($profile, "osc_run_hook('user_contact_form', ") !== false);
$contactPart = (string) file_get_contents($guiDir . 'parts/contact-form.php');
check('the shared contact form refills after a failed send', strpos($contactPart, "osc_gui_kept('message_body')") !== false);
foreach (array($guiDir . 'contact-content.php', $guiDir . 'item-contact-content.php', $accountIn . 'user-public-profile-content.php') as $file) {
    $src = (string) file_get_contents($file);
    check(basename($file) . ' uses the shared contact form', strpos($src, "parts/contact-form.php'") !== false
        && strpos($src, 'name="yourName"') === false);
}
$nonSecure = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebUserNonSecure.php');
preg_match("/case 'contact_post':.*?break;/s", $nonSecure, $contactCase);
check('user contact_post checks the CSRF token', isset($contactCase[0]) && strpos($contactCase[0], 'osc_csrf_check()') !== false);
check('user contact_post is throttled', isset($contactCase[0]) && strpos($contactCase[0], "ActionThrottle::exceeded(\n                    'user_contact'") !== false);

$alertsSrc = (string) file_get_contents($accountIn . 'user-alerts-content.php');
check('alerts apply alert_row_actions', strpos($alertsSrc, "osc_apply_filter('alert_row_actions'") !== false);
check('alerts draw actions through the shared row-actions part', strpos($alertsSrc, "parts/row-actions.php") !== false);

$profileForm = (string) file_get_contents($accountIn . 'user-profile-content.php');
// Without these fields, profile_post saved b_company = 0 and an empty city area.
check('the profile form posts b_company', strpos($profileForm, 'UserForm::is_company_select(') !== false);
check('the profile form posts cityArea', strpos($profileForm, 'UserForm::city_area_text(') !== false);
check('the profile form fires user_avatar_form', strpos($profileForm, "osc_run_hook('user_avatar_form', ") !== false);
check('the profile form never lists every city', strpos(
    $profileForm,
    "osc_user_field('fk_i_region_id') ? osc_get_cities(osc_user_field('fk_i_region_id')) : array()"
) !== false);

// A token in a delete URL lands in logs, so core's own Delete posts a form.
check("core's Delete is a POST action", (bool) preg_match("/'delete'\\] = array\\(.*?'method'\\s*=> 'post'/s", $row));
check('the CSRF token is posted only to this site', strpos($rowParts, 'strpos($actionUrl, $siteRoot) === 0') !== false);

check('the profile contact form is a dialog the head button opens', strpos($profile, 'id="oe-contact-dialog"') !== false
    && strpos($profile, 'data-osc-dialog-open="oe-contact-dialog"') !== false);

// Every contact form's controller keeps the typed values and the reason through one helper.
$hUtils = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php');
check('osc_keep_form() stores the reason the form reads', strpos($hUtils, '_setForm(\'contact_error\', $error)') !== false
    && strpos($contactPart, "osc_gui_kept('contact_error')") !== false);
foreach (array('CWebUserNonSecure', 'CWebContact', 'CWebItem') as $controller) {
    $src = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/' . $controller . '.php');
    check("{$controller} keeps a failed contact send through osc_keep_form()", strpos($src, 'osc_keep_form(') !== false);
}
$contactCtl = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebContact.php');
check('the contact page refuses an empty message, and only that', strpos($contactCtl, 'if (trim($message) === \'\') {') !== false
    && strpos($contactCtl, 'trim($subject) === ') === false);

exit(harness_result());
