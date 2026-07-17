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

define('OC_ADMIN', true);

require_once dirname(__DIR__) . '/oc-load.php';

if (file_exists(ABS_PATH . '.maintenance')) {
    define('__OSC_MAINTENANCE__', true);
}

// register admin scripts. The admin frontend is jQuery-free — osc.js/ui-osc.js
// are vanilla, so they no longer depend on (and no longer pull in) jquery or
// jquery-ui. Those scripts remain registered (see oc-load.php); a legacy plugin
// that still needs them must enqueue them itself.
osc_register_script('admin-osc', osc_asset_url_versioned(osc_current_admin_theme_js_url('osc.js')));
osc_register_script('admin-ui-osc', osc_asset_url_versioned(osc_current_admin_theme_js_url('ui-osc.js')), 'admin-osc');
osc_register_script('admin-location', osc_asset_url_versioned(osc_current_admin_theme_js_url('location.min.js')), 'bootstrap5');
osc_register_script('popper', osc_asset_url_versioned(osc_assets_url('popper/popper.min.js')));
osc_register_script('bootstrap5', osc_asset_url_versioned(osc_assets_url('bootstrap/bootstrap.min.js')), 'popper');
osc_register_script('sortablejs', osc_asset_url_versioned(osc_assets_url('sortablejs/Sortable.min.js')));
osc_register_script('admin-categories', osc_asset_url_versioned(osc_current_admin_theme_js_url('categories.js')), 'sortablejs');
// enqueue scripts
osc_enqueue_script('bootstrap5');
osc_enqueue_script('admin-osc');
osc_enqueue_script('admin-ui-osc');

// register css styles
osc_register_style('jquery-ui', osc_asset_url_versioned(osc_assets_url('jquery-ui/jquery-ui.min.css')));
osc_register_style('admin-css', osc_asset_url_versioned(osc_current_admin_theme_styles_url('main.css')));
osc_register_style('bootstrap-icons', osc_asset_url_versioned(osc_assets_url('bootstrap-icons/bootstrap-icons.css')));

// enqueue css styles. jquery-ui CSS is not enqueued — no jQuery-UI widgets are
// used; it stays registered for any plugin that still needs it.
osc_enqueue_style('admin-css');
osc_enqueue_style('bootstrap-icons');

switch (Params::getParam('page')) {
    case ('items'):
        $do = new CAdminItems();
        $do->doModel();
        break;
    case ('comments'):
        $do = new CAdminItemComments();
        $do->doModel();
        break;
    case ('media'):
        $do = new CAdminMedia();
        $do->doModel();
        break;
    case ('login'):
        $do = new CAdminLogin();
        $do->doModel();
        break;
    case ('categories'):
        $do = new CAdminCategories();
        $do->doModel();
        break;
    case ('emails'):
        $do = new CAdminEmails();
        $do->doModel();
        break;
    case ('pages'):
        $do = new CAdminPages();
        $do->doModel();
        break;
    case ('settings'):
        $do = new CAdminSettings();
        $do->doModel();
        break;
    case ('plugins'):
        $do = new CAdminPlugins();
        $do->doModel();
        break;
    case ('languages'):
        $do = new CAdminLanguages();
        $do->doModel();
        break;
    case ('admins'):
        $do = new CAdminAdmins();
        $do->doModel();
        break;
    case ('users'):
        $do = new CAdminUsers();
        $do->doModel();
        break;
    case ('ajax'):
        $do = new CAdminAjax();
        $do->doModel();
        break;
    case ('appearance'):
        $do = new CAdminAppearance();
        $do->doModel();
        break;
    case ('tools'):
        $do = new CAdminTools();
        $do->doModel();
        break;
    case ('stats'):
        $do = new CAdminStats();
        $do->doModel();
        break;
    case ('cfields'):
        $do = new CAdminCFields();
        $do->doModel();
        break;
    case ('upgrade'):
        $do = new CAdminUpgrade();
        $do->doModel();
        break;
    default:            //login of oc-admin
        $do = new CAdminMain();
        $do->doModel();
}

/* file end: ./oc-admin/index.php */
