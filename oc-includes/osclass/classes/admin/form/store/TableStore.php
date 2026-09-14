<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form\store;

/**
 * One row of one table, addressed by its primary key.
 *
 * Bound to a table and a key rather than to a model class on purpose: every entity model
 * in core still extends the legacy DAO, which reports a failed write by returning false
 * and returns an affected-row count from update() -- so an unchanged save legitimately
 * returns 0 and reads as failure. This writes through osc_db_table() instead, which
 * throws on failure and cannot be misread.
 *
 * Rows are addressed by an integer key only, and never by one taken from the request:
 * the key comes from the caller, which is the only party that knows which row this admin
 * is allowed to be editing.
 *
 * A field maps to the column of the same name unless it declares one. A field this save
 * did not collect -- a custom one, or one whose 'depends' master is off -- is not written,
 * so its column keeps its previous value.
 *
 * A field may also say what its column takes, with 'persist': false for a field that is
 * no column at all, or a callable returning the value to write, where null writes nothing.
 * What the column takes and what the control shows are separate answers, so a derived
 * column still reads back unless the field also declares 'write_only'. A field that is no
 * column is the exception, because it has none to read.
 *
 * @package mindstellar\admin\form\store
 */
final class TableStore implements Store
{
    private string $table;

    private string $pk;

    /**
     * @param string $table Unprefixed table name; DB_TABLE_PREFIX is applied here.
     * @param string $pk    Primary key column.
     */
    public function __construct(string $table, string $pk)
    {
        $this->table = $table;
        $this->pk    = $pk;
    }

    /**
     * The column a field maps to: its own name unless it declares another.
     *
     * @param string              $name
     * @param array<string,mixed> $field field spec
     *
     * @return string
     */
    public static function column(string $name, array $field): string
    {
        $column = $field['column'] ?? '';

        return is_string($column) && $column !== '' ? $column : $name;
    }

    /**
     * The column value of one declared field, or its declared default when the row has no
     * such column.
     *
     * @inheritDoc
     *
     * @param string              $name  field name
     * @param array<string,mixed> $field field spec
     * @param int|string|null     $id    the row: a positive integer, or the decimal string
     *                                   of one
     *
     * @return mixed
     * @throws StoreException when the key is not a positive integer
     * @throws \mindstellar\database\DbException on a failed read
     */
    public function value(string $name, array $field, $id = null)
    {
        return $this->load(array($name => $field), $id)[$name] ?? null;
    }

    /**
     * Every declared field's column value, keyed by field name.
     *
     * @inheritDoc
     *
     * @param array<string,array<string,mixed>> $fields declared fields, keyed by name
     * @param int|string|null                   $id     the row, or null for a new one
     *
     * @return array<string,mixed>
     * @throws StoreException when the key is not a positive integer
     * @throws \mindstellar\database\DbException on a failed read
     */
    public function load(array $fields, $id = null): array
    {
        $row = $this->row($this->identify($id));

        $values = array();
        foreach ($fields as $name => $field) {
            $type = $field['type'] ?? 'text';
            if ($type === 'custom') {
                // Core neither reads nor writes a custom field, so it has no column here
                // either; the plugin owns the value the same way it owns the markup.
                $values[$name] = $field['default'] ?? '';
                continue;
            }
            // Nothing to read in either case, for two different reasons: a write-only
            // field has a column it must never show -- a password hash -- and a
            // 'persist' => false field has no column at all, so one that happens to share
            // its name belongs to something else.
            if (!empty($field['write_only']) || (($field['persist'] ?? null) === false)) {
                $values[$name] = $field['default'] ?? ($type === 'checkbox' ? false : '');
                continue;
            }
            $column = self::column($name, $field);
            if (!array_key_exists($column, $row)) {
                $values[$name] = $field['default'] ?? ($type === 'checkbox' ? false : '');
                continue;
            }
            $values[$name] = osc_settings_cast($type, $row[$column]);
        }

        return $values;
    }

