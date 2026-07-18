<?php
/*
 * This file is part of Osclass (Mindstellar).
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

require_once ABS_PATH . 'config.php';
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

Params::init();
if (is_osclass_installed()) {
    die();
}

$json_message           = array();
$json_message['status'] = true;

$result                       = basic_info();
$json_message['email_status'] = $result['email_status'];
$json_message['password']     = $result['s_password'];

if (Params::getParam('skip-location-input') !== 'skip' && Params::getParam('location-json')) {
    $msg                    = osc_install_json_locations(Params::getParam('location-json'));
    $json_message['status'] = $msg;
}

echo json_encode($json_message);
