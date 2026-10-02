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
 * which means REMOTE_ADDR is a proxy's address, not the visitor's.
 * Usage:  php tests/proxy-ip-mismatch.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\security\ProxyIpMismatch;

harness_section('no proxy headers');

pin('nothing to compare against', null, ProxyIpMismatch::detect('203.0.113.9', array()));

harness_section('single-value headers');

pin('CF-Connecting-IP equal to REMOTE_ADDR: no warning', null, ProxyIpMismatch::detect(
    '203.0.113.9',
    array('HTTP_CF_CONNECTING_IP' => '203.0.113.9')
));

pin('CF-Connecting-IP different from REMOTE_ADDR: warns', array('header' => 'CF-Connecting-IP', 'proxy' => '198.51.100.1'), ProxyIpMismatch::detect(
    '198.51.100.1',
    array('HTTP_CF_CONNECTING_IP' => '203.0.113.9')
));

pin('a private REMOTE_ADDR behind X-Real-IP still warns', array('header' => 'X-Real-IP', 'proxy' => '10.0.0.5'), ProxyIpMismatch::detect(
    '10.0.0.5',
    array('HTTP_X_REAL_IP' => '203.0.113.9')
));

pin('a garbage header value is ignored, not treated as a mismatch', null, ProxyIpMismatch::detect(
    '203.0.113.9',
    array('HTTP_CF_CONNECTING_IP' => 'not-an-ip')
));

harness_section('list headers');

pin('X-Forwarded-For containing REMOTE_ADDR: no warning', null, ProxyIpMismatch::detect(
    '203.0.113.9',
    array('HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.1')
));

pin('X-Forwarded-For not containing REMOTE_ADDR: warns', array('header' => 'X-Forwarded-For', 'proxy' => '10.0.0.1'), ProxyIpMismatch::detect(
    '10.0.0.1',
    array('HTTP_X_FORWARDED_FOR' => '203.0.113.9, 198.51.100.2')
));

harness_section('IPv6, brackets and ports');

pin('bracketed IPv6 with a port matches the same address in REMOTE_ADDR', null, ProxyIpMismatch::detect(
    '2001:db8::1',
    array('HTTP_CF_CONNECTING_IP' => '[2001:DB8::1]:8443')
));

pin('bracketed IPv6 with a port, different address: warns', array('header' => 'CF-Connecting-IP', 'proxy' => '2001:db8::2'), ProxyIpMismatch::detect(
    '2001:db8::2',
    array('HTTP_CF_CONNECTING_IP' => '[2001:db8::1]:8443')
));

pin('Forwarded: for="[2001:db8::1]:4711" is parsed and matched', null, ProxyIpMismatch::detect(
    '2001:db8::1',
    array('HTTP_FORWARDED' => 'for="[2001:db8::1]:4711";proto=https')
));

pin('Forwarded: for="[2001:db8::1]:4711" not matching REMOTE_ADDR: warns', array('header' => 'Forwarded', 'proxy' => '198.51.100.17'), ProxyIpMismatch::detect(
    '198.51.100.17',
    array('HTTP_FORWARDED' => 'for="[2001:db8::1]:4711";proto=https')
));

exit(harness_result());
