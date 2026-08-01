<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
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
}

if (file_exists(ABS_PATH . '.maintenance')) {
    if (osc_is_admin_user_logged_in()) {
        define('__OSC_MAINTENANCE__', true);
    } else {
        header('HTTP/1.1 503 Service Temporarily Unavailable');
        header('Status: 503 Service Temporarily Unavailable');
        header('Retry-After: 900');

        if (file_exists(WebThemes::newInstance()->getCurrentThemePath() . 'maintenance.php')) {
            osc_current_web_theme_path('maintenance.php');
            die();
        }

        require_once LIB_PATH . 'osclass/helpers/hErrors.php';

        osc_die(
            sprintf(__('Maintenance &raquo; %s'), osc_page_title()),
            sprintf(
                __('%s is undergoing maintenance right now. We\'re making some improvements and will be back shortly — thanks for your patience.'),
                osc_page_title()
            ),
            array(
                'heading'   => __('We\'ll be right back'),
                'tone'      => 'info',
                'status'    => 503,
                // The database is reachable in maintenance mode, so show the
                // site's own name rather than the generic wordmark.
                'brandName' => osc_page_title(),
            )
        );
    }
}

if (!osc_users_enabled() && osc_is_web_user_logged_in()) {
    Session::newInstance()->_drop('userId');
    Session::newInstance()->_drop('userName');
    Session::newInstance()->_drop('userEmail');
    Session::newInstance()->_drop('userPhone');

    Cookie::newInstance()->pop('oc_userId');
    Cookie::newInstance()->pop('oc_userSecret');
    Cookie::newInstance()->set();
}

if (osc_is_web_user_logged_in()) {
    User::newInstance()->lastAccess(
        osc_logged_user_id(),
        date('Y-m-d H:i:s'),
        Params::getServerParam('REMOTE_ADDR'),
        3600
    );
}

switch (Params::getParam('page')) {
    case ('cron'):      // cron system
        define('__FROM_CRON__', true);
        require_once(LIB_PATH . 'osclass/cron.php');
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
    case ('route'):
        if (Params::getParam('route')) {
            osc_run_hook(Params::getParam('route'));
        }
        break;
    case ('sitemap'):   // core XML sitemap (index + child sitemaps)
        Sitemap::newInstance()->serve();
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

if (!defined('__FROM_CRON__') && osc_auto_cron()) {
    // Auto-cron sends a fire-and-forget self request to run scheduled tasks. Left ungated it
    // fires on EVERY page view, so a busy site hammers itself with one internal POST per hit
    // (each spawns an FPM worker). Throttle it to at most one dispatch per 5 minutes.
    //
    // Prefer the object cache as the lock: with a real backend (memcached/apcu) the window is
    // shared across every web node and every locale (Object_Cache_Factory directly, not the
    // locale-suffixed osc_cache_* helpers). The default driver is a per-request array that never
    // survives between requests and so cannot throttle anything, so there fall back to the
    // modification time of a stamp file under uploads/, no cache backend required. Either path
    // fails open (write fails or file unwritable => cron still runs), never closed.
    $autocron_window = 300;
    $autocron_fire   = false;
    $autocron_cache  = Object_Cache_Factory::newInstance();

    if (!($autocron_cache instanceof Object_Cache_default)) {
        $autocron_found = false;
        if ($autocron_cache->get('osclass_autocron_tick', $autocron_found) === false) {
            $autocron_cache->set('osclass_autocron_tick', 1, $autocron_window);
            $autocron_fire = true;
        }
    } else {
        // A dotfile, so a "deny hidden files" web-server rule keeps it unreadable; it carries no
        // data anyway, only its mtime matters.
        $autocron_stamp = osc_uploads_path() . '.autocron_tick';
        if (!file_exists($autocron_stamp) || (time() - (int)@filemtime($autocron_stamp)) >= $autocron_window) {
            @touch($autocron_stamp);
            $autocron_fire = true;
        }
    }

    if ($autocron_fire) {
        \mindstellar\utility\Utils::doRequest(osc_base_url(), array('page' => 'cron'));
    }
}

/* file end: ./index.php */
