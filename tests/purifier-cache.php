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
 * Pins HTMLPurifier's definition cache: a random folder under uploads, files signed with
 * the site key, a planted or torn file never unserialized, and no cache (and no warning)
 * when the folder cannot be written.  Usage:  php tests/purifier-cache.php
 */

use mindstellar\security\PurifierCache;
use mindstellar\security\SignedDefinitionCache;

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$root = sys_get_temp_dir() . '/osc-purifier-cache-' . getmypid() . '/';
mkdir($root);
define('UPLOADS_PATH', $root);
define('OSC_CSRF_SECRET', 'test-signing-key');

$warnings = array();
set_error_handler(function ($no, $msg) use (&$warnings) {
    $warnings[] = $msg;

    return true;
});

/** A config PurifierCache has pointed at its folder. */
function cache_config(): HTMLPurifier_Config
{
    $config = HTMLPurifier_Config::createDefault();
    $config->set('HTML.Allowed', 'b');
    PurifierCache::apply($config);

    return $config;
}

/** The HTML definition as the cache reads it back, or false on a miss. */
function cached_definition(HTMLPurifier_Config $config)
{
    return (new SignedDefinitionCache('HTML'))->get($config);
}

harness_section('writable uploads');

$config = cache_config();
(new HTMLPurifier($config))->purify('<b>x</b><i>y</i>');
$dir  = PurifierCache::dir();
$file = is_string($dir) ? (glob($dir . '/HTML/*.ser')[0] ?? '') : '';
check('folder sits under uploads with a random name', is_string($dir)
    && preg_match('#^' . preg_quote($root, '#') . 'purifier-[0-9a-f]{24}$#', $dir) === 1);
check('the config uses the signed cache there', $config->get('Cache.DefinitionImpl') === 'ShopclassSigned'
    && $config->get('Cache.SerializerPath') === $dir);
check('a signed definition was written', $file !== ''
    && preg_match('/^[0-9a-f]{64}O:/', (string) file_get_contents($file)) === 1);
check('it reads back', cached_definition(cache_config()) instanceof HTMLPurifier_HTMLDefinition);
check('index.php and .htaccess shield the folder', is_file($dir . '/index.php')
    && strpos((string) file_get_contents($dir . '/.htaccess'), 'Require all denied') !== false);
PurifierCache::reset();
check('a later request finds the same folder', PurifierCache::dir() === $dir);

harness_section('files that are not ours');

$signed = (string) file_get_contents($file);
file_put_contents($file, str_repeat('0', 64) . substr($signed, 64));
check('a file with a wrong signature is a miss', cached_definition(cache_config()) === false);
check('and is deleted', !is_file($file));
file_put_contents($file, substr($signed, 0, 200));
check('a torn file is a miss', cached_definition(cache_config()) === false && !is_file($file));
(new HTMLPurifier(cache_config()))->purify('<b>x</b>');
check('the next purify writes a good one again', is_file($file) && cached_definition(cache_config()) !== false);

$other = HTMLPurifier_Config::createDefault();
$other->set('HTML.Allowed', 'b,a[href]');
PurifierCache::apply($other);
(new HTMLPurifier($other))->purify('<b>x</b>');
$otherFile = (new SignedDefinitionCache('HTML'))->generateFilePath($other);
copy($otherFile, $file);
check("another config's signed file is refused", cached_definition(cache_config()) === false);
check('so the strip config still drops <a>', (new HTMLPurifier(cache_config()))->purify('<a href="/x">y</a><i>z</i>') === 'yz');

harness_section('unwritable uploads');

$rm = function ($path) use (&$rm) {
    foreach (glob($path . '/{,.}[!.]*', GLOB_BRACE) as $p) {
        is_dir($p) ? $rm($p) : unlink($p);
    }
    rmdir($path);
};
$rm($dir);
chmod($root, 0555);
PurifierCache::reset();
$warnings = array();
$config   = cache_config();
(new HTMLPurifier($config))->purify('<b>x</b>');
check('falls back to no cache', PurifierCache::dir() === false && $config->get('Cache.DefinitionImpl') === null);
check('and raises no warning', $warnings === array(), implode(' | ', $warnings));
chmod($root, 0755);
rmdir($root);

restore_error_handler();
exit(harness_result());
