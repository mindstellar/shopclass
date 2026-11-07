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
        return array('type' => 'classifieds', 'sample' => false, 'plugins' => array());
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
    check('overlay and queue stay', str_contains($html, 'id="ins-setup-overlay"') && str_contains($html, 'data-shop-queue='));

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

    $loc = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/install-location.php');
    harness_section('install-location.php');
    $guard = strpos($loc, 'SiteDetails::problem(');
    $basic = strpos($loc, 'basic_info()');
    check('site details check runs before basic_info()', $guard !== false && $basic !== false && $guard < $basic);
    check('its answer goes out with status false', str_contains($loc, 'json_encode(array(\'status\' => false) + $detailsProblem)'));

    exit(harness_result());
}
