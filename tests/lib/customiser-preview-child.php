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
 * One public request for tests/widgets-customiser.php, in its own process because OC_ADMIN
 * is a constant. Prints what the page rendered and the preview helpers answered, as JSON.
 *
 * Usage:  php tests/lib/customiser-preview-child.php <scratch db> '<json: get, adminId, api>'
 */

ob_start();
define('OC_ADMIN', false);
require_once __DIR__ . '/scratchdb.php';

$admin = scratchdb_bootstrap((string) $argv[1]);
$admin->select_db((string) $argv[1]);
$args = json_decode((string) $argv[2], true);

require_once __DIR__ . '/customiser-kit.php';
customiser_kit_declare();

$GLOBALS['adminId'] = (int) ($args['adminId'] ?? 0);
$GLOBALS['api']     = !empty($args['api']);
$_GET               = (array) ($args['get'] ?? array());
$_POST              = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
Params::init();

osc_mark_response_cacheable();
osc_customiser_preview_start();

ob_start();
osc_show_widgets('home');
$page = (string) ob_get_clean();

osc_add_theme_support('head', array_fill_keys(array('charset', 'viewport', 'title', 'description', 'keywords', 'canonical', 'pagination', 'feed', 'api'), false));
ob_start();
osc_head();
$head = (string) ob_get_clean();

// The admin session alone makes a response private; this is the preview's own answer.
$GLOBALS['adminId'] = 0;
$cacheable = osc_response_is_cacheable();

ob_end_clean();
echo json_encode(array(
    'page'      => $page,
    'head'      => trim($head),
    'preview'   => osc_customiser_preview(),
    'brand'     => osc_customiser_draft_value('aisle', 'brand'),
    'other'     => osc_customiser_draft_value('other', 'brand'),
    'headers'   => osc_customiser_preview_headers(),
    'cacheable' => $cacheable,
));
