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
 * Pins AdminIpAllowlist, the OSC_ADMIN_IP_ALLOW matcher: exact addresses and CIDR ranges in
 * IPv4 and IPv6, an IPv4 address written as IPv6, loopback always let in, an empty list
 * meaning off, and bad entries left out. Also pins AddressGuard::inRange(), which it uses.
 *
 * DB-free. Usage:  php tests/admin-ip-allowlist.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\security\AddressGuard;
use mindstellar\security\AdminIpAllowlist;

$log = (string) tempnam(sys_get_temp_dir(), 'oscallow_');
ini_set('error_log', $log);
register_shutdown_function(static function () use ($log): void {
    @unlink($log);
});

$list = new AdminIpAllowlist(AdminIpAllowlist::parse(
    '203.0.113.7, 10.20.0.0/16 2001:db8::5,2001:db8:aa00::/40 256.1.1.1, 10.0.0.0/33, admin.example, 1.2.3.4/x'
));
$logged = (string) file_get_contents($log);

harness_section('IPv4');

check('an exact address is let in', $list->allows('203.0.113.7'));
check('...its neighbour is not', !$list->allows('203.0.113.8'));
check('an address inside a range is let in', $list->allows('10.20.255.1'));
check('...one just outside it is not', !$list->allows('10.21.0.1'));

harness_section('IPv6');

check('an exact address is let in', $list->allows('2001:db8::5'));
check('...in another spelling too', $list->allows('2001:0db8:0000::0005'));
check('...its neighbour is not', !$list->allows('2001:db8::6'));
check('an address inside a range is let in', $list->allows('2001:db8:aaff:1::1'));
check('...one just outside it is not', !$list->allows('2001:db8:ab00::1'));

harness_section('IPv4 written as IPv6');

check('::ffff: plus a listed address is let in', $list->allows('::ffff:203.0.113.7'));
check('...and one inside a range', $list->allows('::ffff:10.20.1.1'));
check('...an unlisted one is not', !$list->allows('::ffff:198.51.100.1'));

harness_section('loopback');

check('127.0.0.1 is always let in', $list->allows('127.0.0.1'));
check('...and the rest of 127.0.0.0/8', $list->allows('127.9.9.9'));
check('::1 is always let in', $list->allows('::1'));
check('...even with no good entry at all', (new AdminIpAllowlist(AdminIpAllowlist::parse('nonsense')))->allows('127.0.0.1'));
check('a list of only bad entries lets nobody else in', !(new AdminIpAllowlist(AdminIpAllowlist::parse('nonsense')))->allows('203.0.113.7'));

harness_section('off and bad entries');

check('no constant means off', AdminIpAllowlist::fromConfig() === null);
pin('count() leaves loopback out', 4, $list->count());
pin('bad entries are left out', array('203.0.113.7/32', '10.20.0.0/16', '2001:db8::5/128', '2001:db8:aa00::/40'), AdminIpAllowlist::parse(
    '203.0.113.7, 10.20.0.0/16 2001:db8::5,2001:db8:aa00::/40 256.1.1.1, 10.0.0.0/33, admin.example, 1.2.3.4/x'
));
check('256.1.1.1 is named in the log', str_contains($logged, '256.1.1.1'));
check('...and 10.0.0.0/33', str_contains($logged, '10.0.0.0/33'));
pin('...in one line', 1, substr_count(trim($logged), "\n") + 1);
check('a range wider than its family is left out', AdminIpAllowlist::parse('::/129, 1.2.3.4/-1') === array());
check('an empty list parses to nothing', AdminIpAllowlist::parse(' , ') === array());
check('a value that is not an address is refused', !$list->allows('') && !$list->allows('not-an-ip'));
$blank = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'require ' . var_export(dirname(__DIR__) . '/oc-includes/vendor/autoload.php', true) . ';'
    . 'define("OSC_ADMIN_IP_ALLOW", " \n ");'
    . 'echo \\mindstellar\\security\\AdminIpAllowlist::fromConfig() === null ? "off" : "on";'
));
pin('a constant of only spaces means off', 'off', $blank);
define('OSC_ADMIN_IP_ALLOW', ' 192.0.2.1 ');
check('the constant turns it on', AdminIpAllowlist::fromConfig() !== null && AdminIpAllowlist::fromConfig()->allows('192.0.2.1'));
check('...for the listed address only', !AdminIpAllowlist::fromConfig()->allows('192.0.2.2'));

harness_section('AddressGuard::inRange()');

check('an IPv4 address in its /24', AddressGuard::inRange('192.168.1.77', '192.168.1.0/24'));
check('...not in the next one', !AddressGuard::inRange('192.168.2.77', '192.168.1.0/24'));
check('a range not on a byte edge', AddressGuard::inRange('172.31.0.1', '172.16.0.0/12') && !AddressGuard::inRange('172.32.0.1', '172.16.0.0/12'));
check('/0 holds every address of its family', AddressGuard::inRange('8.8.8.8', '0.0.0.0/0'));
check('an IPv4 address is never in an IPv6 range', !AddressGuard::inRange('10.0.0.1', '::/0'));
check('a value that is not an address is in no range', !AddressGuard::inRange('nope', '0.0.0.0/0'));

exit(harness_result());

/* file end: ./tests/admin-ip-allowlist.php */
