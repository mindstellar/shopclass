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
 * Pins ProxyIpMismatch::detect(): warns when a forwarding header disagrees with REMOTE_ADDR,
/**
 * A cache server that is down must read as a miss, never as a stored false.
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$drivers = __DIR__ . '/../oc-includes/osclass/classes/cache/drivers/';
require_once $drivers . 'index.php';

harness_section('memcached with a server that does not answer');

if (!class_exists('Memcached')) {
    check('memcached extension is loaded (skipped: not installed)', true);
} else {
    require_once $drivers . 'Object_Cache_memcached.php';
    $GLOBALS['_cache_config'] = array(array('default_host' => '127.0.0.1', 'default_port' => 1, 'default_weight' => 1));
    $cache = new Object_Cache_memcached();

    $found = null;
    $value = $cache->get('osc_test:locales', $found);
    pin('a dead server is a miss', false, $found);
    pin('...and returns false', false, $value);

    $found = null;
    $cache->get('osc_test:locales', $found);
    pin('a second read is still a miss', false, $found);
    pin('a write to a dead server reports failure', false, $cache->set('osc_test:x', 'v'));
    pin('the value is still readable in this request', 'v', $cache->get('osc_test:x'));

    // After one failure the rest of the request skips the server entirely.
    $probe = new ReflectionProperty($cache, 'down');
    if (PHP_VERSION_ID < 80100) {
        $probe->setAccessible(true);
    }
    pin('the driver remembers the server is down', true, $probe->getValue($cache));
}

exit(harness_result());
