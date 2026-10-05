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

namespace mindstellar\api\auth;

use mindstellar\security\AddressBucket;
use mindstellar\security\RateLimit;

/**
 * Failed token checks, counted so guessing is slow without one bad caller locking out
 * everyone behind a shared address (an office, a CDN, carrier NAT).
 *
 * Two counters, both read in one query before any token is looked at:
 * - per address and key id: MAX failures shut that one key out from that address, so a
 *   stale key left in a deployed app stops only itself;
 * - per address: ADDRESS_MAX failures with no known key id (guesses, garbage) shut the
 *   whole address out. Wrong secrets for a real key id count only in the first.
 *
 * Both fail open: when the counter cannot be read, the token is checked as usual. A key's
 * secret is 256 random bits, so the counters only slow a noisy caller; refusing every keyed
 * request whenever t_rate_counter is unreachable would take the API down for nothing.
 */
final class FailureCounter
{
    /** Failures allowed per address and key id in the window. */
    public const MAX = 20;

    /** Failures with no known key id allowed per address in the window. */
    public const ADDRESS_MAX = 500;

    /** The window, in seconds. */
    public const WINDOW = 900;

    private const CONTEXT = 'api_auth_fail';

    /** @var \Closure(string, string[], int): ?array<string,int> */
    private \Closure $counts;

    /** @var \Closure(string, string, int): ?int */
    private \Closure $increment;

    /**
     * @param callable|null $counts    (context, keys, window) => key => failures, null when unreadable
     * @param callable|null $increment (context, key, window) => failures after this one
     */
    public function __construct(?callable $counts = null, ?callable $increment = null)
    {
        $this->counts    = \Closure::fromCallable($counts ?? [RateLimit::class, 'countMany']);
        $this->increment = \Closure::fromCallable($increment ?? [RateLimit::class, 'increment']);
    }

    /**
     * Whether a token from this address is refused before it is looked at.
     *
     * @param string|null $tokenId the key id inside the token, null when it has none
     */
    public function blocked(string $ip, ?string $tokenId): bool
    {
        if ($ip === '') {
            return false;
        }
        $address = self::addressKey($ip);
        $keys    = $tokenId === null ? [$address] : [$address, self::keyKey($ip, $tokenId)];
        $counts  = ($this->counts)(self::CONTEXT, $keys, self::WINDOW);
        if ($counts === null) {
            return false;
        }

        return ($counts[$address] ?? 0) >= self::ADDRESS_MAX
            || ($tokenId !== null && ($counts[self::keyKey($ip, $tokenId)] ?? 0) >= self::MAX);
    }

    /**
     * Count one failure.
     *
     * @param string|null $tokenId the key id inside the token
     * @param bool        $known   whether that key id belongs to a stored key
     */
    public function record(string $ip, ?string $tokenId, bool $known): void
    {
        if ($ip === '') {
            return;
        }
        $key = $known && $tokenId !== null ? self::keyKey($ip, $tokenId) : self::addressKey($ip);
        ($this->increment)(self::CONTEXT, $key, self::WINDOW);
    }

    private static function addressKey(string $ip): string
    {
        return 'addr:' . AddressBucket::of($ip);
    }

    private static function keyKey(string $ip, string $tokenId): string
    {
        return 'key:' . AddressBucket::of($ip) . '|' . $tokenId;
    }
}
