<?php
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

use mindstellar\Csrf;
use mindstellar\logger\OsclassErrors;

if (!defined('ABS_PATH')) {
    define('ABS_PATH', __DIR__ . '/');
}

define('LIB_PATH', ABS_PATH . 'oc-includes/');

require_once LIB_PATH . 'osclass/helpers/hErrors.php';
if (!file_exists(ABS_PATH . 'config.php')) {
    $title   = 'Osclass &raquo; Error';
    $message =
        'There doesn\'t seem to be a <code>config.php</code> file. Osclass isn\'t installed. '
        . '<a href="https://github.com/mindstellar/Osclass/discussions">Need more help?</a></p>';
    $message .= '<p><a class="btn btn-primary" href="' . osc_get_absolute_url()
        . 'oc-includes/osclass/install.php">'
        . 'Install</a></p>';
    osc_die($title, $message);
}

// load osclass configuration
require_once ABS_PATH . 'config.php';

// load default constants
require_once LIB_PATH . 'osclass/default-constants.php';

//Load Autoloader
require_once LIB_PATH . 'vendor/autoload.php';
//Register error handler
OsclassErrors::newInstance()->register();
require_once LIB_PATH . 'osclass/helpers/hDatabaseInfo.php';
require_once LIB_PATH . 'osclass/helpers/hPreference.php';
// check if Osclass is installed
if (!Preference::newInstance()->get('osclass_installed')) {
    $title   = 'Osclass &raquo; Error';
    $message =
        '<code>config.php</code> file is present but Osclass isn\'t installed. '
        .'Are you sure you want to install Osclass?'
        . '<p><a class="button" href="' . osc_get_absolute_url()
        . 'oc-includes/osclass/install.php">Install</a></p>';
    osc_die($title, $message);
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
require_once LIB_PATH . 'osclass/emails.php';
require_once LIB_PATH . 'osclass/alerts.php';
require_once LIB_PATH . 'osclass/functions.php';
require_once LIB_PATH . 'osclass/helpers/hAdminMenu.php';
require_once LIB_PATH . 'osclass/helpers/hCache.php';
require_once LIB_PATH . 'osclass/compatibility.php';


if (!defined('OSC_CRYPT_KEY')) {
    define('OSC_CRYPT_KEY', osc_get_preference('crypt_key'));
}

osc_cache_init();

define('__OSC_LOADED__', true);
Params::init();
Session::newInstance()->session_resume();

if (osc_timezone()) {
    date_default_timezone_set(osc_timezone());
}


Scripts::init();
Styles::init();

// register scripts
osc_register_script('jquery', osc_assets_url('jquery/jquery.min.js'));
osc_register_script('jquery-migrate', osc_assets_url('jquery-migrate/jquery-migrate.min.js'), 'jquery');
osc_register_script('jquery-ui', osc_assets_url('jquery-ui/jquery-ui.min.js'), 'jquery');

//osc_register_script('jquery-json', osc_assets_url('js/jquery.json.js'), 'jquery');
//Not used in osclass core, removed.
//osc_register_script('fancybox', osc_assets_url('js/fancybox/jquery.fancybox.pack.js'), array('jquery'));

osc_register_script('jquery-treeview', osc_assets_url('jquery-treeview/jquery.treeview.js'), 'jquery');
osc_register_script('jquery-nested', osc_assets_url('jquery-ui-nested/jquery-ui-nested.js'), 'jquery-ui');
osc_register_script('jquery-validate', osc_assets_url('jquery-validation/jquery.validate.min.js'), 'jquery');
osc_register_script('jquery-validate-additional', osc_assets_url('jquery-validation/additional-methods.min.js'), 'jquery-validate');

osc_register_script('tiny_mce', osc_assets_url('tinymce/tinymce.min.js'));

// Shared vanilla UI helpers (oscAutocomplete, …) — no jQuery. Used by the admin
// (as a dependency of ui-osc.js) and by the public item form via osc_ui_common_header().
osc_register_script('osc-ui-common', osc_asset_url_versioned(osc_assets_url('osclass/ui-common.js')));
osc_register_style('osc-ui-common', osc_asset_url_versioned(osc_assets_url('osclass/ui-common.css')));

//Legacy js libraries
osc_register_script('tabber', osc_assets_url('osclass-legacy/js/tabber-minimized.js'), 'jquery');
osc_register_script('colorpicker', osc_assets_url('osclass-legacy/js/colorpicker/js/colorpicker.js'));
osc_register_script('php-date', osc_assets_url('osclass-legacy/js/date.js'));
osc_register_script('jquery-fineuploader', osc_assets_url('osclass-legacy/js/fineuploader/jquery.fineuploader.min.js'), 'jquery');

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
