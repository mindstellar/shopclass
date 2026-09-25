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
 * Pins where HTMLPurifier caches its definitions: a folder under uploads named from
 * the database secret, shielded from direct reads, and no cache at all (without a
 * warning) when that folder cannot be written.  Usage:  php tests/purifier-cache.php
 */

use mindstellar\security\PurifierCache;

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$root = sys_get_temp_dir() . '/osc-purifier-cache-' . getmypid() . '/';
mkdir($root);
define('UPLOADS_PATH', $root);
define('DB_PASSWORD', 'secret');
define('DB_NAME', 'db');

$warnings = array();
set_error_handler(function ($no, $msg) use (&$warnings) {
    $warnings[] = $msg;

    return true;
});

/** Purify once through a config PurifierCache pointed somewhere, and return the config. */
function purify_once(): HTMLPurifier_Config
{
    $config = HTMLPurifier_Config::createDefault();
    $config->set('HTML.Allowed', 'b');
    PurifierCache::apply($config);
    (new HTMLPurifier($config))->purify('<b>x</b><i>y</i>');

    return $config;
}

harness_section('writable uploads');

$config = purify_once();
$dir    = PurifierCache::dir();
check('folder sits under uploads', is_string($dir) && strpos($dir, $root . 'purifier-') === 0);
check('folder name is not guessable from the path alone', $dir !== $root . 'purifier-');
check('the config caches there', $config->get('Cache.SerializerPath') === $dir
    && $config->get('Cache.DefinitionImpl') === 'Serializer');
check('a definition was written', count(glob($dir . '/HTML/*.ser')) === 1);
check('index.php and .htaccess shield the folder', is_file($dir . '/index.php')
    && strpos((string) file_get_contents($dir . '/.htaccess'), 'Require all denied') !== false);
check('the same secret gives the same folder', (function () use ($dir) {
    PurifierCache::reset();

    return PurifierCache::dir() === $dir;
})());

harness_section('unwritable uploads');

array_map('unlink', glob($dir . '/HTML/*'));
rmdir($dir . '/HTML');
array_map('unlink', glob($dir . '/{,.}[!.]*', GLOB_BRACE));
rmdir($dir);
chmod($root, 0555);
PurifierCache::reset();
$warnings = array();
$config   = purify_once();
check('falls back to no cache', PurifierCache::dir() === false && $config->get('Cache.DefinitionImpl') === null);
check('and raises no warning', $warnings === array(), implode(' | ', $warnings));
chmod($root, 0755);
rmdir($root);

restore_error_handler();
exit(harness_result());
