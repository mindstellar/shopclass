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
define('CONTENT_PATH', ABS_PATH . 'oc-content/');
define('TRANSLATIONS_PATH', CONTENT_PATH . 'languages/');
define('OSC_INSTALLING', 1);
require_once LIB_PATH . 'vendor/autoload.php';
mindstellar\logger\OsclassErrors::newInstance()->register();
if (extension_loaded('mysqli')) {
    require_once LIB_PATH . 'osclass/helpers/hPreference.php';
}
require_once LIB_PATH . 'osclass/helpers/hCache.php';
require_once LIB_PATH . 'osclass/helpers/hDatabaseInfo.php';
require_once LIB_PATH . 'osclass/helpers/hDefines.php';
require_once LIB_PATH . 'osclass/helpers/hErrors.php';
require_once LIB_PATH . 'osclass/helpers/hLocale.php';
require_once LIB_PATH . 'osclass/helpers/hSearch.php';
require_once LIB_PATH . 'osclass/helpers/hPlugins.php';
require_once LIB_PATH . 'osclass/helpers/hUtils.php';
require_once LIB_PATH . 'osclass/helpers/hTranslations.php';
require_once LIB_PATH . 'osclass/helpers/hSanitize.php';
require_once LIB_PATH . 'osclass/install-functions.php';
require_once LIB_PATH . 'osclass/utils.php';
require_once LIB_PATH . 'osclass/locales.php';
require_once LIB_PATH . 'osclass/default-constants.php';
define('WEB_PATH', osc_get_absolute_url());
Params::init();
Session::newInstance()->session_start();

$step = (int)Params::getParam('step');
if ($step < 1) {
    $step = 1;
}

$locales = osc_listLocales();
$jsonLocales = osc_file_get_contents(osc_get_locations_json_url());
$jsonLocales = json_decode($jsonLocales, true);
$install_locale = Params::getParam('install_locale');

if (Params::getParam('install_locale') && !(strlen($install_locale) > 5)) {
    Session::newInstance()->_set('userLocale', Params::getParam('install_locale'));
    Session::newInstance()->_set('adminLocale', Params::getParam('install_locale'));
}

if (
    Session::newInstance()->_get('adminLocale')
    && array_key_exists(Session::newInstance()->_get('adminLocale'), $locales)
) {
    $current_locale = Session::newInstance()->_get('adminLocale');
} elseif (isset($locales['en_US'])) {
    $current_locale = 'en_US';
} elseif (key($locales)) {
    $current_locale = key($locales);
} else {
    $current_locale = 'en_US';
}
Session::newInstance()->_set('userLocale', $current_locale);
Session::newInstance()->_set('adminLocale', $current_locale);

Translation::newInstance(true);

if (is_osclass_installed()) {
    $message =
        __("Looks like you've already installed Osclass. To reinstall please clear your old database tables first.");
    osc_die('Osclass &raquo; Error', $message);
}

switch ($step) {
    case 1:
        $requirements = get_requirements();
        $error        = check_requirements($requirements);
        if (
            $error === false && $install_locale && !array_key_exists($install_locale, $locales)
            && array_key_exists($install_locale, $jsonLocales)
        ) {
            $langFolder = osc_translations_path() . $install_locale;
            mkdir($langFolder, 0755, true);

            $poFiles = array(
                'theme.po',
                'core.po',
                'messages.po'
            );
            $moFiles = array(
                'theme.mo',
                'core.mo',
                'messages.mo'
            );
            foreach ($poFiles as $poFile) {
                $poFileFrom = osc_get_i18n_repository_url('src/translations/' . $install_locale . '/' . $poFile);
                $poFileTo = $langFolder . $poFile;
                $poFile = osc_file_get_contents($poFileFrom);
                if ($poFile) {
                    file_put_contents($poFileTo, $poFile);
                }
            }
            foreach ($moFiles as $moFile) {
                $moFileFrom = osc_get_i18n_repository_url('src/translations/' . $install_locale . '/' . $moFile);
                $moFileTo = $langFolder . $moFile;
                $moFile = osc_file_get_contents($moFileFrom);
                if ($moFile) {
                    file_put_contents($moFileTo, $moFile);
                }
            }
            header('Location: ' . $_SERVER['REQUEST_URI']);
            die;
        }
        break;
    case 2:
        if (Params::getParam('save_stats') == '1' || isset($_COOKIE['osclass_save_stats'])) {
            setcookie('osclass_save_stats', 1, time() + (24 * 60 * 60));
        } else {
            setcookie('osclass_save_stats', 0, time() + (24 * 60 * 60));
        }

        if (isset($_COOKIE['osclass_ping_engines'])) {
            setcookie('osclass_ping_engines', 1, time() + (24 * 60 * 60));
        }

        break;
    case 3:
        if (Params::getParam('dbname') != '') {
            $error = oc_install();
        }
        break;
    case 4:
        if (Params::getParam('result') != '') {
            $error = Params::getParam('result');
        }
        $password = Params::getParam('password', false, false);
        break;
    case 5:
        $password = Params::getParam('password', false, false);
        break;
    default:
        break;
}
include_once LIB_PATH . 'osclass/installer/gui/install.php';
