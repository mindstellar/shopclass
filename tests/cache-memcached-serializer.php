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
 * Pins the memcached driver's serializer: igbinary when the extension was built with it, PHP's
 * own otherwise. No server is needed, since the option is set before any connection.
 *
 * DB-free.  Usage:  php tests/cache-memcached-serializer.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

harness_section('memcached serializer');
if (!class_exists('Memcached')) {
    echo "  (memcached is not loaded: nothing to check)\n";
    check('the check runs only with the memcached extension', true);
    exit(harness_result());
}

$GLOBALS['_cache_config'] = [['default_host' => '127.0.0.1', 'default_port' => 1, 'default_weight' => 1]];
$driver = new \mindstellar\cache\MemcachedCache();
$client = (new ReflectionProperty($driver, 'memcached'));
if (PHP_VERSION_ID < 80100) {
    $client->setAccessible(true);
}
pin(
    'it uses igbinary exactly when the extension supports it',
    \Memcached::HAVE_IGBINARY ? \Memcached::SERIALIZER_IGBINARY : \Memcached::SERIALIZER_PHP,
    $client->getValue($driver)->getOption(\Memcached::OPT_SERIALIZER)
);

exit(harness_result());

/* file end: ./tests/cache-memcached-serializer.php */
