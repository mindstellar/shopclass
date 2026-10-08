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

namespace mindstellar\model;

use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\SignInStore;
use mindstellar\apiaccess\StoredKey;
use mindstellar\base\Model;
use mindstellar\database\Db;
use mindstellar\database\UtcDatetime;

/**
 * REST API credentials in t_api_credential: API keys, public keys and refresh tokens.
 *
 * Every read joins the owner in, so a key and whether its admin or user may still use it
 * come back in one query. Dates are UTC, so an expiry cannot jump on a DST change or a new
 * site time zone.
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      7.0.0
 */
final class ApiCredential extends Model implements SignInStore
{
    protected const TABLE = 't_api_credential';

    public function findByTokenId(string $tokenId): ?StoredKey
    {
        return $this->select('c.s_token_id = ?', [$tokenId])[0] ?? null;
    }

    public function find(int $id): ?StoredKey
    {
        return $this->select('c.pk_i_id = ?', [$id])[0] ?? null;
    }

    /**
     * @throws \InvalidArgumentException on an unknown kind or a key with no owner
     */
    public function insert(StoredKey $key): int
    {
        if (!in_array($key->kind(), CredentialKind::STORED, true)) {
            throw new \InvalidArgumentException('Unknown credential kind.');
        }
        $owner = $key->owner();
        if ($owner === null) {
            throw new \InvalidArgumentException('A credential belongs to an admin or a user.');
        }
        return $this->table()->insert([
            'e_kind'        => $key->kind(),
            's_token_id'    => $key->tokenId(),
            's_secret_hash' => $key->secretHash(),
            's_name'        => $key->name(),
            's_scopes'      => implode(' ', $key->scopes()),
            'fk_i_admin_id' => $owner->adminId(),
            'fk_i_user_id'  => $owner->userId(),
            's_family'      => $key->family(),
            'i_rate_limit'  => $key->rateLimit(),
            'b_enabled'     => $key->enabled() ? 1 : 0,
            'dt_expires'    => self::datetime($key->expiresAt()),
            'dt_created'    => self::datetime($key->createdAt() ?? time()),
            'i_auth_stamp'  => $key->authStamp() ?? $owner->stamp() ?? $this->stampOf($owner),
        ]);
    }

    public function touch(int $id, string $ip, int $time): void
    {
        $this->table()->where('pk_i_id', $id)->update(['dt_last_used' => self::datetime($time), 's_last_ip' => $ip]);
    }

    public function revoke(int $id): bool
    {
        return $this->table()->where('pk_i_id', $id)->whereNull('dt_revoked')
            ->update(['b_enabled' => 0, 'dt_revoked' => self::datetime(time())]) > 0;
    }

    /**
     * Revoke every refresh token of one rotation family.
     *
     * @return int rows changed
     */
    public function revokeFamily(string $family): int
    {
        return $this->table()->where('s_family', $family)->whereNull('dt_revoked')
            ->update(['b_enabled' => 0, 'dt_revoked' => self::datetime(time())]);
    }

    public function revokeRefreshFor(int $userId, ?string $keepFamily = null): int
    {
        $query = $this->table()->where('e_kind', CredentialKind::REFRESH)->where('fk_i_user_id', $userId)->whereNull('dt_revoked');
        if ($keepFamily !== null) {
            $query = $query->where('s_family', '!=', $keepFamily);
        }

        return $query->update(['b_enabled' => 0, 'dt_revoked' => self::datetime(time())]);
    }

    /**
     * Run $fn in one transaction; it commits when $fn returns and rolls back when it throws.
     *
     * @template T
     * @param callable(): T $fn
     *
     * @return T
     */
    public function atomically(callable $fn): mixed
    {
        return Db::transaction($fn);
    }

