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
use mindstellar\database\Db;
use mindstellar\fields\FieldTypeRegistry;
use mindstellar\listing\ListingStore;

/**
 * t_item_facet: one row per filterable value of a listing (custom field values and variant
 * options), kept in step with t_item_meta and t_item_variant by their writers. Every method
 * that writes expects the listing row to be locked by the caller.
 */
final class FacetIndex
{
    public const TABLE      = 't_item_facet';
    public const JOB_BUILD  = 'search.facet_build';
    public const JOB_FIELD  = 'search.facet_field';
    public const BATCH      = 2000;
    public const VALUE_LEN  = 191;
    public const PREF_READY = 'facet_ready';
    public const PREF_BUILT = 'facet_built_at';

    private const KEY_LEN = 48;
    private const ROWS_PER_INSERT = 500;

    private static bool $tablePresent = false;

    public static function tableName(): string
    {
        return DB_TABLE_PREFIX . self::TABLE;
    }

    /**
     * The key of a custom field row: f12, or f12.from / f12.to for a date interval.
     */
    public static function key(int $fieldId, string $multi = ''): string
    {
        return 'f' . $fieldId . ($multi !== '' ? '.' . $multi : '');
    }

    public static function optionKey(string $name): string
    {
        return 'o' . mb_substr($name, 0, self::KEY_LEN - 1);
    }

    public static function cut(string $value): string
    {
        return mb_substr(trim($value), 0, self::VALUE_LEN);
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
     * Replace the option rows of a listing.
     *
     * @param array<int,array<string,mixed>> $rows t_item_variant rows: pk_i_id and s_options
     *
     * @throws \mindstellar\database\DbException
     */
    public static function rewriteVariants(int $itemId, array $rows): void
    {
        self::dropVariants($itemId);
        self::insert(self::variantRows($itemId, $rows));
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function dropVariants(int $itemId): void
    {
        Db::execute('DELETE FROM ' . self::tableName() . ' WHERE fk_i_item_id = ? AND fk_i_variant_id <> 0', [$itemId]);
    }

    /**
     * Replace the rows one listing has for one custom field.
     *
     * @param string|array<string,mixed>|null $value   as Field::replace() gets it
     * @param string|null                     $storage the field's storage primitive, looked up when null
     *
     * @throws \mindstellar\database\DbException
     */
    public static function rewriteField(int $itemId, int $fieldId, $value, ?string $storage = null): void
    {
        $storage ??= self::storageOfField($fieldId);
        self::deleteField($itemId, $fieldId);
        self::insert(self::fieldRows($itemId, $fieldId, $storage, $value));
    }

    /**
     * Remove a field's rows from every listing.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function dropField(int $fieldId): void
    {
        Db::execute(
            'DELETE FROM ' . self::tableName() . ' WHERE s_key IN (?, ?, ?)',
            [self::key($fieldId), self::key($fieldId, 'from'), self::key($fieldId, 'to')]
        );
    }

    /**
     * Rebuild one listing from t_item_meta and t_item_variant.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function rewriteItem(int $itemId): void
    {
        self::rewriteScoped($itemId, null);
    }

    /**
     * Rebuild the next listings after $after in one transaction, or only one field's rows of them.
     *
     * @return int|null the last listing id done, or null when the batch was short
     * @throws \mindstellar\database\DbException
     */
    public static function buildStep(int $after, int $limit, ?int $fieldId = null): ?int
    {
        $ids = array_map('intval', array_column(Db::select(
            'SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id > ? ORDER BY pk_i_id LIMIT ' . max(1, $limit),
            [$after]
        ), 'pk_i_id'));
        if ($ids === []) {
            return null;
        }
        Db::transaction(static function () use ($ids, $fieldId): void {
            foreach ($ids as $id) {
                if (ListingStore::find($id, ['pk_i_id'], true) === null) {
                    continue;
                }
                self::rewriteScoped($id, $fieldId);
            }
        });

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
     * Rewrite a listing's rows: all of them, or only one field's. Reads lock what they read
     * (variants, then meta, as the writers do) so a batch never holds an old snapshot.
     *
     * @throws \mindstellar\database\DbException
     */
    private static function rewriteScoped(int $itemId, ?int $fieldId): void
    {
        $rows = [];
        if ($fieldId === null) {
            Db::execute('DELETE FROM ' . self::tableName() . ' WHERE fk_i_item_id = ?', [$itemId]);
            $variants = Db::select(
                'SELECT pk_i_id, s_options FROM ' . DB_TABLE_PREFIX . 't_item_variant WHERE fk_i_item_id = ? ORDER BY pk_i_id FOR UPDATE',
                [$itemId]
            );
            $rows = self::variantRows($itemId, $variants);
        } else {
            self::deleteField($itemId, $fieldId);
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
            $rows = array_merge($rows, self::fieldRows($itemId, $id, $field['storage'], $field['value']));
        }
        self::insert($rows);
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    private static function deleteField(int $itemId, int $fieldId): void
    {
        Db::execute(
            'DELETE FROM ' . self::tableName() . ' WHERE fk_i_item_id = ? AND s_key IN (?, ?, ?)',
            [$itemId, self::key($fieldId), self::key($fieldId, 'from'), self::key($fieldId, 'to')]
        );
    }

    private static function storageOfField(int $fieldId): string
    {
        $field = Field::getInstance()->findByPrimaryKey($fieldId);

        return self::storageOfType((string) ($field['e_type'] ?? 'TEXT'));
    }

    private static function storageOfType(string $type): string
    {
        return FieldTypeRegistry::getInstance()->storageOf($type);
    }

    /**
     * @param string|array<string,mixed>|null $value
     *
     * @return array<int,array{0:string,1:string,2:int,3:int,4:?float}>
     */
    private static function fieldRows(int $itemId, int $fieldId, string $storage, $value): array
    {
        if ($storage === 'DATEINTERVAL') {
            $rows = [];
            foreach (['from', 'to'] as $end) {
                $row = is_array($value) ? self::numberRow($itemId, self::key($fieldId, $end), (string) ($value[$end] ?? '')) : null;
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
        switch ($storage) {
            case 'DROPDOWN':
            case 'RADIO':
                return $text === '' ? [] : [[$key, self::cut($text), $itemId, 0, null]];
            case 'CHECKBOX':
                return $text === '1' ? [[$key, '1', $itemId, 0, null]] : [];
            case 'NUMBER':
            case 'DATE':
                $row = self::numberRow($itemId, $key, $text);

                return $row === null ? [] : [$row];
        }

        return [];
    }

    /**
     * @return array{0:string,1:string,2:int,3:int,4:?float}|null
     */
    private static function numberRow(int $itemId, string $key, string $text): ?array
    {
        $number = self::number($text);

        return $number === null ? null : [$key, self::cut($text), $itemId, 0, $number];
    }

    /**
     * @param array<int,array<string,mixed>> $variants
     *
     * @return array<int,array{0:string,1:string,2:int,3:int,4:?float}>
     */
    private static function variantRows(int $itemId, array $variants): array
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
                $rows[] = [self::optionKey((string) $name), self::cut((string) $value), $itemId, (int) $variant['pk_i_id'], null];
            }
        }

        return $rows;
    }

    /**
     * @param array<int,array{0:string,1:string,2:int,3:int,4:?float}> $rows
     *
     * @throws \mindstellar\database\DbException
     */
    private static function insert(array $rows): void
    {
        foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                array_push($params, ...$row);
            }
            Db::execute(
                'INSERT INTO ' . self::tableName() . ' (s_key, s_value, fk_i_item_id, fk_i_variant_id, d_number) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')),
                $params
            );
        }
    }
}
