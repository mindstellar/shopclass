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
 * The 6.3 updater copies the new files but never clears OPcache, so a server that does not
 * re-check file times keeps running 6.3. A migration file is new on disk, so it is the one
 * piece of the new release that the old updater is sure to run fresh; it must clear the cache.
 *
 * Usage: php tests/upgrade-opcache-reset.php
 */

require_once __DIR__ . '/lib/harness.php';

$migration = dirname(__DIR__) . '/oc-includes/osclass/installer/migrations/0059_reset_php_cache.php';
pin('the cache-clearing migration ships', true, is_file($migration));

// OPcache only reports a pending reset from inside the process that asked for it.
$child = <<<'PHP'
define('ABS_PATH', $argv[1] . '/');
require ABS_PATH . 'oc-includes/vendor/autoload.php';
$status = static fn () => (bool) (opcache_get_status(false)['restart_pending'] ?? false);
if (!function_exists('opcache_get_status') || opcache_get_status(false) === false) {
    echo json_encode(['opcache' => false]);
    exit;
}
$before = $status();
$conn   = (new ReflectionClass(mindstellar\database\Connection::class))->newInstanceWithoutConstructor();
(require $argv[2])->up($conn);
echo json_encode(['opcache' => true, 'before' => $before, 'after' => $status()]);
PHP;

$cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable=1 -d opcache.enable_cli=1 -r ' . escapeshellarg($child)
    . ' ' . escapeshellarg(dirname(__DIR__)) . ' ' . escapeshellarg($migration);
$out = json_decode((string) shell_exec($cmd), true);

pin('OPcache is available to the test', true, $out['opcache'] ?? null);
pin('no reset is pending before the migration', false, $out['before'] ?? null);
pin('the migration asks OPcache to reset', true, $out['after'] ?? null);

exit(harness_result());
