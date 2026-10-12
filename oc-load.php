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

use mindstellar\boot\Boot;
use mindstellar\logger\ErrorHandler;
use mindstellar\security\Csrf;

// Stage 1, config: paths and the settings from config.php or the environment.
if (!defined('ABS_PATH')) {
    define('ABS_PATH', __DIR__ . '/');
}
if (!defined('LIB_PATH')) {
    define('LIB_PATH', ABS_PATH . 'oc-includes/');
}

require_once LIB_PATH . 'osclass/helpers/hErrors.php';
// config.php is optional: Shopclass can run entirely from environment variables.
require_once LIB_PATH . 'osclass/config-loader.php';

if (!osc_is_configured()) {
    _osc_die_not_ready(
        'Shopclass isn\'t set up yet',
        'There\'s no <code>config.php</code> file and no database settings in the environment yet. '
        . 'Run the installer to get started.',
        true
    );
}

// A command line has no request to take the site address from.
if (PHP_SAPI === 'cli' && !defined('WEB_PATH')) {
    fwrite(STDERR, "Set OSC_CLI_URL to the site's address, e.g. OSC_CLI_URL=http://localhost:8000/ (or WEB_PATH, which also fixes it for web requests)\n");
    exit(1);
}

require_once LIB_PATH . 'osclass/default-constants.php';

// Stage 2, autoload: stop here when the code is not built or the database is not installed.
if (!is_file(LIB_PATH . 'vendor/autoload.php')) {
    osc_die('Shopclass is not built yet', 'Run composer install and npm run build, or install from a release zip.', ['tone' => 'info', 'status' => 503]);
}
require_once LIB_PATH . 'vendor/autoload.php';
ErrorHandler::getInstance()->register();
if (!Preference::getInstance()->get('osclass_installed')) {
    _osc_die_not_ready(
        'Shopclass isn\'t installed yet',
        'Your settings are in place, but the database hasn\'t been set up yet. Run the installer to finish.'
    );
}

// Stage 3, helpers: the osc_* functions listed in Boot::HELPERS, required here so they load in global scope.
foreach (Boot::HELPERS as $oscHelper) {
    require_once LIB_PATH . $oscHelper;
}
unset($oscHelper);

if (!defined('OSC_CRYPT_KEY')) {
    define('OSC_CRYPT_KEY', osc_get_preference('crypt_key'));
}

osc_cache_init();

// Stage 4, request: the request values, then the visitor's session.
define('__OSC_LOADED__', true);
Params::init();
Boot::session();

if (osc_timezone()) {
    date_default_timezone_set(osc_timezone());
}

// Stage 5, extensions: assets, plugins, themes, translations, CSRF and URL rules.
Scripts::init();
Styles::init();

Plugins::init();
if (defined('OC_ADMIN') && OC_ADMIN) {
    AdminMenu::getInstance()->init();
    $functions_path = AdminThemes::getInstance()->getCurrentThemePath() . 'functions.php';
    if (file_exists($functions_path)) {
        require_once $functions_path;
    }
    // Admin only, and after the admin theme's functions.php: every function in it is
    // function_exists()-guarded, so a theme's own copy wins and core fills the gaps.
    require_once LIB_PATH . 'osclass/helpers/hAdminUi.php';
}
WebThemes::init();
Translation::init();
Csrf::init();
Rewrite::getInstance()->init();
