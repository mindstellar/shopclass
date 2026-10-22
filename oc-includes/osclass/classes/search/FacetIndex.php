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

namespace mindstellar\search;

use Field;
use mindstellar\database\Connection;
use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\fields\FieldTypeRegistry;
use mindstellar\listing\ListingStore;

/**
 * t_item_facet: one row per filterable value of a listing (custom field values and variant
 * options), kept in step with t_item_meta and t_item_variant by their writers. A row holds the
 * id of its (key, value) term in t_facet_term; a number row uses the term (key, '') and keeps
 * the number in d_number, a text row has d_number 0. Every method that writes expects the
 * listing row to be locked by the caller. A writer's call that fails marks the index stale and
 * queues a rebuild instead of failing the listing write.
 */
final class FacetIndex
{
    public const TABLE      = 't_item_facet';
    public const TERM_TABLE = 't_facet_term';
    public const JOB_BUILD  = 'search.facet_build';
    public const JOB_FIELD  = 'search.facet_field';
    public const JOB_PACK   = 'search.facet_pack';
    public const BATCH      = 500;
    public const VALUE_LEN  = 191;
    public const PREF_READY = 'facet_ready';
    public const PREF_BUILT = 'facet_built_at';

    private const KEY_LEN = 48;
    private const ROWS_PER_INSERT = 500;
    private const TERMS_PER_SELECT = 200;
    private const PRUNE_RANGE = 2000;
    private const ER_NO_REFERENCED_ROW = 1452;
    private const PACK_LOCK_WAIT = 2;
    private const PACK_WEB_MAX_BYTES = 128 * 1024 * 1024;

    private static bool $tablePresent = false;

    /** @var array<int,string> storage primitive by field id, for this request */
    private static array $storages = [];

    private static float $storagesRequest = 0.0;

    /** @var array<string,array<int|string,int>> committed term ids by key and cut value, for this request */
    private static array $terms = [];

    /** @var array<int,true> term ids made by a transaction of this request that has not committed yet */
    private static array $created = [];

    /** @var array<int,true> term ids another transaction committed after the open one's snapshot */
    private static array $unseen = [];

    public static function tableName(): string
    {
        return DB_TABLE_PREFIX . self::TABLE;
    }

    public static function termTable(): string
    {
        return DB_TABLE_PREFIX . self::TERM_TABLE;
    }

    /**
     * The key of a custom field row: f12, or f12.from / f12.to for a date interval.
     */
    public static function key(int $fieldId, string $multi = ''): string
    {
        return 'f' . $fieldId . ($multi !== '' ? '.' . $multi : '');
    }

    /**
     * The term key of an option name. A name too long for the column keeps its start and ends
     * in a hash of the whole name, so two long names with one start stay apart.
     */
    public static function optionKey(string $name): string
    {
        $room = self::KEY_LEN - 1;
        if (mb_strlen($name) <= $room) {
            return 'o' . $name;
        }

        return 'o' . mb_substr($name, 0, $room - 13) . '~' . substr(md5(mb_strtolower($name)), 0, 12);
    }

    public static function cut(string $value): string
    {
        return mb_substr(trim($value), 0, self::VALUE_LEN);
    }

    /**
     * How a field of this storage is indexed: '' (not at all), 'text', 'flag', 'number' or
     * 'interval'. Two storages of the same kind give the same rows.
     */
    public static function rowKind(string $storage): string
    {
        return [
            'DROPDOWN'     => 'text',
            'RADIO'        => 'text',
            'CHECKBOX'     => 'flag',
            'NUMBER'       => 'number',
            'DATE'         => 'number',
            'DATEINTERVAL' => 'interval',
        ][$storage] ?? '';
    }

    /**
     * The rowKind() of a field's storage. Reads the request's map first and the field on a miss;
     * $fresh always reads the field, for a long-running worker.
     */
    public static function fieldKind(int $fieldId, bool $fresh = false): string
    {
        if ($fresh) {
            self::forgetTypes();
        }

        return self::rowKind(self::storageOfField($fieldId));
    }

