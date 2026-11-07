<?php

declare(strict_types=1);

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins that every text('key') string in install.js has a translated entry in the page's
 * $ins_i18n map, so no installer message stays English.
 *
 * DB-free.  Usage: php tests/install-js-text.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/install-css.php';

$js   = (string) file_get_contents(ins_root() . '/install.js');
$page = (string) file_get_contents(ins_root() . '/gui/install.php');

preg_match_all("/\\btext\\('([A-Za-z]+)'/", $js, $used);
$keys = array_unique($used[1]);
check('install.js asks for translated strings', count($keys) > 5, (string) count($keys));

preg_match('/\$ins_i18n\s*=\s*array\((.*?)\n\);/s', $page, $map);
preg_match_all("/^\\s*'([A-Za-z]+)'\\s*=>\\s*__\\(/m", $map[1] ?? '', $defined);
$missing = array_diff($keys, $defined[1]);
check('every install.js text key is in $ins_i18n', $missing === array(), implode(', ', $missing));

exit(harness_result());
