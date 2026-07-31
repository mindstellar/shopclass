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

use mindstellar\Csrf;
use mindstellar\logger\OsclassErrors;

if (!defined('ABS_PATH')) {
    define('ABS_PATH', __DIR__ . '/');
}

define('LIB_PATH', ABS_PATH . 'oc-includes/');

require_once LIB_PATH . 'osclass/helpers/hErrors.php';
// Load configuration from config.php when present, otherwise from environment
// variables (config.php is optional — Shopclass can run entirely from env).
require_once LIB_PATH . 'osclass/config-loader.php';

if (!osc_is_configured()) {
    osc_die(
        'Shopclass isn\'t set up yet',
        'There\'s no <code>config.php</code> file and no database settings in the environment yet. '
        . 'Run the installer to get started.',
        array(
            'tone'    => 'info',
            'actions' => array(
                array(
                    'label'   => 'Run the installer',
                    'href'    => osc_get_absolute_url() . 'oc-includes/osclass/install.php',
                    'primary' => true,
                ),
                array(
                    'label' => 'Need help?',
                    'href'  => 'https://github.com/mindstellar/shopclass/discussions',
                ),
            ),
        )
    );
}

// load default constants
require_once LIB_PATH . 'osclass/default-constants.php';

//Load Autoloader
require_once LIB_PATH . 'vendor/autoload.php';
//Register error handler
OsclassErrors::newInstance()->register();
require_once LIB_PATH . 'osclass/helpers/hDatabaseInfo.php';
require_once LIB_PATH . 'osclass/helpers/hDatabase.php';
require_once LIB_PATH . 'osclass/helpers/hPreference.php';
// check if Shopclass is installed
if (!Preference::newInstance()->get('osclass_installed')) {
    osc_die(
        'Shopclass isn\'t installed yet',
        'Your settings are in place, but the database hasn\'t been set up yet. Run the installer to finish.',
        array(
            'tone'    => 'info',
            'actions' => array(
                array(
                    'label'   => 'Run the installer',
                    'href'    => osc_get_absolute_url() . 'oc-includes/osclass/install.php',
                    'primary' => true,
                ),
            ),
        )
    );
}
require_once LIB_PATH . 'osclass/helpers/hDefines.php';
require_once LIB_PATH . 'osclass/helpers/hLocale.php';
require_once LIB_PATH . 'osclass/helpers/hMessages.php';
require_once LIB_PATH . 'osclass/helpers/hUsers.php';
require_once LIB_PATH . 'osclass/helpers/hItems.php';
require_once LIB_PATH . 'osclass/helpers/hSearch.php';
require_once LIB_PATH . 'osclass/helpers/hUtils.php';
require_once LIB_PATH . 'osclass/helpers/hCategories.php';
require_once LIB_PATH . 'osclass/helpers/hTranslations.php';
require_once LIB_PATH . 'osclass/helpers/hSecurity.php';
require_once LIB_PATH . 'osclass/helpers/hSanitize.php';
require_once LIB_PATH . 'osclass/helpers/hValidate.php';
require_once LIB_PATH . 'osclass/helpers/hPage.php';
require_once LIB_PATH . 'osclass/helpers/hPagination.php';
require_once LIB_PATH . 'osclass/helpers/hPremium.php';
require_once LIB_PATH . 'osclass/helpers/hTheme.php';
require_once LIB_PATH . 'osclass/helpers/hLocation.php';
require_once LIB_PATH . 'osclass/utils.php';
require_once LIB_PATH . 'osclass/formatting.php';
require_once LIB_PATH . 'osclass/locales.php';
require_once LIB_PATH . 'osclass/helpers/hPlugins.php';
require_once LIB_PATH . 'osclass/helpers/hStorage.php';
require_once LIB_PATH . 'osclass/helpers/hResources.php';
require_once LIB_PATH . 'osclass/emails.php';
require_once LIB_PATH . 'osclass/alerts.php';
require_once LIB_PATH . 'osclass/functions.php';
require_once LIB_PATH . 'osclass/helpers/hAdminMenu.php';
require_once LIB_PATH . 'osclass/helpers/hCache.php';
require_once LIB_PATH . 'osclass/helpers/hHttpCache.php';
require_once LIB_PATH . 'osclass/helpers/hSitemap.php';
require_once LIB_PATH . 'osclass/helpers/hSpam.php';
require_once LIB_PATH . 'osclass/helpers/hWidgets.php';
require_once LIB_PATH . 'osclass/helpers/hPageTemplates.php';
require_once LIB_PATH . 'osclass/helpers/hFields.php';
require_once LIB_PATH . 'osclass/helpers/hForms.php';
require_once LIB_PATH . 'osclass/compatibility.php';


