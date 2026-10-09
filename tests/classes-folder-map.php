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
 * Every folder in oc-includes/osclass/classes/ has one line in the folder map of
 * docs/site/developers/architecture.md, and the map names no folder that is gone.
 * DB-free.  Usage: php tests/classes-folder-map.php
 */

require_once __DIR__ . '/lib/harness.php';

$root    = dirname(__DIR__);
$folders = array_map('basename', glob($root . '/oc-includes/osclass/classes/*', GLOB_ONLYDIR));
sort($folders);

$doc = (string) file_get_contents($root . '/docs/site/developers/architecture.md');
$map = substr($doc, (int) strpos($doc, '## Folders'));
$map = substr($map, 0, (int) strpos($map, "\n## ", 1) ?: strlen($map));
preg_match_all('/^\| `([a-z0-9_]+)` \|/m', $map, $m);
$listed = $m[1];
sort($listed);

harness_section('the folder map in architecture.md');
pin('every folder has a line', array(), array_values(array_diff($folders, $listed)));
pin('and every line has a folder', array(), array_values(array_diff($listed, $folders)));
pin('one line each', count(array_unique($listed)), count($listed));
check('there is no second base folder', !is_dir($root . '/oc-includes/osclass/classes/controller/base') && !is_dir($root . '/oc-includes/osclass/classes/form/base'));

exit(harness_result());
