<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pre-install bootstrap for the `install` CLI verb.
 *
 * The normal CLI entry (oc-cli.php -> oc-load.php) osc_die()s before the app is
 * configured/installed, so the install verb loads this installer-style bootstrap
 * instead: the CLI twin of install.php, minus the web/GUI pieces. It tolerates a
 * not-yet-configured, not-yet-installed state and resolves DB settings and
 * WEB_PATH from the environment via config-loader.php.
 */

if (defined('ABS_PATH')) {
    return;
}

// The installer's reporting level: session_start() and similar CLI setup emit
// notices that are noise here, while a genuine E_ERROR still surfaces.
error_reporting(E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_PARSE);

define('ABS_PATH', dirname(__DIR__, 2) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');
define('CONTENT_PATH', ABS_PATH . 'oc-content/');
define('TRANSLATIONS_PATH', CONTENT_PATH . 'languages/');
define('OSC_INSTALLING', 1);

require_once LIB_PATH . 'vendor/autoload.php';
mindstellar\logger\OsclassErrors::newInstance()->register();

require_once LIB_PATH . 'osclass/helpers/hErrors.php';
// Resolve DB_*, WEB_PATH and OSC_CONFIG_FROM_ENV from the environment (or an
// alternate config file) before anything needs a connection.
require_once LIB_PATH . 'osclass/config-loader.php';

require_once LIB_PATH . 'osclass/helpers/hDatabaseInfo.php';
require_once LIB_PATH . 'osclass/helpers/hDatabase.php';
if (extension_loaded('mysqli')) {
    require_once LIB_PATH . 'osclass/helpers/hPreference.php';
}
require_once LIB_PATH . 'osclass/helpers/hDefines.php';
require_once LIB_PATH . 'osclass/helpers/hLocale.php';
require_once LIB_PATH . 'osclass/helpers/hSearch.php';
require_once LIB_PATH . 'osclass/helpers/hUtils.php';
require_once LIB_PATH . 'osclass/helpers/hTranslations.php';
require_once LIB_PATH . 'osclass/helpers/hSanitize.php';
require_once LIB_PATH . 'osclass/helpers/hSecurity.php';
// Path constants before hPlugins/hCache: registering a hook resolves
// osc_plugins_path(), which reads PLUGINS_PATH.
require_once LIB_PATH . 'osclass/default-constants.php';
require_once LIB_PATH . 'osclass/helpers/hPlugins.php';
require_once LIB_PATH . 'osclass/helpers/hCache.php';
require_once LIB_PATH . 'osclass/helpers/hTheme.php';
require_once LIB_PATH . 'osclass/helpers/hBilling.php';
require_once LIB_PATH . 'osclass/install-functions.php';
require_once LIB_PATH . 'osclass/utils.php';
require_once LIB_PATH . 'osclass/locales.php';

Params::init();
// A lazy session backs osc_current_admin_locale() and the installer's
// sample-content warning flag; harmless under CLI with reporting limited above.
Session::newInstance()->session_start();
