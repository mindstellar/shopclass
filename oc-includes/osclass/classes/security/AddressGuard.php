<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

/**
 * Decides whether the server may fetch an address that someone other than the admin sent.
 *
 * Such an address could name something only the server can reach: the database, a cloud
 * metadata service, the admin panel on localhost. So only http and https on their normal
 * ports pass, and every address the host name resolves to must be public. The checked IPs
 * are handed back, so the download connects to one of them (CURLOPT_RESOLVE) and a second
 * DNS answer cannot point it somewhere else.
 *
 * The caller must pin that IP, and must not follow redirects blindly: a public address can
 * redirect to a private one, so check each redirect target here before fetching it.
 */
final class AddressGuard
{
    /** Ranges nothing is fetched from, beyond what PHP itself calls private or reserved. */
    private const BLOCKED = array(
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    );

    private const PORTS = array('http' => 80, 'https' => 443);

    /** @var callable(string): array<int,string> */
    private $resolve;

    /**
     * @param callable|null $resolve host name => its IP addresses; DNS by default
     */
    public function __construct(?callable $resolve = null)
    {
        $this->resolve = $resolve ?? array(self::class, 'dns');
    }

    /**
     * @param string $url
     *
     * @return array{ok: bool, error?: string, host?: string, port?: int, ip?: string, ips?: array<int,string>}
     */
    public function check(string $url): array
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host   = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if (!isset(self::PORTS[$scheme]) || $host === '') {
            return array('ok' => false, 'error' => 'Only http and https addresses are fetched.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return array('ok' => false, 'error' => 'An address with a user name or password is not fetched.');
        }
        $port = (int)($parts['port'] ?? self::PORTS[$scheme]);
        if ($port !== self::PORTS[$scheme]) {
            return array('ok' => false, 'error' => 'Only the standard port is fetched.');
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        // cURL decodes a %-escaped host that parse_url keeps, so it would skip the pinned address.
        if (!$literal && preg_match('/^[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)*\.?$/', $host) !== 1) {
            return array('ok' => false, 'error' => 'The host name holds characters an address may not.');
        }

        // cURL reads a host such as 2130706433 or 0x7f.1 as an IP itself and skips the pin.
        $last = (string)substr(strrchr('.' . rtrim($host, '.'), '.'), 1);
        if (!$literal && (ctype_digit($last) || strncmp($last, '0x', 2) === 0)) {
            return array('ok' => false, 'error' => 'The host name holds characters an address may not.');
        }

        $ips = $literal ? array($host) : ($this->resolve)($host);
        if ($ips === array()) {
            return array('ok' => false, 'error' => 'The host name does not resolve.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                return array('ok' => false, 'error' => 'The host is on a private or reserved network.');
            }
        }

        // IPv4 first: many servers can reach the internet over IPv4 only.
        $isV4 = static fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $ips  = array_values(array_merge(array_filter($ips, $isV4), array_filter($ips, static fn ($ip) => !$isV4($ip))));

        return array('ok' => true, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'ips' => $ips);
    }

    /**
     * @param string $ip
     *
     * @return bool
     */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Of IPv6, only global unicast reaches the internet; the rest is local, mapped or relayed.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && !self::inRange($ip, '2000::/3')) {
            return false;
        }
        foreach (self::BLOCKED as $cidr) {
            if (self::inRange($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $ip
     * @param string $cidr
     *
     * @return bool
     */
    private static function inRange(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $a = inet_pton($ip);
        $b = inet_pton($network);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits  = (int)$bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($a, $b, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xff << (8 - $rest)) & 0xff);

        return (ord($a[$bytes]) & ord($mask)) === (ord($b[$bytes]) & ord($mask));
    }

    /**
     * Every IPv4 and IPv6 address a host name resolves to.
     *
     * @param string $host
     *
     * @return array<int,string>
     */
    public static function dns(string $host): array
    {
        $ips = array();
        foreach ((array)@dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if ($ips === array()) {
            $ips = (array)@gethostbynamel($host);
        }

        return array_values(array_unique(array_filter($ips, 'is_string')));
    }
}
