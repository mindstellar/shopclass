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
 * Pins the installer's site details step (form half) and the server answers behind it.
 *
 * DB-free.  Usage: php tests/install-site-details.php
 */

namespace mindstellar\location {
    class LocationCatalog
    {
        public function status(): array
        {
            return array(
                array('code' => 'GB', 'name' => 'United Kingdom'),
                array('code' => 'IN', 'name' => 'India'),
            );
        }
    }
}

namespace {
    define('ABS_PATH', dirname(__DIR__) . '/');
    define('LIB_PATH', ABS_PATH . 'oc-includes/');
    define('CONTENT_PATH', sys_get_temp_dir() . '/ins-no-content/');
    define('OSCLASS_VERSION', '7.1.0');

    function __($text)
    {
        return $text;
    }

    function _e($text)
    {
        echo $text;
    }

    function install_site_choice()
    {
        return array('type' => 'classifieds', 'sample' => !empty($GLOBALS['ins_test_sample']), 'plugins' => array());
    }

    require_once LIB_PATH . 'vendor/autoload.php';
    require_once LIB_PATH . 'osclass/helpers/hSanitize.php';
    require_once __DIR__ . '/lib/harness.php';

    $GLOBALS['install_nonce'] = 'nonce123';

    $dir = ABS_PATH . 'oc-includes/osclass/installer/gui/';
    ob_start();
    include $dir . 'install-target.php';
    $html = (string) ob_get_clean();

    harness_section('form');
    check('site name is labelled and has an error line', (bool) preg_match('/<label for="webtitle">Site name/', $html) && str_contains($html, 'id="webtitle-error"'));
    check('old labels are gone', !str_contains($html, 'Web title') && !str_contains($html, 'Set up my site'));
    check('same post names', str_contains($html, 'name="s_name"') && str_contains($html, 'name="webtitle"') && str_contains($html, 'name="email"') && str_contains($html, 'name="s_passwd"'));
    check('password has reveal and generate', str_contains($html, 'data-reveal-target="s_passwd"') && str_contains($html, 'id="ins-gen-s_passwd"'));
    check('username error id follows the input id', str_contains($html, 'id="admin_user-error"'));
    check('country select keeps its name and the skip option', (bool) preg_match('/<select[^>]*name="location-json"/', $html) && str_contains($html, '<option value="skip"'));
    check('countries are listed', str_contains($html, '<option value="IN">India</option>'));
    check('Back goes to step 3', str_contains($html, 'href="install.php?step=3"'));
    check('submit says Install Shopclass', str_contains($html, 'Install Shopclass'));
    check('no overlay and no shop queue', !str_contains($html, 'ins-setup-overlay') && !str_contains($html, 'ins-overlay') && !str_contains($html, 'data-shop-queue'));

    harness_section('error panels');
    check('account error panel has an icon, title and the default text, hidden', (bool) preg_match('/<div id="ins-target-error" class="ins-panel ins-panel-danger" role="alert" tabindex="-1" hidden><svg class="ins-icon ins-panel-icon".*?<p class="ins-panel-title">We couldn&#039;t create your admin account<\/p><p class="ins-panel-text">The server didn&#039;t answer\. Your details are still here\. Press Install Shopclass to try again\.<\/p>/s', $html));
    check('progress error panel is hidden with an empty text slot', (bool) preg_match('/<div id="ins-progress-error" class="ins-panel ins-panel-danger"[^>]*\shidden>.*?<p class="ins-panel-title">Setup stopped<\/p><p class="ins-panel-text"><\/p>/s', $html));
    check('hidden reload link goes back to step 4', (bool) preg_match('/<a id="ins-progress-reload" class="ins-btn ins-btn-primary" href="install.php\?step=4" hidden>Reload<\/a>/', $html));
    check('announcer is a polite status region', (bool) preg_match('/<div id="ins-progress-announcer"[^>]*role="status"[^>]*aria-live="polite"/', $html));
    check('noscript warning uses the panel', (bool) preg_match('/<noscript>\s*<div class="ins-panel ins-panel-warning".*?JavaScript is required/s', $html));

