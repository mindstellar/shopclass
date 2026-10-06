<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI === 'cli') {
    define('CLI', true);
} else {
    define('CLI', false);
}

require_once __DIR__ . '/oc-load.php';

if (CLI) {
    // Legacy entry: `php index.php -p cron -t hourly`. Richer maintenance
    // commands live in oc-cli.php.
    $cli_params = getopt('p:t:');
    if ($cli_params) {
        if (isset($cli_params['p'])) {
            Params::setParam('page', $cli_params['p']);
        }
        if (isset($cli_params['t'])) {
            Params::setParam('cron-type', $cli_params['t']);
        }
    }
    if (Params::getParam('page') === 'upgrade') {
        $result  = \mindstellar\upgrade\Osclass::upgradeDB();
        $decoded = json_decode((string) $result, true);
        echo $result, PHP_EOL;

        exit(is_array($decoded) && (int) ($decoded['error'] ?? 1) === 0 ? 0 : 1);
    }

    if (Params::getParam('page') !== 'cron'
        && !in_array(Params::getParam('cron-type'), array('hourly', 'daily', 'weekly'))
    ) {
        exit(1);
    }

    // During a restore cron carries the restore on and runs nothing else.
    if (osc_maintenance_is_restoring(ABS_PATH . '.maintenance')) {
        \mindstellar\job\JobWorker::run(50);
        exit(0);
    }
}

// A cookie never authenticates an API call: forget the browser's identity before anything reads it.
$osc_api_request = Params::getParamString('page') === 'api';
if ($osc_api_request) {
    \mindstellar\apiaccess\ApiAccess::begin();
}

if (file_exists(ABS_PATH . '.maintenance')) {
    // Default is a public 503 (same as before this option existed). Unchecking
    // lockout in Tools → Maintenance leaves the site up and shows a banner.
    // CLI is not 503'd, so `php index.php -p cron` still runs, except while a
    // package upgrade is replacing files. Admins always get through.
    if (osc_maintenance_should_lockout_request(
        true,
        osc_maintenance_lockout_enabled(),
        !$osc_api_request && osc_is_admin_user_logged_in(),
        CLI,
        osc_maintenance_locks_everyone(ABS_PATH . '.maintenance')
    )) {
        if ($osc_api_request) {
            \mindstellar\apiaccess\ApiAccess::maintenance();
        }

        header('HTTP/1.1 503 Service Temporarily Unavailable');
        header('Status: 503 Service Temporarily Unavailable');
        header('Retry-After: 900');
        header('Cache-Control: no-store');

        $maintenanceMessage = osc_maintenance_visitor_message();
        if (!defined('OSC_MAINTENANCE_MESSAGE')) {
            define('OSC_MAINTENANCE_MESSAGE', $maintenanceMessage);
        }

        if (file_exists(WebThemes::getInstance()->getCurrentThemePath() . 'maintenance.php')) {
            osc_current_web_theme_path('maintenance.php');
            die();
        }

        require_once LIB_PATH . 'osclass/helpers/hErrors.php';

        osc_die(
            sprintf(__('Maintenance &raquo; %s'), osc_page_title()),
            nl2br(osc_esc_html($maintenanceMessage), false),
            array(
                'heading'   => __('We\'ll be right back'),
                'tone'      => 'info',
                'status'    => 503,
                // The database is reachable in maintenance mode, so show the
                // site's own name rather than the generic wordmark.
                'brandName' => osc_page_title(),
            )
        );
    } elseif (!CLI) {
        define('__OSC_MAINTENANCE__', true);
    }
}

if (!$osc_api_request && !osc_users_enabled() && osc_is_web_user_logged_in()) {
    Session::getInstance()->_drop('userId');
    Session::getInstance()->_drop('userName');
    Session::getInstance()->_drop('userEmail');
    Session::getInstance()->_drop('userPhone');

    Cookie::getInstance()->pop('oc_userId');
    Cookie::getInstance()->pop('oc_userSecret');
    Cookie::getInstance()->set();
}

if (!$osc_api_request && osc_is_web_user_logged_in()) {
    User::getInstance()->lastAccess(
        osc_logged_user_id(),
        date('Y-m-d H:i:s'),
        Params::getServerParam('REMOTE_ADDR'),
        3600
    );
}

switch (Params::getParam('page')) {
    case ('cron'):      // cron system
        define('__FROM_CRON__', true);
        // A restore is half-way: scheduled tasks would run against a half-loaded database.
        if (!osc_maintenance_is_restoring(ABS_PATH . '.maintenance')) {
            require_once(LIB_PATH . 'osclass/cron.php');
        }
        break;
    case ('user'):      // user pages (with security)
        $osclass_action = Params::getParam('action');
        if ($osclass_action === 'change_email_confirm'
            || $osclass_action === 'activate_alert'
            || $osclass_action === 'contact_post'
            || $osclass_action === 'pub_profile'
            || ($osclass_action === 'unsub_alert' && !osc_is_web_user_logged_in())

        ) {
            $do = new CWebUserNonSecure();
        } else {
            $do = new CWebUser();
        }
        $do->doModel();
        break;
    case ('item'):      // item pages
        $do = new CWebItem();
        $do->doModel();
        break;
    case ('billing'):   // wallet, checkout, orders, and the gateway callback
        if (Params::getParam('action') === 'callback') {
            $do = new CWebBillingNonSecure();
        } else {
            $do = new CWebBilling();
        }
        $do->doModel();
        break;
    case ('resource'):  // resource download (friendly Content-Disposition name)
        $do = new CWebResource();
        $do->doModel();
        break;
    case ('search'):    // search pages
        $do = new CWebSearch();
        $do->doModel();
        break;
    case ('page'):      // static pages
        $do = new CWebPage();
        $do->doModel();
        break;
    case ('register'):  // register page
        $do = new CWebRegister();
        $do->doModel();
        break;
    case ('ajax'):      // ajax
        $do = new CWebAjax();
        $do->doModel();
        break;
    case ('login'):     // login page
        $do = new CWebLogin();
        $do->doModel();
        break;
    case ('language'):  // set language
        $do = new CWebLanguage();
        $do->doModel();
        break;
    case ('contact'):   //contact
        $do = new CWebContact();
        $do->doModel();
        break;
    case ('form'):      // custom form submission (core.form widget)
        $do = new CWebForm();
        $do->doModel();
        break;
    case ('custom'):   //custom
        $do = new CWebCustom();
        $do->doModel();
        break;
    case ('route'):     // hook routes (osc_add_route_hook)
        $do = new CWebRoute();
        $do->doModel();
        break;
    case ('api'):       // REST API (/api/v1/...)
        $do = new CWebApi();
        $do->doModel();
        break;
    case ('sitemap'):   // core XML sitemap (index + child sitemaps)
        Sitemap::getInstance()->serve();
        break;
    default:            // home and static pages that are mandatory...
        $do = new CWebMain();
        $do->doModel();
        break;
}

// Stamp the response's Cache-Control now that the page is built and identity/session state is
// final. Output is buffered (Csrf::init), so headers are not yet sent; a controller that streamed
// a file, redirected, or exited never reaches here and keeps its own headers.
osc_send_response_cache_headers();

osc_auto_cron_dispatch();

/* file end: ./index.php */
