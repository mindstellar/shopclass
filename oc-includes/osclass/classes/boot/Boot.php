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

namespace mindstellar\boot;

/**
 * Parts of oc-load.php's start-up: the helper list it requires, and the session step.
 */
final class Boot
{
    /** The helper files every request loads once the database is installed, in order, relative to LIB_PATH. */
    public const HELPERS = [
        'osclass/helpers/hDatabaseInfo.php',
        'osclass/helpers/hDatabase.php',
        'osclass/helpers/hPreference.php',
        'osclass/helpers/hMaintenance.php',
        'osclass/helpers/hDefines.php',
        'osclass/helpers/hLocale.php',
        'osclass/helpers/hMessages.php',
        'osclass/helpers/hUsers.php',
        'osclass/helpers/hItems.php',
        'osclass/helpers/hSearch.php',
        'osclass/helpers/hUtils.php',
        'osclass/helpers/hCategories.php',
        'osclass/helpers/hTranslations.php',
        'osclass/helpers/hSecurity.php',
        'osclass/helpers/hSanitize.php',
        'osclass/helpers/hValidate.php',
        'osclass/helpers/hPage.php',
        'osclass/helpers/hPagination.php',
        'osclass/helpers/hPremium.php',
        'osclass/helpers/hTheme.php',
        'osclass/helpers/hLocation.php',
        'osclass/utils.php',
        'osclass/formatting.php',
        'osclass/locales.php',
        'osclass/helpers/hPlugins.php',
        'osclass/helpers/hJobs.php',
        'osclass/helpers/hKv.php',
        'osclass/helpers/hStorage.php',
        'osclass/helpers/hResources.php',
        'osclass/emails.php',
        'osclass/alerts.php',
        'osclass/functions.php',
        'osclass/helpers/hAdminMenu.php',
        'osclass/helpers/hCache.php',
        'osclass/helpers/hHttpCache.php',
        'osclass/helpers/hApi.php',
        'osclass/helpers/hViews.php',
        'osclass/helpers/hSitemap.php',
        'osclass/helpers/hSpam.php',
        'osclass/helpers/hWidgets.php',
        'osclass/helpers/hPageTemplates.php',
        'osclass/helpers/hFields.php',
        'osclass/helpers/hSettings.php',
        'osclass/helpers/hForms.php',
        'osclass/helpers/hBilling.php',
        'osclass/compatibility.php',
    ];

    private function __construct()
    {
    }

    /**
     * Resume the visitor's session and read what the last page left for this one; needs the helpers loaded.
     * An API call forgets the browser's identity later, in FrontController.
     */
    public static function session(): void
    {
        // Core owns Cache-Control on the front end, so PHP's session module must not add its own.
        if (!\defined('OC_ADMIN') || OC_ADMIN !== true) {
            session_cache_limiter('');
        }
        \Session::getInstance()->session_resume();
        // Flash messages and form values come from signed cookies, so a page that only shows them starts no session.
        \Session::getInstance()->_loadFlashMessages();
        \Session::getInstance()->_loadFormData();
        osc_run_web_user_identity();
    }
}