    /**
     * Write the declared columns of one row, inserting when no key was given and updating
     * when one was.
     *
     * @inheritDoc
     *
     * @param array<string,array<string,mixed>>  $fields  declared fields, keyed by name
     * @param array<string,mixed>                $values  validated values, keyed by field name
     * @param array<string,array<string,string>> $locales unused: no declared column expands
     *                                                    over locales
     * @param int|string|null                    $id      the row, or null to insert one
     *
     * @return array{updated:int,id:int|string|null} rows affected, and the key written
     * @throws StoreException when the key is not a positive integer, or names a row that is
     *                        gone
     * @throws \mindstellar\database\DbException on a failed write
     */
    public function save(array $fields, array $values, array $locales, $id = null): array
    {
        $id = $this->identify($id);
        if ($id !== null && $this->row($id) === array()) {
            // An update matching nothing affects zero rows, and so does an update that
            // changed nothing: the count cannot tell them apart. Without this the admin
            // who saves a row somebody else deleted is told there was nothing to update
            // and loses everything they typed, and the effects run for a row that is gone.
            throw StoreException::noRow('TableStore: ' . $this->table . ' has no row ' . $id);
        }

        $data = array();
        foreach ($fields as $name => $field) {
            if ($field['type'] === 'custom' || !array_key_exists($name, $values)) {
                // A field core never collected names no column here: a custom one it does
                // not read, or one discarded with its master switched off. Its column keeps
                // whatever it already held -- a column cannot not exist, so the alternative
                // is blanking a value the administrator never touched.
                continue;
            }
            $column = self::persisted($field, $values[$name], $values);
            if ($column === null) {
                // Declared as no column at all, or derived to nothing: "leave this one
                // alone", which is how a blank new-password box means "unchanged" without
                // the store having to know what a password is.
                continue;
            }
            $data[self::column($name, $field)] = $column;
        }

        if ($data === array()) {
            // Every declared field was a custom one or was discarded with its master off:
            // there is no column to set, so an existing row is left alone and no row is
            // inserted for a submission that named nothing to put in one.
            return array('updated' => 0, 'id' => $id);
        }

        // The builder is immutable, so every clause has to be reassigned or it is dropped
        // -- and a dropped WHERE would be an UPDATE across the whole table, which is why
        // QueryBuilder refuses one outright.
        $query = osc_db_table(DB_TABLE_PREFIX . $this->table);
        if ($id === null) {
            return array('updated' => 1, 'id' => $query->insert($data));
        }

        $query = $query->where($this->pk, $id);

        // The row was there a statement ago and the write throws when it fails, so zero
        // affected rows here can only mean the row already said what the form says.
        return array('updated' => $query->update($data), 'id' => $id);
    }

    /**
     * The row a primary key addresses, or an empty array when there is no key yet or no
     * row under it.
     *
     * @param int|null $id
     *
     * @return array<string,mixed>
     * @throws \mindstellar\database\DbException on a failed read
     */
    private function row(?int $id): array
    {
        if ($id === null) {
            return array();
        }

        $query = osc_db_table(DB_TABLE_PREFIX . $this->table);
        $query = $query->where($this->pk, $id);

        return $query->first() ?? array();
    }

    /**
     * The row this save or load is about, and only ever the one the caller named.
     *
     * Three answers and no fourth: no key at all is a new row, a positive integer is the
     * row it names, and anything else is refused. Guessing is what makes the third case
     * dangerous -- (int) accepts ' 12 ' and '12abc' as 12, so a malformed key that was
     * quietly coerced writes over a row nobody asked for.
     *
     * @param int|string|null $id
     *
     * @return int|null the row named, or null for a new one
     * @throws StoreException when the key is not a positive integer
     */
    private function identify($id): ?int
    {
        if ($id === null) {
            return null;
        }
        if (is_int($id) ? $id > 0 : (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id))) {
            return (int)$id;
        }

        throw StoreException::badKey(
            'TableStore: ' . $this->table . '.' . $this->pk . ' needs a positive integer key'
        );
    }

    /**
     * The value a field's column takes, or null when it takes none.
     *
     * A field declaring 'persist' => false is no column; one declaring a callable gets
     * whatever the callable makes of the validated value, and null from it leaves the
     * column as it was.
     *
     * @param array<string,mixed> $field  field spec
     * @param mixed               $value  the validated value
     * @param array<string,mixed> $values every validated value, keyed by field name
     *
     * @return mixed null when the field takes no column
     */
    private static function persisted(array $field, $value, array $values)
    {
        if (!array_key_exists('persist', $field)) {
            return self::columnValue($field, $value);
        }
        if ($field['persist'] === false) {
            return null;
        }

        $derived = call_user_func($field['persist'], $value, $values);

        // A column cannot hold an array, and a callable handing one back is a bug in the
        // declaration rather than something to write an empty string for silently.
        return is_array($derived) ? null : $derived;
    }

    /**
     * The value a column takes for a validated field value.
     *
     * @param array<string,mixed> $field field spec
     * @param mixed               $value
     *
     * @return mixed
     */
    private static function columnValue(array $field, $value)
    {
        if ($field['type'] === 'checkbox') {
            return $value ? 1 : 0;
        }
        if (is_array($value)) {
            // Nothing declared on a table store expands into an array today, and a column
            // cannot hold one.
            return '';
        }

        return $value;
    }
}