    harness_section('progress view');
    check('progress section starts hidden', (bool) preg_match('/<section id="ins-progress"[^>]*\shidden[\s>]/', $html));
    check('track is a progressbar from 0 to 100', (bool) preg_match('/id="ins-track"[^>]*role="progressbar"[^>]*aria-valuemin="0"[^>]*aria-valuemax="100"[^>]*aria-valuenow="0"/', $html));
    check('slow hint, task list, meter lines exist', str_contains($html, 'id="ins-slow"') && str_contains($html, 'id="ins-tasks"') && str_contains($html, 'id="ins-meter-now"') && str_contains($html, 'id="ins-meter-count"') && str_contains($html, 'id="ins-fill"'));
    check('title carries a template for the site name', str_contains($html, 'id="ins-progress-title"') && str_contains($html, 'data-template="Setting up %s"'));
    $plan = null;
    if (preg_match('/data-install-plan="([^"]*)"/', $html, $m)) {
        $plan = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    }
    check('install plan decodes to a list starting with the account task', is_array($plan) && ($plan[0]['id'] ?? '') === 'account' && !empty($plan[0]['stop']));
    check('plan has starter and places, no examples by default', is_array($plan) && array_column($plan, 'id') === array('account', 'starter', 'locations', 'storefront'));
    $GLOBALS['ins_test_sample'] = true;
    ob_start();
    include $dir . 'install-target.php';
    $sampleHtml = (string) ob_get_clean();
    preg_match('/data-install-plan="([^"]*)"/', $sampleHtml, $m2);
    $samplePlan = json_decode(html_entity_decode($m2[1] ?? '', ENT_QUOTES), true);
    check('examples add photos and listings tasks', is_array($samplePlan) && in_array('photos', array_column($samplePlan, 'id'), true) && in_array('examples', array_column($samplePlan, 'id'), true));

    $shell = (string) file_get_contents($dir . 'install.php');
    harness_section('rail');
    check('six labels with Install before Done', (bool) preg_match("/4 => __\('Your site'\),\s*5 => __\('Install'\),\s*6 => __\('Done'\),/", $shell));
    check('step 5 shows rail stop 6', str_contains($shell, '$step === 5) {' . "\n" . '    $ins_rail_step = 6;'));
    check('every stop has data-rail, a number and a check', str_contains($shell, 'data-rail="') && str_contains($shell, 'class="ins-step-num"') && str_contains($shell, 'class="ins-step-check"'));
    check('phone label and count have ids', str_contains($shell, 'id="ins-mstep-label"') && str_contains($shell, 'id="ins-mstep-count"'));
    check('old progress strings are gone from the i18n map', !str_contains($shell, "'sampleBatch'") && !str_contains($shell, "'shopMode'") && str_contains($shell, "'doneCount'"));

    $finish = (string) file_get_contents($dir . 'install-finish.php');
    harness_section('finish');
    check('finish uses the shared reveal button', str_contains($finish, "install_reveal_button('ins-cred-password'") && !str_contains($finish, 'ins-icon-show'));

    harness_section('site details check');
    $problem = static fn (string $user, string $email, string $name): ?array => \mindstellar\install\SiteDetails::problem($user, $email, $name);
    $nameError = array('error' => 'Give your site a name.', 'field' => 'webtitle');
    check('empty site name is refused on webtitle', $problem('admin', 'a@example.com', '') === $nameError);
    check('spaces-only site name is refused on webtitle', $problem('admin', 'a@example.com', "  \t ") === $nameError);
    check('a valid name passes', $problem('admin', 'a@example.com', 'My site') === null);
    check('bad username names admin_user', ($problem('ad min', 'a@example.com', 'My site')['field'] ?? '') === 'admin_user');
    check('bad email names email', ($problem('admin', 'nope', 'My site')['field'] ?? '') === 'email');

    $loc = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/install-functions.php');
    harness_section('install-functions.php');
    $guard = strpos($loc, 'SiteDetails::problem(');
    $basic = strpos($loc, 'basic_info()');
    check('site details check runs before basic_info()', $guard !== false && $basic !== false && $guard < $basic);
    check('its answer goes out with status false', str_contains($loc, "return array('status' => false) + \$problem;"));

    exit(harness_result());
}
