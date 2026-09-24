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
 * Pins AddressGuard: only public http(s) addresses on their standard ports pass, every
 * resolved IP must be public, and the checked IPs come back for pinning. DNS is faked.
 * Usage:  php tests/address-guard.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\security\AddressGuard;

/** DNS answers the tests control. */
$dns = array(
    'cdn.example'       => array('93.184.216.34'),
    'inside.example'    => array('10.0.0.5'),
    'split.example'     => array('93.184.216.34', '127.0.0.1'),
    'v6.example'        => array('2606:2800:220:1:248:1893:25c8:1946'),
    'v6local.example'   => array('fd00::1'),
    'metadata.example'  => array('169.254.169.254'),
    'dual.example'      => array('2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34'),
    'multi.example'     => array('93.184.216.1', '93.184.216.2', '93.184.216.3', '93.184.216.4'),
);
$guard = new AddressGuard(static fn (string $host) => $dns[$host] ?? array());

harness_section('addresses that are fetched');

foreach (array(
    'https://cdn.example/a.jpg',
    'http://cdn.example/a.jpg',
    'https://cdn.example:443/a.jpg',
    'https://v6.example/a.jpg',
    'https://93.184.216.34/a.jpg',
) as $url) {
    pin($url, true, $guard->check($url)['ok']);
}
pin('the approved IP is handed back for pinning', '93.184.216.34', $guard->check('https://cdn.example/a.jpg')['ip']);
pin('IPv4 is preferred when a host has both', '93.184.216.34', $guard->check('https://dual.example/a.jpg')['ip']);
pin('and every checked address is handed back, IPv4 first', array('93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'), $guard->check('https://dual.example/a.jpg')['ips']);

harness_section('addresses that are not');

foreach (array(
    'file:///etc/passwd'                     => 'Only http and https addresses are fetched.',
    'gopher://cdn.example/'                  => 'Only http and https addresses are fetched.',
    'ftp://cdn.example/a.jpg'                => 'Only http and https addresses are fetched.',
    'https://cdn.example:8080/a.jpg'         => 'Only the standard port is fetched.',
    'https://user:pw@cdn.example/a.jpg'      => 'An address with a user name or password is not fetched.',
    'https://inside.example/a.jpg'           => 'The host is on a private or reserved network.',
    'https://split.example/a.jpg'            => 'The host is on a private or reserved network.',
    'https://v6local.example/a.jpg'          => 'The host is on a private or reserved network.',
    'http://metadata.example/latest'         => 'The host is on a private or reserved network.',
    'http://127.0.0.1/admin'                 => 'The host is on a private or reserved network.',
    'http://[::1]/admin'                     => 'The host is on a private or reserved network.',
    'http://[::ffff:127.0.0.1]/admin'        => 'The host is on a private or reserved network.',
    'http://100.64.0.1/a.jpg'                => 'The host is on a private or reserved network.',
    'http://0.0.0.0/a.jpg'                   => 'The host is on a private or reserved network.',
    'http://[::7f00:1]/a.jpg'                => 'The host is on a private or reserved network.',
    'http://[fec0::1]/a.jpg'                 => 'The host is on a private or reserved network.',
    'http://[64:ff9b:1::a00:1]/a.jpg'        => 'The host is on a private or reserved network.',
    'http://exa%6dple.com/a.jpg'             => 'The host name holds characters an address may not.',
    'https://nowhere.example/a.jpg'          => 'The host name does not resolve.',
    'http://2130706433/a.jpg'                => 'The host name holds characters an address may not.',
    'http://0x7f.0.0.1/a.jpg'                => 'The host name holds characters an address may not.',
    'http://127.1/a.jpg'                     => 'The host name holds characters an address may not.',
) as $url => $reason) {
    pin($url, $reason, $guard->check($url)['error'] ?? 'allowed');
}

exit(harness_result());
