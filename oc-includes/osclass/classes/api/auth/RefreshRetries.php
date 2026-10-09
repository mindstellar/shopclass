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
 * The token a refresh swap just handed out, kept for WINDOW seconds, so a client that lost the
 * answer gets the same new token when it retries. One row per sign-in, encrypted with a key
 * made from the old token's secret, which the site never stores.
 */
final class RefreshRetries
{
    /** Seconds a swapped token may come back for the same answer. */
    public const WINDOW = 30;

    private const GROUP  = 'api_refresh_retry';
    private const PREFIX = 'rr1:';

    /** @var \Closure(string): ?string */
    private \Closure $read;

    /** @var \Closure(string, string, int): void */
    private \Closure $write;

    /** @var \Closure(string): void */
    private \Closure $delete;

    /**
     * @param callable|null $read   (family) => stored value, null when there is none or it expired
     * @param callable|null $write  (family, value, expires at) => void
     * @param callable|null $delete (family) => void
     */
    public function __construct(?callable $read = null, ?callable $write = null, ?callable $delete = null)
    {
        $kv           = $read === null || $write === null || $delete === null ? new KeyValue() : null;
        $this->read   = \Closure::fromCallable($read ?? static fn (string $key): ?string => $kv?->get(self::GROUP, $key)['value'] ?? null);
        $this->write  = \Closure::fromCallable($write ?? static function (string $key, string $value, int $expiresAt) use ($kv): void {
            $kv?->set(self::GROUP, $key, $value, $expiresAt);
        });
        $this->delete = \Closure::fromCallable($delete ?? static function (string $key) use ($kv): void {
            $kv?->delete(self::GROUP, $key);
        });
    }

    /**
     * Keep the token that replaced the refresh token stored as $oldId.
     *
     * @param string $oldSecret the secret half of the token that was swapped
     */
    public function remember(string $family, int $oldId, string $oldSecret, IssuedToken $new, int $now): void
    {
        $plain = (string) json_encode(['old' => $oldId, 'id' => $new->id(), 'token' => $new->token(), 'expires' => $new->expiresAt(), 'scopes' => $new->scopes(), 'user' => $new->userId()]);
        try {
            ($this->write)($family, SecretBox::sealWith(self::key($oldSecret), $plain, self::PREFIX), $now + self::WINDOW);
        } catch (\RuntimeException) {
            // Without the kept copy a lost answer just means signing in again.
        }
    }

    /**
     * The token that replaced $oldId, while the window lasts and only for the holder of its secret.
     */
    public function recall(string $family, int $oldId, string $oldSecret): ?IssuedToken
    {
        $stored = ($this->read)($family);
        $plain  = $stored === null ? null : SecretBox::openWith(self::key($oldSecret), $stored, self::PREFIX);
        $data   = $plain === null ? null : json_decode($plain, true);
        if (!is_array($data) || (int) ($data['old'] ?? 0) !== $oldId || !isset($data['id'], $data['token'])) {
            return null;
        }

        return new IssuedToken(
            (string) $data['token'],
            isset($data['expires']) ? (int) $data['expires'] : null,
            array_map('strval', (array) ($data['scopes'] ?? [])),
            id: (int) $data['id'],
            userId: isset($data['user']) ? (int) $data['user'] : null,
            family: $family
        );
    }

    /**
     * Drop the sign-in's kept token, when the sign-in ends.
     */
    public function forget(string $family): void
    {
        ($this->delete)($family);
    }

    private static function key(string $oldSecret): string
    {
        return hash_hmac('sha256', 'api-refresh-retry', $oldSecret, true);
    }
}
