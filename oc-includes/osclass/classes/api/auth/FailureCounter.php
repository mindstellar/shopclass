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
 * Two counters:
 * - per address and key id: MAX failures shut that one key out from that address before it
 *   is looked at, so a stale key left in a deployed app stops only itself;
 * - per address: ADDRESS_MAX failures with no known key id (guesses, garbage) turn that
 *   address's failed tokens into 429s. A valid token from it still works. Wrong secrets for a
 *   real key id count only in the first.
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
     * Whether this key id is shut out from this address, so its token is refused before it
     * is looked at.
     *
     * @param string|null $tokenId the key id inside the token, null when it has none
     */
    public function keyBlocked(string $ip, ?string $tokenId): bool
    {
        if ($ip === '' || $tokenId === null) {
            return false;
        }

        return $this->count(self::keyKey($ip, $tokenId)) >= self::MAX;
    }

    /**
     * Whether this address has failed so often that its failed tokens answer 429.
     */
    public function addressBlocked(string $ip): bool
    {
        return $ip !== '' && $this->count(self::addressKey($ip)) >= self::ADDRESS_MAX;
    }

    private function count(string $key): int
    {
        return (int) ((($this->counts)(self::CONTEXT, [$key], self::WINDOW) ?? [])[$key] ?? 0);
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
