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
 * Page settings → Hide the page title: osc_static_page_title_hidden() reads the page's meta, so a
 * theme can hide the title on screen and keep it for screen readers.
 *
 * DB-free.  Usage:  php tests/page-title-hidden.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPage.php';

$view = View::getInstance();
foreach (array(
    'no meta: the title shows'                => array(array('template' => 'core.page_builder'), false),
    'hide_title 1: the title is hidden'       => array(array('template' => 'core.page_builder', 'hide_title' => '1'), true),
    'any other value: the title shows'        => array(array('hide_title' => '0'), false),
) as $label => [$meta, $hidden]) {
    $view->_exportVariableToView('page_meta', $meta);
    pin($label, $hidden, osc_static_page_title_hidden());
}

exit(harness_result());