if (!defined('OSC_CRYPT_KEY')) {
    define('OSC_CRYPT_KEY', osc_get_preference('crypt_key'));
}

osc_cache_init();

define('__OSC_LOADED__', true);
Params::init();
// Core owns Cache-Control on the front end (see hHttpCache.php). Silence PHP's session module so
// it never injects its own no-store/no-cache headers when a session starts — those would fight the
// app's public/private decision. Admin keeps PHP's default limiter: it is never cached, and its
// implicit no-cache stays its safety net.
if (!defined('OC_ADMIN') || OC_ADMIN !== true) {
    session_cache_limiter('');
}
Session::newInstance()->session_resume();
// Consume flash messages left by the previous request from their signed cookie (and clear
// it) before any output — so a page that only shows a flash never starts a session.
Session::newInstance()->_loadFlashMessages();
// Same for form-repopulation values, so a form that refills after a validation error does
// not need a session either.
Session::newInstance()->_loadFormData();
// Resolve a cookie-authenticated front-end identity into the request scope so the
// historical Session::_get('userId') readers work without a server session. No-op for
// anonymous, cookieless visitors, so their requests stay session-free and cacheable.
osc_run_web_user_identity();

if (osc_timezone()) {
    date_default_timezone_set(osc_timezone());
}


Scripts::init();
Styles::init();

// register scripts
//
// jQuery is no longer used anywhere in core — the admin and every core form are vanilla.
// These three stay registered because the bundled `bender` front theme still enqueues
// them (it lives in its own repository, so it migrates separately). Nothing in core
// enqueues them, so they cost nothing until a theme asks for them.
osc_register_script('jquery', osc_assets_url('jquery/jquery.min.js'));
osc_register_script('jquery-ui', osc_assets_url('jquery-ui/jquery-ui.min.js'), 'jquery');
osc_register_script('jquery-validate', osc_assets_url('jquery-validation/jquery.validate.min.js'), 'jquery');

osc_register_script('tiny_mce', osc_assets_url('tinymce/tinymce.min.js'));

// Shared vanilla UI helpers (oscAutocomplete, …) — no jQuery. Used by the admin
// (as a dependency of ui-osc.js) and by the public item form via osc_ui_common_header().
osc_register_script('osc-ui-common', osc_asset_url_versioned(osc_assets_url('osclass/ui-common.js')));
osc_register_style('osc-ui-common', osc_asset_url_versioned(osc_assets_url('osclass/ui-common.css')));

// Vanilla image uploader for the item form (replaces the jQuery fine-uploader plugin).
osc_register_script('osc-uploader', osc_asset_url_versioned(osc_assets_url('osclass/osc-uploader.js')));
osc_register_style('osc-uploader', osc_asset_url_versioned(osc_assets_url('osclass/osc-uploader.css')));

//Legacy js libraries
osc_register_script('php-date', osc_assets_url('osclass-legacy/js/date.js'));

Plugins::init();
if (defined('OC_ADMIN') && OC_ADMIN) {
    // init admin menu
    AdminMenu::newInstance()->init();
    $functions_path = AdminThemes::newInstance()->getCurrentThemePath() . 'functions.php';
    if (file_exists($functions_path)) {
        require_once $functions_path;
    }
}
WebThemes::init();
Translation::init();
Csrf::init();
Rewrite::newInstance()->init();
