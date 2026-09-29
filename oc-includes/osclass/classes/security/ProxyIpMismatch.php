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
 * Detects a visitor-IP proxy that core does not trust yet.
 *
 * Core reads the visitor IP only from REMOTE_ADDR, on purpose: an unauthenticated request
 * can set any forwarding header it likes, so trusting one by default would let a visitor
 * fake their IP and dodge a ban or sign-in throttle. Behind Cloudflare, a load balancer, or
 * a second web server with no real-IP setup, REMOTE_ADDR is the proxy's own address instead
 * of the visitor's, and a forwarding header disagrees with it. This class only compares the
 * two and reports the mismatch; it never changes which address core trusts.
 */
final class ProxyIpMismatch
{
    /** Server key => header name, checked as a single value. */
    private const SINGLE_HEADERS = array(
        'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP',
        'HTTP_TRUE_CLIENT_IP'   => 'True-Client-IP',
        'HTTP_X_REAL_IP'        => 'X-Real-IP',
    );

    /** Server key => header name, checked as a comma-separated list. */
    private const LIST_HEADERS = array(
        'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For',
        'HTTP_FORWARDED'       => 'Forwarded',
    );

    /**
     * @param string               $remoteAddr the request's REMOTE_ADDR
     * @param array<string,string> $server     the headers listed above, keyed as in $_SERVER
     *
     * @return array{header: string, proxy: string}|null the triggering header and REMOTE_ADDR, or null when addresses agree
     */
    public static function detect(string $remoteAddr, array $server): ?array
    {
        $remote = self::parseIp($remoteAddr);
        if ($remote === null) {
            return null;
        }

        foreach (self::SINGLE_HEADERS as $key => $label) {
            $raw = trim((string)($server[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $ip = self::parseIp($raw);
            if ($ip !== null && $ip !== $remote) {
                return array('header' => $label, 'proxy' => $remoteAddr);
            }
        }

        foreach (self::LIST_HEADERS as $key => $label) {
            $raw = trim((string)($server[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $ips = ($label === 'Forwarded') ? self::forwardedIps($raw) : self::listIps($raw);
            if ($ips === array()) {
                continue;
            }
            if (!in_array($remote, $ips, true)) {
                return array('header' => $label, 'proxy' => $remoteAddr);
            }
        }

        return null;
    }

    /**
     * Every valid address in a comma-separated list such as X-Forwarded-For.
     *
     * @param string $header
     *
     * @return array<int,string>
     */
    private static function listIps(string $header): array
    {
        $ips = array();
        foreach (explode(',', $header) as $entry) {
            $ip = self::parseIp($entry);
            if ($ip !== null) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    /**
     * Every `for=` address in a Forwarded header (RFC 7239), one per comma-separated element.
     *
     * @param string $header
     *
     * @return array<int,string>
     */
    private static function forwardedIps(string $header): array
    {
        $ips = array();
        foreach (explode(',', $header) as $element) {
            foreach (explode(';', $element) as $pair) {
                $pair = trim($pair);
                if (stripos($pair, 'for=') === 0) {
                    $ip = self::parseIp(substr($pair, 4));
                    if ($ip !== null) {
                        $ips[] = $ip;
                    }
                    break;
                }
            }
        }

        return $ips;
    }

    /**
     * A single address value to its bare IP: trims whitespace, strips a quoted wrapper,
     * strips a port, and unwraps a bracketed IPv6 address. Returns null when it is not a
     * valid IP once that is done.
     *
     * @param string $raw
     *
     * @return string|null lower-cased so an IPv6 address compares regardless of hex case
     */
    private static function parseIp(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if ($raw[0] === '"' && substr($raw, -1) === '"' && strlen($raw) >= 2) {
            $raw = trim(substr($raw, 1, -1));
        }
        if ($raw === '') {
            return null;
        }

        // Bracketed IPv6, with or without a trailing :port — "[::1]" or "[::1]:4711".
        if ($raw[0] === '[') {
            $end = strpos($raw, ']');
            if ($end === false) {
                return null;
            }
            $ip = substr($raw, 1, $end - 1);

            return filter_var($ip, FILTER_VALIDATE_IP) !== false ? strtolower($ip) : null;
        }

        // Exactly one colon reads as IPv4:port; more than one is a bare, unbracketed IPv6
        // address, which carries no port on its own (RFC 7239 requires brackets for that).
        if (substr_count($raw, ':') === 1) {
            [$host] = explode(':', $raw, 2);

            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $host : null;
        }

        return filter_var($raw, FILTER_VALIDATE_IP) !== false ? strtolower($raw) : null;
    }
}
