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
 * In-memory doubles for the API kernel's collaborators, and builders that fill in the parts a
 * test does not look at.
 */

require_once dirname(__DIR__, 2) . '/oc-includes/vendor/autoload.php';

use mindstellar\api\ApiSettings;
use mindstellar\api\auth\AccessTokens;
use mindstellar\api\auth\AdminRows;
use mindstellar\api\auth\ApiKeys;
use mindstellar\api\auth\Authenticator;
use mindstellar\api\auth\FailureCounter;
use mindstellar\api\auth\PageTokenAuth;
use mindstellar\api\auth\Scopes;
use mindstellar\api\auth\UserRows;
use mindstellar\api\idempotency\Idempotency;
use mindstellar\api\idempotency\IdempotencyRecord;
use mindstellar\api\idempotency\IdempotencyStore;
use mindstellar\api\Kernel;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\utility\SystemClock;

/** The Idempotency-Key store as an array. */
final class MemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string,array{fp:string,status:string,response:?string,created:int,expires:int}> */
    public array $rows = [];

    public function claim(string $hash, string $fingerprint, int $now, int $expiresAt, int $lockTtl): ?IdempotencyRecord
    {
        $row = $this->rows[$hash] ?? null;
        if ($row === null || $row['expires'] <= $now || ($row['status'] === IdempotencyRecord::LOCKED && $row['created'] < $now - $lockTtl)) {
            $this->rows[$hash] = ['fp' => $fingerprint, 'status' => IdempotencyRecord::LOCKED, 'response' => null, 'created' => $now, 'expires' => $expiresAt];

            return null;
        }

        return new IdempotencyRecord($row['fp'], $row['status'], $row['response']);
    }

    public function complete(string $hash, int $status, string $response): void
    {
        $this->rows[$hash]['status']   = IdempotencyRecord::DONE;
        $this->rows[$hash]['response'] = $response;
    }

    public function release(string $hash): void
    {
        unset($this->rows[$hash]);
    }
}

/**
 * A limiter that counts every request as the first, unless $count says otherwise.
 *
 * @param callable|null $count (bucket, key, window) => count so far
 */
function api_test_limiter(?callable $count = null): RateLimiter
{
    return new RateLimiter($count ?? static fn (): int => 1, new SystemClock());
}

/**
 * User rows from an array instead of t_user.
 *
 * @param array<int,array<string,mixed>> $rows user id => row
 */
function api_test_users(array $rows = []): UserRows
{
    return new UserRows(static fn (int $id): ?array => $rows[$id] ?? null);
}

/**
 * An authenticator that never counts a failure and, unless given tokens, knows no user.
 */
function api_test_authenticator(ApiKeys $keys, ?FailureCounter $failures = null, ?AccessTokens $tokens = null, ?PageTokenAuth $session = null): Authenticator
{
    return new Authenticator(
        $keys,
        $failures ?? new FailureCounter(static fn (): array => [], static fn (): int => 1),
        $tokens ?? new AccessTokens(new Scopes(), api_test_users()),
        $session
    );
}

/**
 * A kernel over a router and an authenticator; the rest is in memory and lets every request through.
 */
function api_test_kernel(
    Router $router,
    Authenticator $authenticator,
    ?ApiSettings $settings = null,
    ?RateLimiter $limiter = null,
    ?Validator $validator = null,
    ?UserRows $users = null,
    ?Idempotency $idempotency = null,
    ?AdminRows $admins = null
): Kernel {
    return new Kernel(
        $router,
        $authenticator,
        $limiter ?? api_test_limiter(),
        $validator ?? new Validator(),
        $settings ?? new ApiSettings(true),
        $users ?? api_test_users(),
        $admins ?? new AdminRows(static fn (): ?array => null),
        $idempotency ?? new Idempotency(new MemoryIdempotencyStore(), new SystemClock())
    );
}
