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

error_reporting(E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_PARSE);

define('ABS_PATH', dirname(dirname(__DIR__)) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

// Resolve the database configuration the same way the app does: from config.php
// when present, otherwise from the environment. Requiring config.php directly
// here broke an environment-only install (no config.php / OSC_IGNORE_CONFIG_FILE)
// — this AJAX endpoint would connect to the wrong host and fail with a 503.
require_once LIB_PATH . 'osclass/helpers/hErrors.php';
require_once LIB_PATH . 'osclass/config-loader.php';
if (!is_file(LIB_PATH . 'vendor/autoload.php')) {
    osc_die('Shopclass is not built yet', 'Run composer install and npm run build, or install from a release zip.', ['tone' => 'info', 'status' => 503]);
}
require_once LIB_PATH . 'vendor/autoload.php';

require_once LIB_PATH . 'osclass/helpers/hDatabaseInfo.php';
require_once LIB_PATH . 'osclass/helpers/hDefines.php';
require_once LIB_PATH . 'osclass/helpers/hLocale.php';
require_once LIB_PATH . 'osclass/helpers/hLocation.php';
require_once LIB_PATH . 'osclass/helpers/hPreference.php';
require_once LIB_PATH . 'osclass/helpers/hPlugins.php';
require_once LIB_PATH . 'osclass/helpers/hTranslations.php';
require_once LIB_PATH . 'osclass/helpers/hUtils.php';
require_once LIB_PATH . 'osclass/compatibility.php';
require_once LIB_PATH . 'osclass/default-constants.php';
require_once LIB_PATH . 'osclass/formatting.php';
require_once LIB_PATH . 'osclass/install-functions.php';
require_once LIB_PATH . 'osclass/utils.php';
require_once LIB_PATH . 'osclass/helpers/hSecurity.php';
require_once LIB_PATH . 'osclass/helpers/hDatabase.php';
require_once LIB_PATH . 'osclass/helpers/hJobs.php';

Params::init();
Session::getInstance()->session_start();

if (is_osclass_installed() || install_database_unreachable()) {
    die();
}

header('Content-Type: application/json; charset=utf-8');

// State-changing request: require the installer nonce (osc_csrf_check() cannot
// work yet — no preferences exist). The browser stops the whole run on a fatal answer.
if (!install_ajax_allowed()) {
    echo json_encode(array(
        'status' => false,
        'fatal'  => true,
        'error'  => __('Your session expired. Reload the page and start again.'),
    ));
    exit;
}

// One unit of work per request, so no request runs into a shared host's time limit.
echo json_encode(install_run_unit(Params::getParamString('action')));
