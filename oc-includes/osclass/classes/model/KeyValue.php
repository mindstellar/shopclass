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

use mindstellar\base\Model;
use mindstellar\database\Db;
use mindstellar\database\UtcDatetime;

/**
 * The shared key-value store in t_key_value: small values core and plugins keep by group and key,
 * optionally with an expiry and a state.
 *
 * Values are stored as given (a string or null); osc_kv_get()/osc_kv_set() add JSON on top.
 * A row whose dt_expires has passed reads as absent and is removed by the daily cron.
 * t_key_value has no user column, so personal data export and erasure do not reach it: a caller
 * that stores something about a person must export and erase it itself.
 *
 * Group: 1-64 of a-z, 0-9, `_`, `.`, `-`, starting with a letter or digit.
 * Key: 1-191 characters of valid UTF-8, no control characters, no leading or trailing
 * space; compared byte for byte, so `A` and `a` are different keys.
 * State: null, or 1-16 of a-z, 0-9, `_`, `.`, `-`.
 * Dates are UTC, so a lock or expiry cannot jump on a DST change or a new site time zone.
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      7.0.0
 */
final class KeyValue extends Model
{
    public const TABLE = 't_key_value';

    public const MAX_GROUP = 64;
    public const MAX_KEY   = 191;
    public const MAX_STATE = 16;

    /** MEDIUMTEXT's limit, in bytes. */
    public const MAX_VALUE = 16777215;

    /**
     * The live row under a key.
     *
     * @return array{value:?string, state:?string, created:int, updated:?int, expires:?int}|null
     *         null when there is none, or it has expired
     * @throws \InvalidArgumentException on a malformed group or key
     * @throws \mindstellar\database\DbException
     */
    public function get(string $group, string $key, ?int $now = null): ?array
    {
        self::check($group, $key);
        $row = $this->table()->where('s_group', $group)->where('s_key', $key)->first();
        if ($row === null) {
            return null;
        }
        $expires = self::time($row['dt_expires']);
        if ($expires !== null && $expires <= ($now ?? time())) {
            return null;
        }

        return [
            'value'   => $row['s_value'] === null ? null : (string) $row['s_value'],
            'state'   => $row['s_state'] === null ? null : (string) $row['s_state'],
            'created' => (int) self::time($row['dt_created']),
            'updated' => self::time($row['dt_updated']),
            'expires' => $expires,
        ];
    }

