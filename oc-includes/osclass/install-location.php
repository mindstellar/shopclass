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

error_reporting(E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_PARSE);

define('ABS_PATH', dirname(dirname(__DIR__)) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

// Resolve the database configuration the same way the app does: from config.php
// when present, otherwise from the environment. Requiring config.php directly
// here broke an environment-only install (no config.php / OSC_IGNORE_CONFIG_FILE)
// — this AJAX endpoint would connect to the wrong host and fail with a 503.
require_once LIB_PATH . 'osclass/config-loader.php';
require_once LIB_PATH . 'vendor/autoload.php';

require_once LIB_PATH . 'osclass/helpers/hDatabaseInfo.php';
require_once LIB_PATH . 'osclass/helpers/hDefines.php';
require_once LIB_PATH . 'osclass/helpers/hErrors.php';
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

Params::init();
Session::newInstance()->session_start();

if (is_osclass_installed()) {
    die();
}

header('Content-Type: application/json; charset=utf-8');

// State-changing request: require the installer nonce (osc_csrf_check() cannot
// work yet — no preferences exist).
if (!install_nonce_check()) {
    echo json_encode(array(
        'status' => false,
        'error'  => __('Your session expired. Reload the page and start again.'),
    ));
    exit;
}

// Re-validate on the server. The browser validates too, but never trust it.
$adminUser = (string)Params::getParam('s_name');
$email     = (string)Params::getParam('email');
if ($adminUser !== '' && !preg_match('/^[A-Za-z0-9]+$/', $adminUser)) {
    echo json_encode(array(
        'status' => false,
        'error'  => __('The admin username may only contain letters and numbers.'),
    ));
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(array(
        'status' => false,
        'error'  => __('Enter a valid contact email address.'),
    ));
    exit;
}

$json_message           = array();
$json_message['status'] = true;

try {
    $result = basic_info();
} catch (\Throwable $e) {
    error_log('Shopclass install: creating the admin account failed: ' . $e->getMessage());
    echo json_encode(array(
        'status' => false,
        'error'  => __('The admin account could not be created. Nothing was saved — please try again.'),
    ));
    exit;
}

$json_message['email_status'] = $result['email_status'];
$json_message['password']     = $result['s_password'];

if (Params::getParam('skip-location-input') !== 'skip' && Params::getParam('location-json')) {
    $msg                    = osc_install_json_locations(Params::getParam('location-json'));
    $json_message['status'] = $msg;
}

echo json_encode($json_message);
