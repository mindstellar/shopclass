<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the committed vendor tree against composer.lock.
 *
 * Releases are cut with `git archive` from a tag, so what users get is whatever is
 * committed under oc-includes/vendor -- nothing installs anything on their behalf.
 * A dependency bump that updates composer.json and composer.lock without rebuilding
 * the vendor tree therefore ships the old library while every manifest claims the
 * new one, and the only symptom is that a fixed bug is still there.
 *
 * Both files compared here are committed, so this needs no network and no install:
 * installed.json is composer's own record of what is actually in the tree.
 *
 * Usage: php tests/vendor-sync.php
 */

define('ABS_PATH', __DIR__ . '/../');

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$lock      = json_decode(file_get_contents(ABS_PATH . 'composer.lock'), true);
$installed = json_decode(file_get_contents(ABS_PATH . 'oc-includes/vendor/composer/installed.json'), true);

// composer 2 nests under "packages"; composer 1 was a bare list.
$installedPackages = isset($installed['packages']) ? $installed['packages'] : $installed;

$have = array();
foreach ($installedPackages as $package) {
    if (isset($package['name'], $package['version'])) {
        $have[$package['name']] = $package['version'];
    }
}

harness_section('every locked package is present in the vendor tree at the locked version');
foreach ($lock['packages'] as $package) {
    $name = $package['name'];
    pin(
        $name . ' vendored at ' . $package['version'],
        $package['version'],
        isset($have[$name]) ? $have[$name] : '(absent from vendor)'
    );
}

harness_section('the constraint in composer.json admits what is locked');
$require = isset($lock['content-hash']) ? true : false;
pin('composer.lock carries a content hash', true, $require);

exit(harness_result());
