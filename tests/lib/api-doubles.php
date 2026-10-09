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

use mindstellar\api\ApiServices;
use mindstellar\api\auth\AccessTokens;
use mindstellar\api\auth\Authenticator;
use mindstellar\api\auth\FailureCounter;
use mindstellar\api\auth\MemoisedRows;
use mindstellar\api\auth\PageTokenAuth;
use mindstellar\api\auth\UserRows;
use mindstellar\api\idempotency\Idempotency;
use mindstellar\api\idempotency\IdempotencyRecord;
use mindstellar\api\idempotency\IdempotencyStore;
use mindstellar\api\idempotency\KvIdempotencyStore;
use mindstellar\api\Kernel;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\ApiSettings;
use mindstellar\apikey\Scopes;
use mindstellar\utility\SystemClock;

/** The Idempotency-Key store as an array. */
final class MemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string,array{fp:string,lock:string,status:string,response:?string,created:int,expires:int}> */
    public array $rows = [];

    public function claim(string $hash, string $fingerprint, int $now, int $expiresAt, int $lockTtl, string $lock): ?IdempotencyRecord
    {
        $row   = $this->rows[$hash] ?? null;
        $stale = $row !== null && $row['status'] === IdempotencyRecord::LOCKED && $row['created'] < $now - $lockTtl && $row['fp'] === $fingerprint;
        if ($row === null || $row['expires'] <= $now || $stale) {
            $this->rows[$hash] = ['fp' => $fingerprint, 'lock' => $lock, 'status' => IdempotencyRecord::LOCKED, 'response' => null, 'created' => $now, 'expires' => $expiresAt];

            return null;
        }

        return new IdempotencyRecord($row['fp'], $row['status'], $row['response']);
    }

    public function complete(string $hash, string $lock, int $status, string $response): void
    {
        if ($this->holds($hash, $lock)) {
            $this->rows[$hash]['status']   = IdempotencyRecord::DONE;
            $this->rows[$hash]['response'] = $response;
        }
    }

    public function release(string $hash, string $lock): void
    {
        if ($this->holds($hash, $lock)) {
            unset($this->rows[$hash]);
        }
    }

    private function holds(string $hash, string $lock): bool
    {
        return ($this->rows[$hash]['status'] ?? null) === IdempotencyRecord::LOCKED && $this->rows[$hash]['lock'] === $lock;
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
 * Access tokens whose sign-in is live, unless $familyLive says otherwise.
 *
 * @param callable|null $familyLive (family) => whether the sign-in is live
 */
function api_test_access_tokens(Scopes $scopes, UserRows $users, int $ttl = AccessTokens::TTL, ?callable $familyLive = null): AccessTokens
{
    return new AccessTokens($scopes, $users, $ttl, $familyLive ?? static fn (): bool => true);
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
        $tokens ?? api_test_access_tokens(new Scopes(), api_test_users()),
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
    ?MemoisedRows $admins = null
): Kernel {
    return new Kernel(
        $router,
        $authenticator,
        $limiter ?? api_test_limiter(),
        $validator ?? new Validator(),
        $settings ?? new ApiSettings(true, userKeys: true),
        $users ?? api_test_users(),
        $admins ?? new MemoisedRows(static fn (): ?array => null),
        $idempotency ?? new Idempotency(new MemoryIdempotencyStore(), new SystemClock())
    );
}

/**
 * The kernel ApiServices::kernel() wires, over the core routes and $validator, with a limiter
 * that lets every request through.
 */
function api_services_kernel(ApiServices $services, Validator $validator): Kernel
{
    return api_test_kernel(
        new Router($validator, Router::core(), handlers: $services->handlers()),
        $services->authenticator(),
        $services->settings(),
        validator: $validator,
        users: $services->users(),
        idempotency: new Idempotency(new KvIdempotencyStore(), $services->clock()),
        admins: $services->admins()
    );
}
