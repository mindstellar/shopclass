<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Shared system page -- the single calm, on-brand screen behind every full-page
 * system message: fatal errors, "database unavailable", "not installed",
 * "not configured", and maintenance mode.
 *
 * Now an adapter onto gui/page.php's card layout. The path and the $oscSys keys
 * stay exactly as they were: osc_die() and OsclassErrors require this file
 * directly, and one of them runs when the database is unreachable.
 *
 * The caller provides $oscSys:
 *   [
 *     'title'    => string,   // browser tab title
 *     'heading'  => string,   // short, plain-language headline
 *     'body'     => string,   // what happened / what to do
 *     'bodyHtml' => bool,     // echo body as trusted HTML instead of escaping
 *     'tone'     => string,   // 'info' | 'warning' | 'danger' | 'success'
 *     'ref'      => ?string,  // reference id the admin can grep for in logs
 *     'actions'  => ?array,   // [ ['label'=>, 'href'=>, 'primary'=>bool], ... ]
 *     'detail'   => ?array,   // ['type','message','file','line','trace'] (debug)
 *   ]
 */

$oscSys = isset($oscSys) && is_array($oscSys) ? $oscSys : array();

$oscPage = array(
    'layout'    => 'card',
    'title'     => $oscSys['title'] ?? 'Shopclass',
    'heading'   => $oscSys['heading'] ?? 'Something went wrong',
    'body'      => $oscSys['body'] ?? '',
    'bodyHtml'  => !empty($oscSys['bodyHtml']),
    'tone'      => $oscSys['tone'] ?? 'danger',
    'ref'       => $oscSys['ref'] ?? null,
    'actions'   => (isset($oscSys['actions']) && is_array($oscSys['actions'])) ? $oscSys['actions'] : array(),
    'detail'    => !empty($oscSys['detail']) ? $oscSys['detail'] : null,
    'brandName' => (isset($oscSys['brandName']) && $oscSys['brandName'] !== '') ? $oscSys['brandName'] : null,
    'brandLogo' => (isset($oscSys['brandLogo']) && $oscSys['brandLogo'] !== '') ? $oscSys['brandLogo'] : null,
);

require __DIR__ . '/page.php';