    public function lockFamily(string $family): void
    {
        Db::select('SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . self::TABLE . ' WHERE s_family = ? FOR UPDATE', [$family]);
    }

    /**
     * Drop refresh tokens that can no longer be used: revoked or expired before $before.
     * A superseded token is kept until then, so presenting it still revokes its family.
     *
     * @param int $batch rows per DELETE
     *
     * @return int rows removed
     */
    public function pruneRefresh(int $before, int $batch = 1000): int
    {
        $at      = self::datetime($before);
        $removed = 0;
        // One column per statement, so each uses its (e_kind, column) index; small batches keep locks short.
        foreach (['dt_revoked', 'dt_expires'] as $column) {
            do {
                $n = Db::execute(
                    'DELETE FROM ' . DB_TABLE_PREFIX . self::TABLE . " WHERE e_kind = 'refresh' AND " . $column . ' < ? ORDER BY ' . $column . ' LIMIT ' . max(1, $batch),
                    [$at]
                );
                $removed += $n;
            } while ($n >= $batch);
        }

        return $removed;
    }

    /**
     * @return int rows removed
     */
    public function delete(int $id): int
    {
        return $this->table()->where('pk_i_id', $id)->delete();
    }

    /**
     * Credentials, newest first, optionally only one kind and one owner, optionally only
     * those not revoked.
     *
     * @return StoredKey[]
     */
    public function listBy(?string $kind = null, ?int $userId = null, ?int $adminId = null, bool $liveOnly = false): array
    {
        $where  = $liveOnly ? ['c.dt_revoked IS NULL'] : ['1 = 1'];
        $params = [];
        foreach (['c.e_kind' => $kind, 'c.fk_i_user_id' => $userId, 'c.fk_i_admin_id' => $adminId] as $column => $value) {
            if ($value !== null) {
                $where[]  = $column . ' = ?';
                $params[] = $value;
            }
        }

        return $this->select(implode(' AND ', $where), $params, 'ORDER BY c.pk_i_id DESC');
    }

    public function hasLiveFor(int $userId): bool
    {
        return Db::selectOne(
            'SELECT 1 FROM ' . DB_TABLE_PREFIX . self::TABLE . ' WHERE fk_i_user_id = ? AND dt_revoked IS NULL AND e_kind IN (?, ?) LIMIT 1',
            [$userId, CredentialKind::REFRESH, CredentialKind::KEY]
        ) !== null;
    }

    public function familyIsLive(string $family): bool
    {
        return Db::selectOne(
            'SELECT 1 FROM ' . DB_TABLE_PREFIX . self::TABLE . ' WHERE s_family = ? AND dt_revoked IS NULL LIMIT 1',
            [$family]
        ) !== null;
    }

    /**
     * The owner's sign-out stamp now, for a credential issued without one.
     */
    private function stampOf(KeyOwner $owner): ?int
    {
        $table = $owner->isAdmin() ? 't_admin' : 't_user';
        $stamp = Db::scalar('SELECT i_auth_stamp FROM ' . DB_TABLE_PREFIX . $table . ' WHERE pk_i_id = ?', [$owner->adminId() ?? $owner->userId()]);

        return $stamp === null || $stamp === false ? null : (int) $stamp;
    }

    /**
     * Credentials matching a fixed condition, owners joined in.
     *
     * @param string            $where literal SQL with ? placeholders only
     * @param array<int,mixed>  $params
     *
     * @return StoredKey[]
     */
    private function select(string $where, array $params, string $order = ''): array
    {
        $p    = DB_TABLE_PREFIX;
        $rows = Db::select(
            'SELECT c.*, a.pk_i_id AS owner_admin, a.b_moderator AS owner_moderator, a.i_auth_stamp AS owner_admin_stamp,'
            . ' u.pk_i_id AS owner_user, u.b_enabled AS owner_enabled, u.b_active AS owner_active, u.i_auth_stamp AS owner_user_stamp'
            . ' FROM ' . $p . self::TABLE . ' c'
            . ' LEFT JOIN ' . $p . 't_admin a ON a.pk_i_id = c.fk_i_admin_id'
            . ' LEFT JOIN ' . $p . 't_user u ON u.pk_i_id = c.fk_i_user_id'
            . ' WHERE ' . $where . ($order === '' ? '' : ' ' . $order),
            $params
        );

        return array_map(static fn (array $row): StoredKey => self::toKey($row), $rows);
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function toKey(array $row): StoredKey
    {
        $owner = null;
        if ($row['fk_i_admin_id'] !== null) {
            $owner = $row['owner_admin'] === null ? null : KeyOwner::admin((int) $row['owner_admin'], (int) $row['owner_moderator'] === 1, (int) $row['owner_admin_stamp']);
        } elseif ($row['fk_i_user_id'] !== null && (int) $row['owner_enabled'] === 1 && (int) $row['owner_active'] === 1) {
            $owner = KeyOwner::user((int) $row['owner_user'], (int) $row['owner_user_stamp']);
        }

        return new StoredKey(
            id: (int) $row['pk_i_id'],
            kind: (string) $row['e_kind'],
            tokenId: (string) $row['s_token_id'],
            secretHash: (string) $row['s_secret_hash'],
            name: (string) $row['s_name'],
            scopes: preg_split('/\s+/', trim((string) $row['s_scopes']), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            owner: $owner,
            rateLimit: $row['i_rate_limit'] === null ? null : (int) $row['i_rate_limit'],
            enabled: (int) $row['b_enabled'] === 1,
            expiresAt: self::timestamp($row['dt_expires']),
            revokedAt: self::timestamp($row['dt_revoked']),
            lastUsedAt: self::timestamp($row['dt_last_used']),
            family: $row['s_family'] === null ? null : (string) $row['s_family'],
            createdAt: self::timestamp($row['dt_created']),
            lastIp: (string) $row['s_last_ip'],
            authStamp: isset($row['i_auth_stamp']) ? (int) $row['i_auth_stamp'] : null
        );
    }

    private static function timestamp(mixed $value): ?int
    {
        return UtcDatetime::parse($value);
    }
}
