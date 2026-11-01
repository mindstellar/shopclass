<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\security;

use mindstellar\utility\Utils;

/**
 * The addresses the admin panel answers, from OSC_ADMIN_IP_ALLOW: IPv4 and IPv6 addresses and
 * CIDR ranges, separated by commas. Unset or empty, every address is let in. Loopback is always
 * let in, so an SSH tunnel still reaches a panel that is locked to the wrong address.
 * The address checked is REMOTE_ADDR; behind a proxy the web server must set it to the visitor's.
 */
final class AdminIpAllowlist
{
    private const LOOPBACK = array('127.0.0.0/8', '::1/128');

    /** @var array<int,string> */
    private $ranges;

    /** @var int */
    private $listed;

    /**
     * @param array<int,string> $ranges CIDR ranges, as parse() returns them
     */
    public function __construct(array $ranges)
    {
        $this->listed = count($ranges);
        $this->ranges = array_merge($ranges, self::LOOPBACK);
    }

    /** How many good entries the list has, loopback not counted. */
    public function count(): int
    {
        return $this->listed;
    }

    /**
     * The list from OSC_ADMIN_IP_ALLOW, or null when the constant is unset or empty.
     */
    public static function fromConfig(): ?self
    {
        $list = defined('OSC_ADMIN_IP_ALLOW') ? trim((string) OSC_ADMIN_IP_ALLOW) : '';

        return $list === '' ? null : new self(self::parse($list));
    }

    /**
     * Each good entry as "network/bits"; a bad entry is left out, and named in the error log when
     * OSC_DEBUG is on.
     *
     * @return array<int,string>
     */
    public static function parse(string $list): array
    {
        $ranges = array();
        $bad    = array();
        foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: array() as $entry) {
            $range = AddressGuard::cidr($entry);
            if ($range === null) {
                $bad[] = $entry;
            } else {
                $ranges[] = $range;
            }
        }
        if ($bad !== array() && defined('OSC_DEBUG') && OSC_DEBUG) {
            error_log('OSC_ADMIN_IP_ALLOW: ignored ' . implode(', ', array_map(static fn ($e) => substr(preg_replace('/[^0-9A-Fa-f.:\/]/', '?', $e) ?? '', 0, 64), $bad)));
        }

        return $ranges;
    }

    /**
     * Whether $ip may reach the admin panel. An IPv4 address written as IPv6 (::ffff:a.b.c.d)
     * is checked as IPv4.
     */
    public function allows(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $ip = AddressGuard::mappedV4($ip) ?? $ip;
        foreach ($this->ranges as $range) {
            if (AddressGuard::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Answer an admin request from an address outside the list with a plain 404, before any
     * session or cookie. Does nothing when the list is off or the address is in it.
     */
    public static function guard(): void
    {
        $list = self::fromConfig();
        $ip   = Utils::getClientIp();
        if ($list === null || $list->allows($ip)) {
            return;
        }
        if (defined('OSC_DEBUG') && OSC_DEBUG) {
            error_log('Admin panel refused for ' . substr(preg_replace('/[^0-9A-Fa-f.:]/', '?', $ip) ?? '', 0, 45));
        }
        // Stop at a buffer that cannot be removed, or the loop never ends.
        while (ob_get_level() > 0 && @ob_end_clean()) {
        }
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo 'Not Found';
        exit;
    }
}