    /**
     * Remember the storage of fields already read, so a write to them looks nothing up.
     *
     * @param array<int,array<string,mixed>> $fieldRows rows with pk_i_id and e_type
     */
    public static function rememberTypes(array $fieldRows): void
    {
        self::sameRequest();
        foreach ($fieldRows as $row) {
            if (isset($row['pk_i_id'], $row['e_type'])) {
                self::$storages[(int) $row['pk_i_id']] = self::storageOfType((string) $row['e_type']);
            }
        }
    }

    /**
     * Drop the remembered storages and term ids. A long-running worker keeps statics between
     * requests, so both are also dropped when the request time moves.
     */
    public static function forgetTypes(): void
    {
        self::$storages = [];
        self::$terms    = [];
    }

    /**
     * Outside a transaction no term is waiting for a commit, so a rollback left these ids behind.
     */
    private static function settle(): void
    {
        if ((self::$created !== [] || self::$unseen !== []) && !Db::inTransaction()) {
            self::$created = [];
            self::$unseen  = [];
        }
    }

    private static function sameRequest(): void
    {
        $now = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? 0.0);
        if ($now !== self::$storagesRequest) {
            self::$storagesRequest = $now;
            self::forgetTypes();
        }
    }

    public static function number(string $value): ?float
    {
        $value = trim($value);
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    /**
     * The term ids of the pairs that have one. Values are cut as rows are written and compared
     * under the table's collation, so 'red' finds the term of 'Red'. Reads only.
     *
     * @param array<string,array<int,int|string>> $pairs key => values (a number key asks for '')
     *
     * @return array<string,array<int|string,int>> key => value as asked => term id; unknown pairs left out
     * @throws DbException
     */
    public static function termIds(array $pairs): array
    {
        self::sameRequest();
        self::settle();
        $found  = [];
        $misses = [];
        foreach ($pairs as $key => $values) {
            foreach ($values as $value) {
                $cut = self::cut((string) $value);
                if (isset(self::$terms[$key][$cut])) {
                    $found[$key][$value] = self::$terms[$key][$cut];
                } else {
                    $misses[$key . "\0" . $cut] = [(string) $key, $cut];
                }
            }
        }
        if ($misses === []) {
            return $found;
        }
        $ids = self::lookup(array_values($misses), false);
        foreach ($ids as [$key, $cut, $id]) {
            if (!isset(self::$created[$id])) {
                self::$terms[$key][$cut] = $id;
            }
        }

        return self::fill($found, $pairs, $ids);
    }

    /**
     * As termIds(), creating the terms that are missing. A term another transaction made after
     * this one's snapshot is returned too; a row naming it is written after the commit instead.
     *
     * @param array<string,array<int,int|string>> $pairs key => values
     *
     * @return array<string,array<int|string,int>> key => value as asked => term id
     * @throws DbException
     */
    public static function ensureTerms(array $pairs): array
    {
        $found   = self::termIds($pairs);
        $missing = [];
        foreach ($pairs as $key => $values) {
            foreach ($values as $value) {
                if (!isset($found[$key][$value])) {
                    $cut = self::cut((string) $value);
                    $missing[$key . "\0" . $cut] = [(string) $key, $cut];
                }
            }
        }
        if ($missing === []) {
            return $found;
        }
        // Sorted, so two writers making the same terms lock them in one order.
        ksort($missing, SORT_STRING);
        $missing = array_values($missing);
        foreach (array_chunk($missing, self::TERMS_PER_SELECT) as $chunk) {
            Db::execute(
                'INSERT IGNORE INTO ' . self::termTable() . ' (s_key, s_value) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?)')),
                array_merge(...$chunk)
            );
        }
        // A plain read in a snapshot opened earlier would not see a term another writer just made.
        $ids = self::lookup($missing, true);
        if ($ids === []) {
            return $found;
        }
        // This transaction's own terms show in its snapshot; one made by another writer since does not.
        $seen = array_flip(array_map('intval', array_column(Db::select(
            'SELECT pk_i_id FROM ' . self::termTable() . ' WHERE pk_i_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            array_column($ids, 2)
        ), 'pk_i_id')));
        foreach ($ids as [, , $id]) {
            if (isset($seen[$id])) {
                self::$created[$id] = true;
            } else {
                self::$unseen[$id] = true;
            }
        }
        Db::afterCommit(static function () use ($ids): void {
            foreach ($ids as [$key, $cut, $id]) {
                self::$terms[$key][$cut] = $id;
                unset(self::$created[$id], self::$unseen[$id]);
            }
        });

        return self::fill($found, $pairs, $ids);
    }

    /**
     * Write the option rows of new variants.
     *
     * @param array<int,array<string,mixed>> $rows t_item_variant rows: pk_i_id and s_options
     *
     * @throws DbException only when the caller's transaction was lost
     */
    public static function insertVariants(int $itemId, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        self::guard(static fn () => self::insert($itemId, self::variantRows($rows)));
    }

    /**
     * Remove the option rows of these variants, or of every variant of the listing when null.
     *
     * @param int[]|null $variantIds
     *
     * @throws DbException only when the caller's transaction was lost
     */
    public static function dropVariants(int $itemId, ?array $variantIds = null): void
    {
        if ($variantIds === []) {
            return;
        }
        self::guard(static fn () => self::deleteVariants($itemId, $variantIds));
    }

    /**
     * Replace the rows one listing has for one custom field.
     *
     * @param string|array<string,mixed>|null $value   as Field::replace() gets it
     * @param string|null                     $storage the field's storage primitive, looked up when null
     *
     * @throws DbException only when the caller's transaction was lost
     */
    public static function rewriteField(int $itemId, int $fieldId, $value, ?string $storage = null): void
    {
        self::guard(static function () use ($itemId, $fieldId, $value, $storage): void {
            $storage ??= self::storageOfField($fieldId);
            $keys = self::fieldKeys($fieldId);
            if (self::rowKind($storage) === 'interval' && is_array($value)) {
                // REPLACE leaves an end that was not passed in t_item_meta, so its row stays too.
                $ends = array_values(array_intersect(['from', 'to'], array_map('strval', array_keys($value))));
                $keys = array_map(static fn (string $end): string => self::key($fieldId, $end), $ends);
            }
            self::deleteKeys($itemId, $keys);
            self::insert($itemId, self::fieldRows($fieldId, $storage, $value));
        });
    }

    /**
     * Remove a field's rows from every listing, with its terms.
     *
     * @throws DbException only when the caller's transaction was lost
     */
    public static function dropField(int $fieldId): void
    {
        $keys = self::fieldKeys($fieldId);
        self::guard(static fn () => Db::execute('DELETE FROM ' . self::termTable() . ' WHERE s_key IN (?, ?, ?)', $keys));
        foreach ($keys as $key) {
            unset(self::$terms[$key]);
        }
    }

    /**
     * Delete the terms no row names, as a rebuild leaves them. Only a rebuild calls this; a term
     * stays after its last listing changes, and no list reads the terms, so it costs only space.
     * It deletes in id ranges, so no statement holds locks for long.
     *
     * @return int the terms deleted
     * @throws DbException
     */
    public static function pruneTerms(): int
    {
        self::$terms = [];
        $max         = (int) Db::scalar('SELECT MAX(pk_i_id) FROM ' . self::termTable());
        $deleted     = 0;
        for ($from = 0; $from < $max; $from += self::PRUNE_RANGE) {
            $deleted += Db::execute(
                'DELETE t FROM ' . self::termTable() . ' t LEFT JOIN ' . self::tableName() . ' f ON f.fk_i_term_id = t.pk_i_id'
                . ' WHERE t.pk_i_id > ? AND t.pk_i_id <= ? AND f.fk_i_term_id IS NULL',
                [$from, $from + self::PRUNE_RANGE]
            );
        }

        return $deleted;
    }

    /**
     * Rewrite the table with full pages, as a full build ends. The build writes listing by
     * listing into a primary key that starts with the term, which leaves pages half full. The
     * rebuild is online, so searches and saves go on; it waits at most PACK_LOCK_WAIT seconds
     * for a lock. Skipped inside a transaction, which the statement would commit.
     *
     * @return bool true when the table was rewritten, false inside a transaction
     * @throws DbException with the server's reason when the rewrite fails, e.g. on a lock wait, a full online log or a full disk
     */
    public static function pack(): bool
    {
        if (Db::inTransaction()) {
            return false;
        }
        $wait = (int) Db::scalar('SELECT @@SESSION.lock_wait_timeout');
        Db::execute('SET SESSION lock_wait_timeout = ' . self::PACK_LOCK_WAIT);
        try {
            Db::execute('ALTER TABLE ' . self::tableName() . ' FORCE, ALGORITHM=INPLACE, LOCK=NONE');
        } catch (DbException $e) {
            // The server's text gives the reason, e.g. a lock wait, a full online log or a rebuild that needs a lock.
            throw new DbException(Connection::getInstance()->lastError() ?: $e->getMessage(), $e->getCode(), $e);
        } finally {
            Db::execute('SET SESSION lock_wait_timeout = ' . $wait);
        }

        return true;
    }

    /**
     * Whether a background pack may run: always from the command line, from a web request
     * only while the table is small enough to rewrite well inside a request's time limit.
     */
    public static function packFits(string $sapi, int $bytes): bool
    {
        return $sapi === 'cli' || $bytes <= self::PACK_WEB_MAX_BYTES;
    }

    /**
     * The table's data and index size in bytes, as the server last counted it.
     *
     * @throws DbException
     */
    public static function tableBytes(): int
    {
        return (int) Db::scalar(
            'SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::tableName()]
        );
    }

    /**
     * Rebuild one listing from t_item_meta and t_item_variant.
     *
     * @throws DbException
     */
    public static function rewriteItem(int $itemId): void
    {
        self::rewriteScoped($itemId, null);
    }

    /**
     * Rebuild the next listings after $after, or only one field's rows of them. Each listing is
     * its own transaction, so a save waits for one listing at most, never the whole batch.
     *
     * @return int|null the last listing id done, or null when the batch was short
     * @throws DbException
     */
    public static function buildStep(int $after, int $limit, ?int $fieldId = null): ?int
    {
        self::forgetTypes();
        $ids = array_map('intval', array_column(Db::select(
            'SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id > ? ORDER BY pk_i_id LIMIT ' . max(1, $limit),
            [$after]
        ), 'pk_i_id'));
        if ($ids === []) {
            return null;
        }
        foreach ($ids as $id) {
            self::rebuildLocked($id, $fieldId);
        }

        return count($ids) < $limit ? null : (int) end($ids);
    }

    public static function ready(): bool
    {
        if ((string) osc_get_preference(self::PREF_READY, 'osclass') !== '1') {
            return false;
        }
        if (!self::$tablePresent) {
            self::$tablePresent = self::tableExists();
        }

        return self::$tablePresent;
    }

    public static function markReady(): void
    {
        osc_set_preference(self::PREF_READY, '1', 'osclass', 'BOOLEAN');
        osc_set_preference(self::PREF_BUILT, gmdate('Y-m-d H:i:s'), 'osclass', 'STRING');
    }

    public static function markStale(): void
    {
        osc_set_preference(self::PREF_READY, '0', 'osclass', 'BOOLEAN');
    }

    public static function tableExists(): bool
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::tableName()]
        ) > 0;
    }

    /**
     * @return string[] the three keys a field's rows can have
     */
    private static function fieldKeys(int $fieldId): array
    {
        return [self::key($fieldId), self::key($fieldId, 'from'), self::key($fieldId, 'to')];
    }

    /**
     * @throws DbException
     */
    private static function rebuildLocked(int $itemId, ?int $fieldId): void
    {
        Db::transaction(static function () use ($itemId, $fieldId): void {
            if (ListingStore::find($itemId, ['pk_i_id'], true) !== null) {
                self::rewriteScoped($itemId, $fieldId);
            }
        });
    }

    /**
     * Rewrite a listing's rows: all of them, or only one field's. Reads lock what they read and
     * come before the index write (variants, meta, then the index, as the writers do), so a
     * build waits for a save in progress and never holds an old snapshot or deadlocks with it.
     *
     * @throws DbException
     */
    private static function rewriteScoped(int $itemId, ?int $fieldId): void
    {
        $rows = [];
        if ($fieldId === null) {
            $variants = Db::select(
                'SELECT pk_i_id, s_options FROM ' . DB_TABLE_PREFIX . 't_item_variant WHERE fk_i_item_id = ? ORDER BY pk_i_id FOR UPDATE',
                [$itemId]
            );
            $rows = self::variantRows($variants);
        }

        $sql    = 'SELECT m.fk_i_field_id, m.s_multi, m.s_value, f.e_type FROM ' . DB_TABLE_PREFIX . 't_item_meta m'
            . ' JOIN ' . DB_TABLE_PREFIX . 't_meta_fields f ON f.pk_i_id = m.fk_i_field_id WHERE m.fk_i_item_id = ?';
        $params = [$itemId];
        if ($fieldId !== null) {
            $sql     .= ' AND m.fk_i_field_id = ?';
            $params[] = $fieldId;
        }
        $fields = [];
        foreach (Db::select($sql . ' ORDER BY m.fk_i_field_id, m.s_multi LOCK IN SHARE MODE', $params) as $meta) {
            $id = (int) $meta['fk_i_field_id'];
            $fields[$id]['storage'] = self::storageOfType((string) $meta['e_type']);
            if ((string) $meta['s_multi'] === '') {
                $fields[$id]['value'] = (string) $meta['s_value'];
            } else {
                $fields[$id]['value'][(string) $meta['s_multi']] = (string) $meta['s_value'];
            }
        }
        foreach ($fields as $id => $field) {
            $rows = array_merge($rows, self::fieldRows($id, $field['storage'], $field['value']));
        }
        if ($fieldId === null) {
            Db::execute('DELETE FROM ' . self::tableName() . ' WHERE fk_i_item_id = ?', [$itemId]);
        } else {
            self::deleteKeys($itemId, self::fieldKeys($fieldId));
        }
        self::insert($itemId, $rows);
    }

    /**
     * Run a writer's change in a savepoint. On failure the main write goes on: the index is
     * marked stale and a rebuild queued. A lost transaction is still thrown.
     *
     * @throws DbException
     */
    private static function guard(callable $write): void
    {
        try {
            Db::transaction($write);
        } catch (DbException $e) {
            Db::assertIntact();
            self::queueRebuild($e);
        }
    }

    private static function queueRebuild(DbException $e): void
    {
        error_log('Listing filter index write failed, rebuild queued: ' . $e->getMessage());
        try {
            self::markStale();
            osc_job_ensure(self::JOB_BUILD, ['after' => 0]);
        } catch (\Throwable $ignored) {
            // The flag stays as it was; db:repair or search:reindex sets it right.
        }
    }

    /**
     * @param int[]|null $variantIds null for every variant of the listing
     *
     * @throws DbException
     */
    private static function deleteVariants(int $itemId, ?array $variantIds): void
    {
        if ($variantIds === null) {
            Db::execute('DELETE FROM ' . self::tableName() . ' WHERE fk_i_item_id = ? AND fk_i_variant_id <> 0', [$itemId]);

            return;
        }
        $variantIds = array_values(array_map('intval', $variantIds));
        Db::execute(
            'DELETE FROM ' . self::tableName() . ' WHERE fk_i_item_id = ? AND fk_i_variant_id IN (' . implode(', ', array_fill(0, count($variantIds), '?')) . ')',
            array_merge([$itemId], $variantIds)
        );
    }

    /**
     * Delete a listing's rows whose term has one of these keys. The join starts from the
     * listing's rows, so it reads only the terms they name.
     *
     * @param string[] $keys
     *
     * @throws DbException
     */
    private static function deleteKeys(int $itemId, array $keys): void
    {
        if ($keys === []) {
            return;
        }
        Db::execute(
            'DELETE f FROM ' . self::tableName() . ' f STRAIGHT_JOIN ' . self::termTable() . ' t ON t.pk_i_id = f.fk_i_term_id'
            . ' WHERE f.fk_i_item_id = ? AND t.s_key IN (' . implode(', ', array_fill(0, count($keys), '?')) . ')',
            array_merge([$itemId], array_values($keys))
        );
    }

    /**
     * Term ids of [key, cut value] pairs, compared under the term table's collation.
     *
     * @param array<int,array{0:string,1:string}> $pairs
     *
     * @return array<int,array{0:string,1:string,2:int}> the asked key and value with the id, for those found
     * @throws DbException
     */
    private static function lookup(array $pairs, bool $lock): array
    {
        $found = [];
        foreach (array_chunk($pairs, self::TERMS_PER_SELECT) as $chunk) {
            $rows = [];
            foreach (array_keys($chunk) as $n) {
                $rows[] = $n === 0 ? 'SELECT 0 AS n, ? AS k, ? AS v' : 'SELECT ' . $n . ', ?, ?';
            }
            $result = Db::select(
                'SELECT q.n, t.pk_i_id FROM (' . implode(' UNION ALL ', $rows) . ') q JOIN ' . self::termTable() . ' t'
                . ' ON t.s_key = q.k AND t.s_value = q.v' . ($lock ? ' LOCK IN SHARE MODE' : ''),
                array_merge(...$chunk)
            );
            foreach ($result as $row) {
                $pair    = $chunk[(int) $row['n']];
                $found[] = [$pair[0], $pair[1], (int) $row['pk_i_id']];
            }
        }

        return $found;
    }

    /**
     * Add the looked-up ids to $found, keyed by each value as it was asked.
     *
     * @param array<string,array<int|string,int>>  $found
     * @param array<string,array<int,int|string>>  $pairs
     * @param array<int,array{0:string,1:string,2:int}> $ids
     *
     * @return array<string,array<int|string,int>>
     */
    private static function fill(array $found, array $pairs, array $ids): array
    {
        $byPair = [];
        foreach ($ids as [$key, $cut, $id]) {
            $byPair[$key . "\0" . $cut] = $id;
        }
        foreach ($pairs as $key => $values) {
            foreach ($values as $value) {
                $id = $byPair[$key . "\0" . self::cut((string) $value)] ?? null;
                if ($id !== null && !isset($found[$key][$value])) {
                    $found[$key][$value] = $id;
                }
            }
        }

        return $found;
    }

    private static function storageOfField(int $fieldId): string
    {
        self::sameRequest();
        if (!isset(self::$storages[$fieldId])) {
            $field = Field::getInstance()->findByPrimaryKey($fieldId);
            if (!isset($field['e_type'])) {
                return self::storageOfType('TEXT');
            }
            self::$storages[$fieldId] = self::storageOfType((string) $field['e_type']);
        }

        return self::$storages[$fieldId];
    }

    private static function storageOfType(string $type): string
    {
        return FieldTypeRegistry::getInstance()->storageOf($type);
    }

    /**
     * @param string|array<string,mixed>|null $value
     *
     * @return array<int,array{0:string,1:string,2:int,3:float}> key, term value, variant, number
     */
    private static function fieldRows(int $fieldId, string $storage, $value): array
    {
        $kind = self::rowKind($storage);
        if ($kind === 'interval') {
            $rows = [];
            foreach (['from', 'to'] as $end) {
                $row = is_array($value) ? self::numberRow(self::key($fieldId, $end), (string) ($value[$end] ?? '')) : null;
                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }
        if (!is_string($value) && !is_numeric($value)) {
            return [];
        }
        $text = trim((string) $value);
        $key  = self::key($fieldId);
        switch ($kind) {
            case 'text':
                return $text === '' ? [] : [[$key, self::cut($text), 0, 0.0]];
            case 'flag':
                return $text === '1' ? [[$key, '1', 0, 0.0]] : [];
            case 'number':
                $row = self::numberRow($key, $text);

                return $row === null ? [] : [$row];
        }

        return [];
    }

    /**
     * @return array{0:string,1:string,2:int,3:float}|null
     */
    private static function numberRow(string $key, string $text): ?array
    {
        $number = self::number($text);

        return $number === null ? null : [$key, '', 0, $number];
    }

    /**
     * @param array<int,array<string,mixed>> $variants
     *
     * @return array<int,array{0:string,1:string,2:int,3:float}>
     */
    private static function variantRows(array $variants): array
    {
        $rows = [];
        foreach ($variants as $variant) {
            $options = json_decode((string) ($variant['s_options'] ?? ''), true);
            if (!is_array($options)) {
                continue;
            }
            foreach ($options as $name => $value) {
                if (!is_scalar($value) || self::cut((string) $value) === '') {
                    continue;
                }
                $rows[] = [self::optionKey((string) $name), self::cut((string) $value), (int) $variant['pk_i_id'], 0.0];
            }
        }

        return $rows;
    }

    /**
     * Resolve the rows' terms and write them. Rows equal under the table's collation (option
     * names Size and SIZE) are written once. A row whose term this transaction cannot see yet
     * is left out, and the listing is rebuilt once the transaction commits. A term a prune or
     * another process deleted since it was cached fails the foreign key; the write is then
     * tried once more with the cache dropped.
     *
     * @param array<int,array{0:string,1:string,2:int,3:float}> $rows key, term value, variant, number
     *
     * @throws DbException
     */
    private static function insert(int $itemId, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        try {
            $later = self::writeRows($itemId, $rows);
        } catch (DbException $e) {
            if ($e->getCode() !== self::ER_NO_REFERENCED_ROW) {
                throw $e;
            }
            Db::assertIntact();
            self::forgetTypes();
            $later = self::writeRows($itemId, $rows);
        }
        if ($later) {
            Db::afterCommit(static function () use ($itemId): void {
                try {
                    self::rebuildLocked($itemId, null);
                } catch (DbException $e) {
                    self::queueRebuild($e);
                }
            });
        }
    }

    /**
     * @param array<int,array{0:string,1:string,2:int,3:float}> $rows
     *
     * @return bool true when a row was left out for a term this transaction cannot see yet
     * @throws DbException
     */
    private static function writeRows(int $itemId, array $rows): bool
    {
        self::settle();
        $pairs = [];
        foreach ($rows as [$key, $value]) {
            $pairs[$key][$value] = $value;
        }
        $ids    = self::ensureTerms(array_map('array_values', $pairs));
        $values = [];
        $later  = false;
        foreach ($rows as [$key, $value, $variant, $number]) {
            $term = $ids[$key][$value] ?? null;
            if ($term === null || isset(self::$unseen[$term])) {
                $later = true;
                continue;
            }
            $values[] = [$term, $number, $itemId, $variant];
        }
        // Not INSERT IGNORE: that would also hide a row naming a term that is gone.
        foreach (array_chunk($values, self::ROWS_PER_INSERT) as $chunk) {
            Db::execute(
                'INSERT INTO ' . self::tableName() . ' (fk_i_term_id, d_number, fk_i_item_id, fk_i_variant_id) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE fk_i_item_id = fk_i_item_id',
                array_merge(...$chunk)
            );
        }

        return $later;
    }
}
