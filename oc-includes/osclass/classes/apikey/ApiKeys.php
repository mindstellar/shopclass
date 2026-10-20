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

namespace mindstellar\apikey;

use mindstellar\utility\Clock;

/**
 * API keys: making them, checking a token, rotating and revoking them.
 *
 * A token is `sck_<key id>.<secret>` (an admin's or a user's key) or `scp_<key id>.<secret>`
 * (a public key, safe to ship in an app; it only reads public data). The key id is stored as
 * it is, so a key can be found; the secret only as a sha256 hash, so a copy of the database
 * holds no working token. The secret is shown once, when the key is made.
 */
final class ApiKeys
{
    public const KEY_PREFIX    = 'sck_';
    public const PUBLIC_PREFIX = 'scp_';

    /** How often a key's last use is written, in seconds. */
    private const TOUCH_EVERY = 300;

    private const KEY_ID_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(private CredentialStore $store, private Scopes $scopes, private Clock $clock)
    {
    }

    /**
     * Whether a string has the shape of an API key token's prefix.
     */
    public static function looksLikeKey(string $token): bool
    {
        return str_starts_with($token, self::KEY_PREFIX) || str_starts_with($token, self::PUBLIC_PREFIX);
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * A new stored token's parts: its public id, its secret (shown once) and the secret's hash.
     *
     * @return array{0:string,1:string,2:string} token id, secret, secret hash
     */
    public static function mint(): array
    {
        $secret = bin2hex(random_bytes(32));

        return [self::newId(), $secret, self::hash($secret)];
    }

    /**
     * The prefix, token id and secret of a `<prefix><token id>.<secret>` token, or null when it
     * is malformed or its prefix is not one of $prefixes.
     *
     * @param string[] $prefixes e.g. [self::KEY_PREFIX]
     *
     * @return array{0:string,1:string,2:string}|null
     */
    public static function parse(string $token, array $prefixes): ?array
    {
        $pattern = '/^(' . implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), $prefixes)) . ')([0-9A-Za-z]{16})\.([0-9a-f]{64})$/D';

        return preg_match($pattern, $token, $m) === 1 ? [$m[1], $m[2], $m[3]] : null;
    }

    /**
     * Whether $secret is the one the stored row was made with, compared in constant time.
     */
    public static function secretMatches(StoredKey $key, string $secret): bool
    {
        return hash_equals($key->secretHash(), self::hash($secret));
    }

    /**
     * Make a key. Scopes the key may not hold are dropped.
     *
     * @param string   $kind      CredentialKind::KEY or CredentialKind::PUBLIC
     * @param string[] $scopes
     * @param int|null $expiresAt Unix time, or null for never
     * @param int|null $rateLimit requests a minute, or null for the site default
     *
     * @throws \InvalidArgumentException on a bad kind or owner, or no scope it may hold
     */
    public function create(string $kind, string $name, array $scopes, KeyOwner $owner, ?int $expiresAt = null, ?int $rateLimit = null): IssuedToken
    {
        if ($kind !== CredentialKind::KEY && $kind !== CredentialKind::PUBLIC) {
            throw new \InvalidArgumentException('A key is of kind key or public.');
        }
        if ($kind === CredentialKind::PUBLIC && !$owner->isAdmin()) {
            throw new \InvalidArgumentException('Only an admin makes public keys.');
        }
        $scopes = Scopes::normalize($scopes, $this->scopes->allowedFor($kind, $owner));
        if ($scopes === []) {
            throw new \InvalidArgumentException('A key needs at least one scope it may hold.');
        }

        [$tokenId, $secret, $hash] = self::mint();
        $id = $this->store->insert(new StoredKey(
            id: 0,
            kind: $kind,
            tokenId: $tokenId,
            secretHash: $hash,
            name: mb_substr(trim($name), 0, 100),
            scopes: $scopes,
            owner: $owner,
            rateLimit: $rateLimit !== null && $rateLimit > 0 ? $rateLimit : null,
            expiresAt: $expiresAt,
            createdAt: $this->clock->now()
        ));
        $prefix = $kind === CredentialKind::PUBLIC ? self::PUBLIC_PREFIX : self::KEY_PREFIX;

        return new IssuedToken($prefix . $tokenId . '.' . $secret, $expiresAt, $scopes, id: $id, tokenId: $tokenId);
    }

    /**
     * A new key with the same kind, name, owner, scopes, limit and expiry. The old one keeps
     * working until it is revoked, so a client can switch over without a gap.
     *
     * @return IssuedToken|null null when there is no such key, or it is revoked, disabled,
     *                        expired or its owner is gone
     * @param int|null      $notAfter the latest expiry the new key may have
     * @param KeyOwner|null $owner    the new key's owner, or null to keep the old one's
     */
    public function rotate(int $id, ?int $notAfter = null, ?KeyOwner $owner = null): ?IssuedToken
    {
        $old = $this->store->find($id);
        if ($old === null || !in_array($old->kind(), [CredentialKind::KEY, CredentialKind::PUBLIC], true)
            || !$old->isUsableAt($this->clock->now())
        ) {
            return null;
        }

        $expiresAt = $old->expiresAt();
        if ($notAfter !== null && ($expiresAt === null || $expiresAt > $notAfter)) {
            $expiresAt = $notAfter;
        }

        return $this->create($old->kind(), $old->name(), $old->scopes(), $owner ?? $old->owner(), $expiresAt, $old->rateLimit());
    }

    public function revoke(int $id): bool
    {
        return $this->store->revoke($id);
    }

    /**
     * The public key id inside a well-formed token, or null.
     */
    public static function tokenId(string $token): ?string
    {
        return self::parse($token, [self::KEY_PREFIX, self::PUBLIC_PREFIX])[1] ?? null;
    }

    /**
     * The credential a token stands for, or a refusal (malformed, unknown, wrong secret,
     * disabled, revoked, expired, or an owner that is gone or blocked) saying whether it named a stored key.
     *
     * @param string $token without the `Bearer ` prefix
     * @param string $ip    stored as the key's last user
     */
    public function check(string $token, string $ip = ''): KeyCheck
    {
        $parts = self::parse($token, [self::KEY_PREFIX, self::PUBLIC_PREFIX]);
        if ($parts === null) {
            return KeyCheck::refused(false);
        }
        [$prefix, $tokenId, $secret] = $parts;
        $kind = $prefix === self::PUBLIC_PREFIX ? CredentialKind::PUBLIC : CredentialKind::KEY;
        $key  = $this->store->findByTokenId($tokenId);
        if ($key === null) {
            return KeyCheck::refused(false);
        }
        $now = $this->clock->now();
        if ($key->kind() !== $kind || !self::secretMatches($key, $secret) || !$key->isUsableAt($now)) {
            return KeyCheck::refused(true);
        }
        $owner = $key->owner();
        if ($key->lastUsedAt() === null || $now - $key->lastUsedAt() >= self::TOUCH_EVERY) {
            $this->store->touch($key->id(), substr($ip, 0, 45), $now);
        }

        // Scopes are cut to what the owner may hold today, so a demoted admin's key shrinks.
        return KeyCheck::accepted(new Credential(
            $kind,
            Scopes::normalize($key->scopes(), $this->scopes->allowedFor($kind, $owner)),
            $owner->userId(),
            $owner->adminId(),
            $key->id(),
            $key->rateLimit(),
            $key->name(),
            $owner->isModerator()
        ));
    }

    /**
     * A random 16-character base62 id, the public half of a stored token.
     */
    public static function newId(): string
    {
        $id  = '';
        $max = strlen(self::KEY_ID_CHARS) - 1;
        for ($i = 0; $i < 16; $i++) {
            $id .= self::KEY_ID_CHARS[random_int(0, $max)];
        }

        return $id;
    }
}
