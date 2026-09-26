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
 * Pins osc_request_host(): the port comes back when nginx passes the host without it, and is
 * left alone behind a proxy or on the default ports.
 *
 * Usage: php tests/request-host.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hErrors.php';

$host = static function (array $server): string {
    $_SERVER = $server;

    return osc_request_host();
};

pin('a host without its port gets it back', 'localhost:8080', $host(array('HTTP_HOST' => 'localhost', 'SERVER_PORT' => '8080')));
pin('a host that has its port keeps it', 'localhost:8080', $host(array('HTTP_HOST' => 'localhost:8080', 'SERVER_PORT' => '8080')));
pin('port 80 is not added', 'example.com', $host(array('HTTP_HOST' => 'example.com', 'SERVER_PORT' => '80')));
pin('port 443 is not added', 'example.com', $host(array('HTTP_HOST' => 'example.com', 'SERVER_PORT' => '443')));
pin('behind a proxy the internal port is not added', 'example.com', $host(array('HTTP_HOST' => 'example.com', 'SERVER_PORT' => '8080', 'HTTP_X_FORWARDED_PROTO' => 'https')));
pin('nor with only a forwarded address', 'example.com', $host(array('HTTP_HOST' => 'example.com', 'SERVER_PORT' => '8080', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1')));
pin('no host stays empty', '', $host(array('SERVER_PORT' => '8080')));

exit(harness_result());
