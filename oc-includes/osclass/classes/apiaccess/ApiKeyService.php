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

namespace mindstellar\apiaccess;

use mindstellar\auth\AdminStore;
use mindstellar\user\UserStore;
use mindstellar\utility\Clock;
use mindstellar\validation\ConflictException;
use mindstellar\validation\NotFoundException;
use mindstellar\validation\RefusedException;

/**
 * API keys as Settings -> API and the api:key:* commands manage them: checks what was asked
 * for, then hands the work to ApiKeys. A refused request throws with the reason to show.
 */
final class ApiKeyService
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_REVOKED  = 'revoked';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_DISABLED = 'disabled';
    /** The admin or user the key belongs to is gone or blocked. */
    public const STATUS_ORPHANED = 'orphaned';

    public function __construct(private ApiKeys $keys, private SignInStore $store, private Scopes $scopes, private Clock $clock)
    {
    }

    /**
     * The scopes an owner may put on a key of this kind, with their descriptions.
     *
     * @return array<string,string>
     */
    public function grantable(string $kind, KeyOwner $owner): array
    {
        $all = $this->scopes->all();
        $out = [];
        foreach ($this->scopes->allowedFor($kind, $owner) as $scope) {
            $out[$scope] = $all[$scope] ?? '';
        }

        return $out;
    }

    /**
     * Make a key. A public key with no scope named gets the public read scope.
     *
     * @param string[] $scopes
     * @param string   $expires '' for never, a date (Y-m-d, valid to the end of that day) or a
     *                          number of days such as "90d"
     * @param int|null $notAfter the latest expiry allowed; a key with no expiry gets this one
     *
     * @throws RefusedException with the reason to show
     */
    public function create(KeyOwner $owner, string $name, string $kind, array $scopes, string $expires = '', ?int $notAfter = null): IssuedToken
    {
        $name = trim($name);
        if ($name === '') {
            throw new RefusedException(_m('Give the key a name, so you know later what uses it.'));
        }
        if (mb_strlen($name) > 100) {
            throw new RefusedException(_m('The key name is too long. Use 100 characters or fewer.'));
        }
        if ($kind !== CredentialKind::KEY && $kind !== CredentialKind::PUBLIC) {
            throw new RefusedException(_m('Choose an admin key or a public key.'));
        }

        $scopes = array_values(array_unique(array_filter(array_map('strval', $scopes), static fn (string $s): bool => $s !== '')));
        if ($scopes === [] && $kind === CredentialKind::PUBLIC) {
            $scopes = [Scopes::PUBLIC_READ];
        }
        if ($scopes === []) {
            throw new RefusedException(_m('Choose at least one scope.'));
        }
        $refused = array_diff($scopes, array_keys($this->grantable($kind, $owner)));
        if ($refused !== []) {
            throw new RefusedException(sprintf(_m('This key cannot hold: %s'), implode(', ', $refused)));
        }

        $expiresAt = $this->expiry($expires);
        if ($notAfter !== null && $expiresAt !== null && $expiresAt > $notAfter) {
            throw new RefusedException(_m('The new key cannot outlive the key that makes it. Choose an earlier expiry date.'));
        }

        return $this->keys->create($kind, $name, $scopes, $owner, $expiresAt ?? $notAfter);
    }

    /**
     * When a key expires; null when it never does or does not exist.
     */
    public function expiresAt(int $id): ?int
    {
        return $id > 0 ? $this->store->find($id)?->expiresAt() : null;
    }

    /**
     * A new key in place of an old one. The old key keeps working until it is revoked.
     * An admin rotates its own keys and any public key, never another admin's key.
     *
     * @throws RefusedException with the reason to show
     */
    public function rotate(int $id, int $actorAdminId): IssuedToken
    {
        $key = $this->manageable($id);
        if ($key->kind() !== CredentialKind::PUBLIC && $key->owner()?->adminId() !== $actorAdminId) {
            throw new RefusedException(_m('You can only rotate your own keys and public keys. Revoke this one and make a new key instead.'));
        }
        $issued = $this->keys->rotate($id);
        if ($issued === null) {
            throw new ConflictException(_m('That key is revoked, expired or its owner is gone, so it cannot be rotated.'));
        }

        return $issued;
    }

    /**
     * A key's stored name, or '' when there is no such key.
     */
    public function nameOf(int $id): string
    {
        return $this->store->find($id)?->name() ?? '';
    }

    /**
     * @throws NotFoundException|ConflictException when there is no such key or it is already revoked
     */
    public function revoke(int $id): void
    {
        $this->manageable($id);
        if (!$this->keys->revoke($id)) {
            throw new ConflictException(_m('That key is already revoked.'));
        }
    }

    /**
     * Revoke every admin and public key an admin owns; runs on `admin_signout_all_after`,
     * inside SignOut's transaction.
     *
     * @throws \mindstellar\database\DbException
     */
    public function revokeAdminKeys(int $adminId): void
    {
        foreach ($this->liveOwnedBy($adminId) as $key) {
            $this->keys->revoke($key->id());
        }
    }

    /**
     * Every admin, user and public key, newest first, as the list shows it. Never the secret
     * or its hash.
     *
     * @return array<int,array{id:int,name:string,kind:string,prefix:string,scopes:string[],owner:string,
     *         owner_admin:int|null,created:int|null,last_used:int|null,expires:int|null,status:string}>
     */
    public function rows(): array
    {
        return $this->describe(array_values(array_filter(
            $this->store->listBy(),
            static fn (StoredKey $k): bool => in_array($k->kind(), [CredentialKind::KEY, CredentialKind::PUBLIC], true)
        )));
    }

    /**
     * One key as rows() lists it, or null when there is no such key.
     *
     * @return array<string,mixed>|null
     */
    public function row(int $id): ?array
    {
        $key = $id > 0 ? $this->store->find($id) : null;
        if ($key === null || !in_array($key->kind(), [CredentialKind::KEY, CredentialKind::PUBLIC], true)) {
            return null;
        }

        return $this->describe([$key])[0];
    }

    /**
     * @param StoredKey[] $keys
     *
     * @return array<int,array<string,mixed>>
     */
    private function describe(array $keys): array
    {
        $admins = AdminStore::usernames(self::ownerIds($keys, static fn (StoredKey $k): ?int => $k->owner()?->adminId()));
        $users  = UserStore::usernames(self::ownerIds($keys, static fn (StoredKey $k): ?int => $k->owner()?->userId()));
        $now    = $this->clock->now();

        return array_map(static function (StoredKey $k) use ($admins, $users, $now): array {
            $owner   = $k->owner();
            $label   = '';
            if ($owner !== null) {
                $label = $owner->isAdmin() ? ($admins[$owner->adminId()] ?? '') : ($users[$owner->userId()] ?? '');
            }

            return [
                'id'          => $k->id(),
                'name'        => $k->name(),
                'kind'        => $k->kind() === CredentialKind::PUBLIC ? 'public' : ($owner !== null && !$owner->isAdmin() ? 'user' : 'admin'),
                'prefix'      => ($k->kind() === CredentialKind::PUBLIC ? ApiKeys::PUBLIC_PREFIX : ApiKeys::KEY_PREFIX) . $k->tokenId(),
                'scopes'      => $k->scopes(),
                'owner'       => $label,
                'owner_admin' => $owner?->adminId(),
                'created'     => $k->createdAt(),
                'last_used'   => $k->lastUsedAt(),
                'expires'     => $k->expiresAt(),
                'status'      => self::status($k, $now),
            ];
        }, $keys);
    }

    public static function status(StoredKey $key, int $now): string
    {
        return match (true) {
            $key->revokedAt() !== null                                 => self::STATUS_REVOKED,
            !$key->enabled()                                           => self::STATUS_DISABLED,
            $key->owner() === null                                     => self::STATUS_ORPHANED,
            $key->expiresAt() !== null && $key->expiresAt() <= $now    => self::STATUS_EXPIRED,
            default                                                    => self::STATUS_ACTIVE,
        };
    }

    /**
     * An expiry as typed, as Unix time; null for never.
     *
     * @throws RefusedException on a date that cannot be read or is not in the future
     */
    public function expiry(string $expires): ?int
    {
        $expires = trim($expires);
        if ($expires === '') {
            return null;
        }
        $now = $this->clock->now();
        if (preg_match('/^(\d{1,4})d$/D', $expires, $m) === 1) {
            $at = (int) $m[1] > 0 ? $now + (int) $m[1] * 86400 : false;
        } else {
            $date = \DateTime::createFromFormat('!Y-m-d', $expires);
            $at   = $date !== false && $date->format('Y-m-d') === $expires ? $date->getTimestamp() + 86399 : false;
        }
        if ($at === false) {
            throw new RefusedException(_m('Write the expiry date as YYYY-MM-DD.'));
        }
        if ($at <= $now) {
            throw new RefusedException(_m('The expiry date has to be in the future.'));
        }

        return $at;
    }

    /**
     * @throws RefusedException
     */
    private function manageable(int $id): StoredKey
    {
        $key = $id > 0 ? $this->store->find($id) : null;
        if ($key === null || !in_array($key->kind(), [CredentialKind::KEY, CredentialKind::PUBLIC], true)) {
            throw new NotFoundException(_m('That key is not in the list any more.'));
        }

        return $key;
    }

    /**
     * @return StoredKey[]
     */
    private function liveOwnedBy(int $adminId): array
    {
        if ($adminId < 1) {
            return [];
        }

        return array_values(array_filter(
            $this->store->listBy(null, null, $adminId, true),
            static fn (StoredKey $k): bool => in_array($k->kind(), [CredentialKind::KEY, CredentialKind::PUBLIC], true)
        ));
    }

    /**
     * The distinct owner ids of these keys.
     *
     * @param StoredKey[]               $keys
     * @param callable(StoredKey): ?int $ownerId
     *
     * @return int[]
     */
    private static function ownerIds(array $keys, callable $ownerId): array
    {
        return array_values(array_unique(array_filter(array_map($ownerId, $keys))));
    }
}
