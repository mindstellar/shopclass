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
 * Pins that IP ban rules also cover IPv6 visitors: a rule can be a single address (either
 * family, any notation) or a CIDR range. The dotted-block rules keep working as before.
 *
 * DB-free.  Usage: php tests/ban-rules-ipv6.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';

$rules = array();
foreach (array('2001:DB8:0::5', '2001:db8:aa::/48', '203.0.113.0/24', '10.0.*.1-5', '2001:db8::/129', '198.51.100.0/33', 'not-an-ip/8') as $ip) {
    $rules[] = array('s_ip' => $ip, 's_email' => '');
}

harness_section('IPv6 visitors');

pin('a single IPv6 rule bans that address, whatever its notation', true, osc_is_ip_banned('2001:db8::5', $rules));
pin('an IPv6 range bans an address inside it', true, osc_is_ip_banned('2001:db8:aa:ffff::9', $rules));
pin('an address outside every range is not banned', false, osc_is_ip_banned('2001:db8:ab::1', $rules));
pin('a prefix longer than 128 bits matches nothing', false, osc_is_ip_banned('2001:db8::77', array(array('s_ip' => '2001:db8::/129', 's_email' => ''))));

harness_section('IPv4 visitors');

pin('an IPv4 CIDR rule bans an address inside it', true, osc_is_ip_banned('203.0.113.77', $rules));
pin('and not the next network', false, osc_is_ip_banned('203.0.114.1', $rules));
pin('dotted-block rules work as before', true, osc_is_ip_banned('10.0.9.3', $rules));
pin('and their ranges still end where they did', false, osc_is_ip_banned('10.0.9.6', $rules));
pin('a prefix longer than 32 bits matches nothing', false, osc_is_ip_banned('198.51.100.1', array(array('s_ip' => '198.51.100.0/33', 's_email' => ''))));

harness_section('rules that are not addresses');

pin('an IPv6 visitor with only an e-mail rule is not banned', false, osc_is_ip_banned('2001:db8::5', array(array('s_ip' => '', 's_email' => 'x@example.com'))));
pin('a garbage rule bans nobody', false, \mindstellar\security\AddressGuard::matches('192.0.2.1', 'not-an-ip/8'));
pin('a garbage visitor address matches nothing', false, \mindstellar\security\AddressGuard::matches('nonsense', '2001:db8::/32'));

harness_section('IPv4 visitors written as IPv6');

pin('an IPv4 CIDR rule bans a mapped address inside it', true, osc_is_ip_banned('::ffff:203.0.113.9', $rules));
pin('a dotted-block rule bans a mapped address', true, osc_is_ip_banned('::ffff:10.0.4.2', $rules));
pin('a mapped address outside every rule is not banned', false, osc_is_ip_banned('::ffff:198.51.100.1', $rules));

harness_section('inRange() checks the prefix itself');

pin('a prefix longer than the family matches nothing', false, \mindstellar\security\AddressGuard::inRange('192.0.2.1', '192.0.2.0/40'));
pin('a prefix that is not a number matches nothing', false, \mindstellar\security\AddressGuard::inRange('192.0.2.1', '192.0.2.0/x'));
pin('a range with no prefix matches nothing', false, \mindstellar\security\AddressGuard::inRange('192.0.2.1', '192.0.2.0'));

exit(harness_result());
