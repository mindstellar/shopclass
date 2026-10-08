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

use mindstellar\apiaccess\ApiSettings;
use mindstellar\security\AddressBucket;
use mindstellar\security\RateLimit;

/**
 * Failed token checks, counted per address and key id (MAX) and per address with no known key id
 * (ADDRESS_MAX), failing open when the counter cannot be read. A site-wide marker lets keyBlocked()
 * skip the counter while no such failure exists.
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

    /** The preference holding the end of the last window with a failure for a known key id. */
    public const MARKER = 'auth_failures_until';

    /** @var \Closure(string, string[], int): ?array<string,int> */
    private \Closure $counts;

    /** @var \Closure(string, string, int): ?int */
    private \Closure $increment;

    /** @var (\Closure(): int)|null */
    private ?\Closure $markedUntil;

    /** @var (\Closure(int): bool)|null */
    private ?\Closure $mark;

    /** @var \Closure(): int */
    private \Closure $now;

    /**
     * Without $markedUntil and $mark every check reads the counter, unless the counters are the
     * site's own, which keep the marker in the `api` preferences (loaded on every request).
     *
     * @param callable|null $counts      (context, keys, window) => key => failures, null when unreadable
     * @param callable|null $increment   (context, key, window) => failures after this one
     * @param callable|null $markedUntil () => the marker's time, 0 when unset
     * @param callable|null $mark        (time) => whether the marker was written
     * @param callable|null $now         () => the current time
     */
    public function __construct(
        ?callable $counts = null,
        ?callable $increment = null,
        ?callable $markedUntil = null,
        ?callable $mark = null,
        ?callable $now = null
    ) {
        if ($counts === null && $increment === null && $markedUntil === null && $mark === null) {
            $markedUntil = static fn (): int => (int) osc_get_preference(self::MARKER, ApiSettings::SECTION);
            $mark        = static fn (int $until): bool => (bool) osc_set_preference(self::MARKER, (string) $until, ApiSettings::SECTION, 'INTEGER');
        }
        $this->counts      = \Closure::fromCallable($counts ?? [RateLimit::class, 'countMany']);
        $this->increment   = \Closure::fromCallable($increment ?? [RateLimit::class, 'increment']);
        $this->markedUntil = $markedUntil !== null && $mark !== null ? \Closure::fromCallable($markedUntil) : null;
        $this->mark        = $markedUntil !== null && $mark !== null ? \Closure::fromCallable($mark) : null;
        $this->now         = \Closure::fromCallable($now ?? 'time');
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
        if ($this->markedUntil !== null && ($this->markedUntil)() <= ($this->now)()) {
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
        $byKey = $known && $tokenId !== null;
        if ($byKey) {
            $this->markWindow();
        }
        ($this->increment)(self::CONTEXT, $byKey ? self::keyKey($ip, $tokenId) : self::addressKey($ip), self::WINDOW);
    }

    /**
     * Set the marker to the end of the current window, before the failure is counted, so a
     * request that can see the count can see the marker. Written once per window at most.
     */
    private function markWindow(): void
    {
        if ($this->mark === null) {
            return;
        }
        $now = ($this->now)();
        $end = $now - ($now % self::WINDOW) + self::WINDOW;
        if (($this->markedUntil)() < $end) {
            ($this->mark)($end);
        }
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
