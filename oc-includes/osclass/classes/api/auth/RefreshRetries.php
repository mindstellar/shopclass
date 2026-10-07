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

use mindstellar\apiaccess\IssuedToken;
use mindstellar\model\KeyValue;
use mindstellar\security\SecretBox;

/**
 * The token a refresh swap just handed out, kept encrypted for WINDOW seconds, so a client that
 * lost the answer and sends the old token again gets the same new token instead of having its
 * sign-in revoked. Nothing is ever handed out twice in a way that forks the family: a retry
 * gets the very token the first answer held.
 */
final class RefreshRetries
{
    /** Seconds a swapped token may come back for the same answer. */
    public const WINDOW = 30;

    private const GROUP   = 'api_refresh_retry';
    private const PURPOSE = 'api-refresh-retry';

    /** @var \Closure(string): ?string */
    private \Closure $read;

    /** @var \Closure(string, string, int): void */
    private \Closure $write;

    /**
     * @param callable|null $read  (key) => stored value, null when there is none or it expired
     * @param callable|null $write (key, value, expires at) => void
     */
    public function __construct(?callable $read = null, ?callable $write = null)
    {
        $kv          = $read === null || $write === null ? new KeyValue() : null;
        $this->read  = \Closure::fromCallable($read ?? static fn (string $key): ?string => $kv?->get(self::GROUP, $key)['value'] ?? null);
        $this->write = \Closure::fromCallable($write ?? static function (string $key, string $value, int $expiresAt) use ($kv): void {
            $kv?->set(self::GROUP, $key, $value, $expiresAt);
        });
    }

    /**
     * Keep the token that replaced the refresh token stored as $oldId.
     */
    public function remember(int $oldId, IssuedToken $new, int $now): void
    {
        $value = json_encode(['id' => $new->id(), 'token' => $new->token(), 'expires' => $new->expiresAt(), 'scopes' => $new->scopes(), 'user' => $new->userId(), 'family' => $new->family()]);
        ($this->write)((string) $oldId, SecretBox::seal(self::PURPOSE, (string) $value), $now + self::WINDOW);
    }

    /**
     * The token that replaced $oldId, while the window lasts.
     */
    public function recall(int $oldId): ?IssuedToken
    {
        $stored = ($this->read)((string) $oldId);
        $plain  = $stored === null ? null : SecretBox::open(self::PURPOSE, $stored);
        $data   = $plain === null ? null : json_decode($plain, true);
        if (!is_array($data) || !isset($data['id'], $data['token'])) {
            return null;
        }

        return new IssuedToken(
            (string) $data['token'],
            isset($data['expires']) ? (int) $data['expires'] : null,
            array_map('strval', (array) ($data['scopes'] ?? [])),
            id: (int) $data['id'],
            userId: isset($data['user']) ? (int) $data['user'] : null,
            family: isset($data['family']) ? (string) $data['family'] : null
        );
    }
}