    /**
     * The live rows of a group, keyed by key in key order, as get() shapes each.
     *
     * @param int $limit at most this many rows
     *
     * @return array<string,array{value:?string, state:?string, created:int, updated:?int, expires:?int}>
     * @throws \InvalidArgumentException on a malformed group
     * @throws \mindstellar\database\DbException
     */
    public function group(string $group, int $limit = 1000, ?int $now = null): array
    {
        self::check($group, 'any');
        $now  = self::datetime($now ?? time());
        $rows = $this->table()->where('s_group', $group)
            ->whereGroup(static fn ($q) => $q->whereNull('dt_expires')->orWhere('dt_expires', '>', $now))
            ->orderBy('s_key')
            ->limit(max(1, $limit))
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['s_key']] = [
                'value'   => $row['s_value'] === null ? null : (string) $row['s_value'],
                'state'   => $row['s_state'] === null ? null : (string) $row['s_state'],
                'created' => (int) self::time($row['dt_created']),
                'updated' => self::time($row['dt_updated']),
                'expires' => self::time($row['dt_expires']),
            ];
        }

        return $out;
    }

    /**
     * Write a key, replacing whatever was there (its value, state and expiry).
     *
     * @param int|null $expiresAt Unix time the key expires; null keeps it until deleted
     *
     * @throws \InvalidArgumentException on a malformed group, key, state or value
     * @throws \mindstellar\database\DbException
     */
    public function set(string $group, string $key, ?string $value, ?int $expiresAt = null, ?string $state = null, ?int $now = null): void
    {
        self::check($group, $key, $state, $value);
        $now     = self::datetime($now ?? time());
        $expires = $expiresAt === null ? null : self::datetime($expiresAt);
        Db::execute(
            'INSERT INTO ' . DB_TABLE_PREFIX . self::TABLE . ' (s_group, s_key, s_value, s_state, dt_created, dt_updated, dt_expires)'
            . ' VALUES (?, ?, ?, ?, ?, NULL, ?)'
            . ' ON DUPLICATE KEY UPDATE s_value = ?, s_state = ?, dt_updated = ?, dt_expires = ?',
            [$group, $key, $value, $state, $now, $expires, $value, $state, $now, $expires]
        );
    }

    /**
     * Replace the value and state of an existing key, keeping its expiry.
     *
     * @param string|null $onlyState change it only while its state is this
     * @param string|null $onlyValue change it only while its value is this
     *
     * @return int rows changed: 0 when there is no such key, or it is in another state or value
     * @throws \InvalidArgumentException on a malformed group, key, state or value
     * @throws \mindstellar\database\DbException
     */
    public function update(
        string $group,
        string $key,
        ?string $value,
        ?string $state,
        ?string $onlyState = null,
        ?int $now = null,
        ?string $onlyValue = null
    ): int {
        self::check($group, $key, $state, $value);
        $query = $this->table()->where('s_group', $group)->where('s_key', $key);
        if ($onlyState !== null) {
            $query = $query->where('s_state', $onlyState);
        }
        if ($onlyValue !== null) {
            $query = $query->where('s_value', $onlyValue);
        }

        return $query->update(['s_value' => $value, 's_state' => $state, 'dt_updated' => self::datetime($now ?? time())]);
    }

    /**
     * Move a live key's expiry.
     *
     * @param int|null $expiresAt Unix time; null keeps it until deleted
     *
     * @return int rows changed: 0 when there is no live key
     * @throws \InvalidArgumentException on a malformed group or key
     * @throws \mindstellar\database\DbException
     */
    public function touch(string $group, string $key, ?int $expiresAt, ?int $now = null): int
    {
        self::check($group, $key);
        $now = self::datetime($now ?? time());

        return $this->table()->where('s_group', $group)->where('s_key', $key)
            ->whereGroup(static fn ($q) => $q->whereNull('dt_expires')->orWhere('dt_expires', '>', $now))
            ->update(['dt_expires' => $expiresAt === null ? null : self::datetime($expiresAt), 'dt_updated' => $now]);
    }

    /**
     * @param string|null $onlyState delete it only while its state is this
     * @param string|null $onlyValue delete it only while its value is this
     *
     * @return int rows removed
     * @throws \InvalidArgumentException on a malformed group or key
     * @throws \mindstellar\database\DbException
     */
    public function delete(string $group, string $key, ?string $onlyState = null, ?string $onlyValue = null): int
    {
        self::check($group, $key);
        $query = $this->table()->where('s_group', $group)->where('s_key', $key);
        if ($onlyState !== null) {
            $query = $query->where('s_state', $onlyState);
        }
        if ($onlyValue !== null) {
            $query = $query->where('s_value', $onlyValue);
        }

        return $query->delete();
    }

    /**
     * Remove every key of a group, such as a plugin's on uninstall.
     *
     * @return int rows removed
     * @throws \InvalidArgumentException on a malformed group
     * @throws \mindstellar\database\DbException
     */
    public function deleteGroup(string $group): int
    {
        self::check($group, 'any');

        return $this->table()->where('s_group', $group)->delete();
    }

    /**
     * Take a key for this caller: write it when absent, or take over a row that has
     * expired. Of several callers racing for one key, exactly one gets true.
     *
     * A new key costs one query; a held key two.
     *
     * @param int         $expiresAt   Unix time the claim ends; must be after $now
     * @param int|null    $staleBefore also take over a row in $state created before this
     *                                 Unix time, such as a lock whose holder died
     *
     * @return bool true when this caller now owns the key
     * @throws \InvalidArgumentException on a malformed group, key, state or value, or an expiry not after now
     * @throws \mindstellar\database\DbException
     */
    public function claim(
        string $group,
        string $key,
        int $expiresAt,
        string $state = 'locked',
        ?string $value = null,
        ?int $now = null,
        ?int $staleBefore = null
    ): bool {
        self::check($group, $key, $state, $value);
        $now = $now ?? time();
        if ($expiresAt <= $now) {
            throw new \InvalidArgumentException('A claim must expire after now.');
        }
        $created = self::datetime($now);
        $expires = self::datetime($expiresAt);
        $table   = DB_TABLE_PREFIX . self::TABLE;

        // A clash updates nothing, so it reports 0 rows and an insert reports 1.
        $inserted = Db::execute(
            'INSERT INTO ' . $table . ' (s_group, s_key, s_value, s_state, dt_created, dt_updated, dt_expires)'
            . ' VALUES (?, ?, ?, ?, ?, NULL, ?) ON DUPLICATE KEY UPDATE s_group = s_group',
            [$group, $key, $value, $state, $created, $expires]
        );
        if ($inserted === 1) {
            return true;
        }

        // The condition is checked on the locked current row, so only one caller matches it.
        $sql    = 'UPDATE ' . $table . ' SET s_value = ?, s_state = ?, dt_created = ?, dt_updated = NULL, dt_expires = ?'
            . ' WHERE s_group = ? AND s_key = ? AND ((dt_expires IS NOT NULL AND dt_expires <= ?)';
        $params = [$value, $state, $created, $expires, $group, $key, $created];
        if ($staleBefore !== null) {
            $sql     .= ' OR (s_state = ? AND dt_created < ?)';
            $params[] = $state;
            $params[] = self::datetime($staleBefore);
        }

        return Db::execute($sql . ')', $params) === 1;
    }

    /**
     * Remove expired rows of every group, $batch at a time so no single delete holds the
     * table for long.
     *
     * @param int $maxRounds stop after this many batches; the next run carries on
     *
     * @return int rows removed
     * @throws \mindstellar\database\DbException
     */
    public function prune(?int $now = null, int $batch = 1000, int $maxRounds = 100): int
    {
        $sql = 'DELETE FROM ' . DB_TABLE_PREFIX . self::TABLE . ' WHERE dt_expires <= ? LIMIT ' . max(1, $batch);
        $now = self::datetime($now ?? time());
        $removed = 0;
        for ($round = 0; $round < $maxRounds; $round++) {
            $rows     = Db::execute($sql, [$now]);
            $removed += $rows;
            if ($rows < $batch) {
                break;
            }
        }

        return $removed;
    }

    /**
     * @throws \InvalidArgumentException with what is wrong
     */
    public static function check(string $group, string $key, ?string $state = null, ?string $value = null): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.\-]{0,' . (self::MAX_GROUP - 1) . '}$/D', $group) !== 1) {
            throw new \InvalidArgumentException('A key-value group is 1 to ' . self::MAX_GROUP . ' of a-z, 0-9, _ . - and starts with a letter or digit.');
        }
        if (
            $key === '' || !mb_check_encoding($key, 'UTF-8') || mb_strlen($key, 'UTF-8') > self::MAX_KEY
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1 || trim($key, ' ') !== $key
        ) {
            throw new \InvalidArgumentException('A key-value key is 1 to ' . self::MAX_KEY . ' characters of UTF-8, with no control characters or surrounding spaces.');
        }
        if ($state !== null && preg_match('/^[a-z0-9_.\-]{1,' . self::MAX_STATE . '}$/D', $state) !== 1) {
            throw new \InvalidArgumentException('A key-value state is 1 to ' . self::MAX_STATE . ' of a-z, 0-9, _ . -.');
        }
        if ($value !== null && (strlen($value) > self::MAX_VALUE || !mb_check_encoding($value, 'UTF-8'))) {
            throw new \InvalidArgumentException('A key-value value is valid UTF-8 of at most ' . self::MAX_VALUE . ' bytes.');
        }
    }

    private static function time(mixed $datetime): ?int
    {
        return UtcDatetime::parse($datetime);
    }
}
