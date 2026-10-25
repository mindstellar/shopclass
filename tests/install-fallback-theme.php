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
 * Pins the installer's Storefront fallback: it installs the newest catalogue release this core
 * can run, does nothing when the theme is already on disk, and fails quietly when no release fits.
 *
 * DB-free, with a fake fetcher.  Usage: php tests/install-fallback-theme.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');
define('OSCLASS_VERSION', '7.1.0');
define('CONTENT_PATH', sys_get_temp_dir() . '/fallback-theme-' . getmypid() . '/');
mkdir(CONTENT_PATH . 'themes', 0777, true);

function __($text)
{
    return $text;
}

require_once LIB_PATH . 'vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once LIB_PATH . 'osclass/install-functions.php';

$release = static fn (string $version, string $requires = '7.0.0'): array => array(
    'version' => $version, 'requires' => $requires, 'requires_php' => '8.0', 'url' => 'https://github.com/x/' . $version . '.zip', 'sha256' => str_repeat('a', 64),
);
$asked = array();
$ok    = static function (array $entry) use (&$asked): array {
    $asked[] = $entry['version'];

    return array('ok' => true, 'message' => '');
};

harness_section('from the catalogue');
check('installs when a release fits', install_fallback_theme(array($release('2.1.0'), $release('2.2.0')), $ok) === true);
check('it picks the newest', $asked === array('2.2.0'));

$asked = array();
check('a release this core cannot run is skipped', install_fallback_theme(array($release('3.0.0', '9.0.0'), $release('2.2.0')), $ok) && $asked === array('2.2.0'));

$asked = array();
check('no release fits: false, nothing fetched', install_fallback_theme(array($release('3.0.0', '9.0.0')), $ok) === false && $asked === array());
check('an empty catalogue: false', install_fallback_theme(array(), $ok) === false);
check('a failed install: false', install_fallback_theme(array($release('2.2.0')), static fn (array $e): array => array('ok' => false, 'message' => 'x')) === false);

harness_section('already on disk');
mkdir(CONTENT_PATH . 'themes/storefront');
$asked = array();
check('nothing is fetched', install_fallback_theme(array($release('2.2.0')), $ok) === true && $asked === array());

harness_section('the pinned download is gone');
$gui = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/gui/install.php');
check('the 1.2.0 URL is gone', !str_contains($gui, '1.2.0') && !str_contains($gui, 'releases/download'));
check('the finish step calls the fallback', str_contains($gui, 'install_fallback_theme()'));

rmdir(CONTENT_PATH . 'themes/storefront');
rmdir(CONTENT_PATH . 'themes');
rmdir(CONTENT_PATH);
exit(harness_result());
