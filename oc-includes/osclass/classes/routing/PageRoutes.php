<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\routing;

/**
 * Which controller answers each `?page=` value, for the site and for the admin.
 *
 * A route is a controller class name, or an array:
 *   controller  class run by default (needs doModel())
 *   actions     action => class run instead for that `?action=`
 *   guest       action => class run instead for that action when no user is signed in
 *   handler     'Class::method' run instead of a controller
 * A page not in the table goes to the fallback controller.
 */
final class PageRoutes
{
    public const WEB_FALLBACK   = 'CWebMain';
    public const ADMIN_FALLBACK = 'CAdminMain';

    /**
     * @return array<string,string|array<string,mixed>>
     */
    public static function web(): array
    {
        return array(
            'cron'     => array('handler' => FrontController::class . '::cron'),
            'user'     => array(
                'controller' => 'CWebUser',
                'actions'    => array(
                    'change_email_confirm' => 'CWebUserNonSecure',
                    'activate_alert'       => 'CWebUserNonSecure',
                    'contact_post'         => 'CWebUserNonSecure',
                    'pub_profile'          => 'CWebUserNonSecure',
                ),
                'guest'      => array('unsub_alert' => 'CWebUserNonSecure'),
            ),
            'item'     => 'CWebItem',
            'billing'  => array(
                'controller' => 'CWebBilling',
                'actions'    => array('callback' => 'CWebBillingNonSecure'),
            ),
            'resource' => 'CWebResource',
            'search'   => 'CWebSearch',
            'page'     => 'CWebPage',
            'register' => 'CWebRegister',
            'ajax'     => 'CWebAjax',
            'login'    => 'CWebLogin',
            'language' => 'CWebLanguage',
            'contact'  => 'CWebContact',
            'form'     => 'CWebForm',
            'custom'   => 'CWebCustom',
            'route'    => 'CWebRoute',
            'api'      => 'CWebApi',
            'sitemap'  => array('handler' => FrontController::class . '::sitemap'),
        );
    }

    /**
     * @return array<string,string|array<string,mixed>>
     */
    public static function admin(): array
    {
        return array(
            'items'      => 'CAdminItems',
            'comments'   => 'CAdminItemComments',
            'media'      => 'CAdminMedia',
            'login'      => 'CAdminLogin',
            'categories' => 'CAdminCategories',
            'emails'     => 'CAdminEmails',
            'pages'      => 'CAdminPages',
            'settings'   => 'CAdminSettings',
            'plugins'    => 'CAdminPlugins',
            'languages'  => 'CAdminLanguages',
            'admins'     => 'CAdminAdmins',
            'users'      => 'CAdminUsers',
            'ajax'       => 'CAdminAjax',
            'appearance' => 'CAdminAppearance',
            'tools'      => 'CAdminTools',
            'billing'    => 'CAdminBilling',
            'stats'      => 'CAdminStats',
            'cfields'    => 'CAdminCFields',
            'upgrade'    => 'CAdminUpgrade',
        );
    }
}
