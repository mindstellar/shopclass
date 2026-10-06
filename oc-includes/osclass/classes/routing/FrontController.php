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

declare(strict_types=1);

namespace mindstellar\routing;

use Cookie;
use Params;
use Session;
use Sitemap;
use User;
use WebThemes;

/**
 * The public site's request, from oc-load.php to the response: the command-line entry,
 * the maintenance gate, the signed-in user's upkeep, then the page's controller.
 */
final class FrontController
{
    public static function run(bool $cli): void
    {
        if ($cli) {
            self::runCli();
        }

        // A cookie never authenticates an API call: forget the browser's identity before anything reads it.
        $api = Params::getParamString('page') === 'api';
        if ($api) {
            \mindstellar\apiaccess\ApiAccess::begin();
        }

        self::maintenanceGate($api, $cli);

        if (!$api) {
            self::userUpkeep();
        }

        PageDispatcher::web()->dispatch(Params::getParamString('page'), Params::getParamString('action'));

        // Stamp Cache-Control now that the page is built and identity/session state is final.
        // A controller that streamed a file, redirected or exited never reaches here.
        osc_send_response_cache_headers();

        osc_auto_cron_dispatch();
    }

    /**
     * Route handler for `?page=cron`.
     */
    public static function cron(): void
    {
        define('__FROM_CRON__', true);
        // A restore is half-way: scheduled tasks would run against a half-loaded database.
        if (!osc_maintenance_is_restoring(ABS_PATH . '.maintenance')) {
            require_once LIB_PATH . 'osclass/cron.php';
        }
    }

    /**
     * Route handler for `?page=sitemap`.
     */
    public static function sitemap(): void
    {
        Sitemap::getInstance()->serve();
    }

    /**
     * Legacy entry: `php index.php -p cron -t hourly`. Richer commands live in oc-cli.php.
     */
    private static function runCli(): void
    {
        $params = getopt('p:t:');
        if ($params) {
            if (isset($params['p'])) {
                Params::setParam('page', $params['p']);
            }
            if (isset($params['t'])) {
                Params::setParam('cron-type', $params['t']);
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

    /**
     * Answer 503 while the site is in maintenance, unless the visitor may pass.
     * Admins and the command line pass, except while a package upgrade replaces files.
     */
    private static function maintenanceGate(bool $api, bool $cli): void
    {
        if (!file_exists(ABS_PATH . '.maintenance')) {
            return;
        }

        if (!osc_maintenance_should_lockout_request(
            true,
            osc_maintenance_lockout_enabled(),
            !$api && osc_is_admin_user_logged_in(),
            $cli,
            osc_maintenance_locks_everyone(ABS_PATH . '.maintenance')
        )) {
            if (!$cli) {
                define('__OSC_MAINTENANCE__', true);
            }

            return;
        }

        if ($api) {
            \mindstellar\apiaccess\ApiAccess::maintenance();
        }

        header('HTTP/1.1 503 Service Temporarily Unavailable');
        header('Status: 503 Service Temporarily Unavailable');
        header('Retry-After: 900');
        header('Cache-Control: no-store');

        $message = osc_maintenance_visitor_message();
        if (!defined('OSC_MAINTENANCE_MESSAGE')) {
            define('OSC_MAINTENANCE_MESSAGE', $message);
        }

        if (file_exists(WebThemes::getInstance()->getCurrentThemePath() . 'maintenance.php')) {
            osc_current_web_theme_path('maintenance.php');
            die();
        }

        osc_die(
            sprintf(__('Maintenance &raquo; %s'), osc_page_title()),
            nl2br((string) osc_esc_html($message), false),
            array(
                'heading'   => __('We\'ll be right back'),
                'tone'      => 'info',
                'status'    => 503,
                // The database is reachable in maintenance mode, so show the site's own name.
                'brandName' => osc_page_title(),
            )
        );
    }

    /**
     * Sign the user out when user accounts are off, else record their last visit.
     */
    private static function userUpkeep(): void
    {
        if (!osc_users_enabled() && osc_is_web_user_logged_in()) {
            Session::getInstance()->_drop('userId');
            Session::getInstance()->_drop('userName');
            Session::getInstance()->_drop('userEmail');
            Session::getInstance()->_drop('userPhone');

            Cookie::getInstance()->pop('oc_userId');
            Cookie::getInstance()->pop('oc_userSecret');
            Cookie::getInstance()->set();
        }

        if (osc_is_web_user_logged_in()) {
            User::getInstance()->lastAccess(
                osc_logged_user_id(),
                date('Y-m-d H:i:s'),
                Params::getServerParam('REMOTE_ADDR'),
                3600
            );
        }
    }
}
