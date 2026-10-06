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

define('OC_ADMIN', true);

require_once dirname(__DIR__) . '/oc-load.php';

if (file_exists(ABS_PATH . '.maintenance')) {
    define('__OSC_MAINTENANCE__', true);
}

// register admin scripts. The admin frontend is jQuery-free: osc.js/ui-osc.js are vanilla,
// and nothing here loads jquery or jquery-ui.
osc_register_script('admin-osc', osc_asset_url_versioned(osc_current_admin_theme_js_url('osc.js')));
osc_register_script('admin-ui-osc', osc_asset_url_versioned(osc_current_admin_theme_js_url('ui-osc.js')), array('admin-osc', 'osc-ui-common'));
osc_register_script('admin-editor', osc_asset_url_versioned(osc_current_admin_theme_js_url('editor.js')), 'admin-ui-osc');
osc_register_script('admin-location', osc_asset_url_versioned(osc_current_admin_theme_js_url('location.min.js')), 'bootstrap5');
osc_register_script('popper', osc_asset_url_versioned(osc_assets_url('popper/popper.min.js')));
osc_register_script('bootstrap5', osc_asset_url_versioned(osc_assets_url('bootstrap/bootstrap.min.js')), 'popper');
osc_register_script('sortablejs', osc_asset_url_versioned(osc_assets_url('sortablejs/Sortable.min.js')));
osc_register_script('qrcode-generator', osc_asset_url_versioned(osc_assets_url('qrcode-generator/qrcode.js')));
osc_register_script('admin-categories', osc_asset_url_versioned(osc_current_admin_theme_js_url('categories.js')), 'sortablejs');
// enqueue scripts
osc_enqueue_script('bootstrap5');
osc_enqueue_script('admin-osc');
osc_enqueue_script('admin-ui-osc');

// register css styles
osc_register_style('admin-css', osc_asset_url_versioned(osc_current_admin_theme_styles_url('main.css')));
osc_register_style('bootstrap-icons', osc_asset_url_versioned(osc_assets_url('bootstrap-icons/bootstrap-icons.css')));

// enqueue css styles
osc_enqueue_style('admin-css');
osc_enqueue_style('bootstrap-icons');

// The ?page= routes live in mindstellar\routing\PageRoutes.
\mindstellar\routing\PageDispatcher::admin()->dispatch(Params::getParamString('page'), Params::getParamString('action'));

/* file end: ./oc-admin/index.php */
