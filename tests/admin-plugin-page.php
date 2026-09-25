<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins osc_admin_plugin_page(): a plugin declares a route screen's header once, and the
 * plugin view reads it back for that route only.
 * Usage:  php tests/admin-plugin-page.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/lib/harness.php';

harness_section('declaring a plugin screen\'s header');

pin('a route that declared nothing reads empty', array(), osc_admin_plugin_page('acme-records'));
$opts = array('title' => 'Records', 'help' => 'What it is for.', 'actions' => array(array('icon' => 'bi-plus-circle-fill', 'url' => '#', 'title' => 'Add')));
osc_admin_plugin_page('acme-records', $opts);
pin('a declared route reads back what it declared', $opts, osc_admin_plugin_page('acme-records'));
pin('and another route is not touched', array(), osc_admin_plugin_page('acme-other'));

$view = file_get_contents(ABS_PATH . 'oc-admin/themes/modern/plugins/view.php');
check('the plugin view reads it for the current route', strpos($view, "osc_admin_plugin_page(Params::getParamString('route'))") !== false);

exit(harness_result());
