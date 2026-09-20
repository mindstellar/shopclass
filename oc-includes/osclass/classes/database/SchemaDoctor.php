<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\database;

/**
 * Compares a live database against `struct.sql` and reports what differs. Reads only.
 *
 * SchemaReconciler repairs a drifted schema during an upgrade, but it is additive by design and
 * blind to a whole class of drift: it compares a column's type with a one-token regex, so NULL
 * against NOT NULL never registers; it never drops a column core stopped declaring; and it only
 * ever adds an index, never redefines one. Drift it cannot see produces no statement, so nothing
 * fails and nothing is reported.
 *
 * `tests/schema-drift.php` cannot see it either: it compares a fresh install against a baseline
 * plus migrations, and an install whose history predates the oldest supported upgrade — or that
 * was altered by hand or by a plugin — is outside both paths.
 *
 * This is the missing third view: what one real database actually holds, against what core
 * declares today.
 *
 * @package mindstellar\database
 */
final class SchemaDoctor
{
    /** A column core declares that this database does not have. */
    public const MISSING_COLUMN = 'missing_column';

    /** A column this database has that core no longer declares. */
    public const EXTRA_COLUMN = 'extra_column';

    /** Same column, different NULL / NOT NULL. */
    public const NULLABILITY = 'nullability';

    /** Same column, different declared type. */
    public const COLUMN_TYPE = 'column_type';

    /** An index core declares that this database does not have. */
    public const MISSING_INDEX = 'missing_index';

    /** An index this database has that core no longer declares. */
    public const EXTRA_INDEX = 'extra_index';

    /** Same index name, different columns or different order. */
    public const INDEX_COLUMNS = 'index_columns';

    /** A whole table core declares that this database does not have. */
    public const MISSING_TABLE = 'missing_table';

    private Connection $conn;

    /** @var string Contents of struct.sql */
    private string $struct;

    /** @var array<string,array<string,string>> table => index name => INDEX_TYPE, filled as read */
    private array $indexTypes = array();

    /** @var array<string,array<int,string>> table => columns carrying a foreign key, filled as read */
    private array $foreignKeyColumns = array();

    /**
     * @param Connection  $conn
     * @param string|null $structPath defaults to the bundled installer/struct.sql
     */
    public function __construct(Connection $conn, ?string $structPath = null)
    {
        $this->conn   = $conn;
        $path         = $structPath ?? (ABS_PATH . 'oc-includes/osclass/installer/struct.sql');
        $this->struct = is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Every difference found, as a flat list.
     *
     * @return array<int,array{table:string,kind:string,name:string,declared:string,found:string}>
     * @throws DbException
     */
    public function diagnose(): array
    {
        $findings = array();

        foreach ($this->declaredTables() as $table => $declared) {
            $live = $this->liveColumns($table);
            if ($live === array()) {
                $findings[] = $this->finding($table, self::MISSING_TABLE, $table, 'declared', 'absent');
                continue;
            }

            foreach ($declared['columns'] as $name => $spec) {
                if (!isset($live[$name])) {
                    $findings[] = $this->finding($table, self::MISSING_COLUMN, $name, $spec['type'], 'absent');
                    continue;
                }
                if ($spec['nullable'] !== $live[$name]['nullable']) {
                    $findings[] = $this->finding(
                        $table,
                        self::NULLABILITY,
                        $name,
                        $spec['nullable'] ? 'NULL' : 'NOT NULL',
                        $live[$name]['nullable'] ? 'NULL' : 'NOT NULL'
                    );
                }
                if ($spec['type'] !== '' && $spec['type'] !== $live[$name]['type']) {
                    $findings[] = $this->finding(
                        $table,
                        self::COLUMN_TYPE,
                        $name,
                        $spec['type'],
                        $live[$name]['type']
                    );
                }
            }

            foreach ($live as $name => $_) {
                if (!isset($declared['columns'][$name])) {
                    $findings[] = $this->finding($table, self::EXTRA_COLUMN, $name, 'not declared', 'present');
                }
            }

            $liveIndexes = $this->liveIndexes($table);
            $substitutes = array();
            foreach ($declared['indexes'] as $name => $columns) {
                if (!isset($liveIndexes[$name])) {
                    // An old install can carry the same guarantee under another name — a
                    // PRIMARY KEY on the columns core declares a UNIQUE KEY for, say. The
                    // name differs, nothing else does, so there is nothing to act on.
                    if ($this->coveredElsewhere($liveIndexes, $columns)) {
                        $substitutes[] = $columns;
                        continue;
                    }
                    $findings[] = $this->finding($table, self::MISSING_INDEX, $name, implode(', ', $columns), 'absent');
                    continue;
                }
                // Column order decides which queries a normal index can serve, so it is
                // compared. A FULLTEXT index searches all of its columns at once, so the
                // order they were declared in means nothing.
                $live     = $liveIndexes[$name];
                $ordered  = $this->indexTypes[$table][$name] ?? '';
                if ($ordered === 'FULLTEXT') {
                    $a = $live;
                    $b = $columns;
                    sort($a);
                    sort($b);
                    if ($a === $b) {
                        continue;
                    }
                }
                if ($live !== $columns) {
                    $findings[] = $this->finding(
                        $table,
                        self::INDEX_COLUMNS,
                        $name,
                        implode(', ', $columns),
                        implode(', ', $live)
                    );
                }
            }

            foreach ($liveIndexes as $name => $columns) {
                if (isset($declared['indexes'][$name]) || $name === 'PRIMARY') {
                    continue;
                }
                // A foreign key needs a covering index and the server makes one when no
                // declared index already leads on that column. It is required, not spare.
                if ($this->backsForeignKey($table, $columns)) {
                    continue;
                }
                // The index accepted above as standing in for a declared one core could not
                // find by name. Only that one: an index duplicating a declared index that is
                // present under its own name is genuinely spare, and costs a write for nothing.
                if (in_array($columns, $substitutes, true)) {
                    continue;
                }
                $findings[] = $this->finding($table, self::EXTRA_INDEX, $name, 'not declared', implode(', ', $columns));
            }
        }

        return $findings;
    }

    /**
     * @param string $table
     * @param string $kind
     * @param string $name
     * @param string $declared
     * @param string $found
     *
     * @return array{table:string,kind:string,name:string,declared:string,found:string}
     */
    private function finding(string $table, string $kind, string $name, string $declared, string $found): array
    {
        return array(
            'table'    => $table,
            'kind'     => $kind,
            'name'     => $name,
            'declared' => $declared,
            'found'    => $found,
        );
    }

    /**
     * Parse struct.sql into table => ['columns' => name => spec, 'indexes' => name => columns].
     *
     * Deliberately not a SQL parser: struct.sql is core's own file, one column or key per line,
     * and the reconciler reads it the same way. A line it cannot make sense of is skipped rather
     * than reported, because a false difference is worse than a missed one in a tool whose whole
     * output is "here is what looks wrong".
     *
     * @return array<string,array{columns:array<string,array{type:string,nullable:bool}>,indexes:array<string,array<int,string>>}>
     */
    private function declaredTables(): array
    {
        if ($this->struct === '') {
            return array();
        }

        $tables = array();
        if (!preg_match_all(
            '/CREATE\s+TABLE\s+\/\*TABLE_PREFIX\*\/(\w+)\s*\((.*?)\)\s*ENGINE/is',
            $this->struct,
            $matches,
            PREG_SET_ORDER
        )) {
            return array();
        }

        foreach ($matches as $match) {
            $table   = DB_TABLE_PREFIX . $match[1];
            $columns = array();
            $indexes = array();

            foreach (explode("\n", $match[2]) as $line) {
                $line = trim(rtrim(trim($line), ','));
                if ($line === '' || strpos($line, '--') === 0) {
                    continue;
                }

                // PRIMARY KEY (a, b) / UNIQUE KEY name (a, b) / INDEX name (a, b(10))
                if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|FULLTEXT(?:\s+KEY)?|INDEX|KEY)\s*(\w+)?\s*\((.+)\)$/i', $line, $k)) {
                    $keyword = strtoupper(preg_replace('/\s+/', ' ', $k[1]));
                    $name    = $keyword === 'PRIMARY KEY' ? 'PRIMARY' : (string) ($k[2] ?? '');
                    if ($name === '') {
                        continue; // Unnamed: the server invents a name, so there is nothing to match on.
                    }
                    $indexes[$name] = $this->splitIndexColumns($k[3]);
                    continue;
                }

                if (stripos($line, 'FOREIGN KEY') === 0 || stripos($line, 'CONSTRAINT') === 0) {
                    continue; // The reconciler owns foreign keys and can already add and drop them.
                }

                if (!preg_match('/^(\w+)\s+(.+)$/', $line, $c)) {
                    continue;
                }
                $definition       = $c[2];
                $columns[$c[1]] = array(
                    'type'     => $this->normaliseType($definition),
                    'nullable' => stripos($definition, 'NOT NULL') === false,
                );
            }

            $tables[$table] = array('columns' => $columns, 'indexes' => $indexes);
        }

        return $tables;
    }

    /**
     * The column names of an index declaration, prefix lengths dropped so a declaration
     * compares against information_schema, which records the length separately.
     *
     * @param string $columns
     *
     * @return array<int,string>
     */
    private function splitIndexColumns(string $columns): array
    {
        $out = array();
        foreach (explode(',', $columns) as $column) {
            $column = trim($column);
            $column = preg_replace('/\(\s*\d+\s*\)$/', '', $column);
            $column = trim((string) $column);
            if ($column !== '') {
                $out[] = strtolower($column);
            }
        }

        return $out;
    }

    /**
     * The type as information_schema would spell it: lowercase, no attributes, no default.
     *
     * @param string $definition
     *
     * @return string
     */
    private function normaliseType(string $definition): string
    {
        if (!preg_match('/^([a-z]+(?:\s*\([^)]*\))?(?:\s+unsigned)?)/i', trim($definition), $m)) {
            return '';
        }

        return self::comparableType($m[1]);
    }

    /**
     * Both sides reduced to the same spelling, so only a real difference is reported.
     *
     * An integer's display width is decoration the server adds or drops by version — `int` and
     * `int(11)` are one type — and it spells an enum without the spaces struct.sql writes for
     * readability. A tool whose entire output is "this looks wrong" has to be silent about both.
     *
     * @param string $type
     *
     * @return string
     */
    private static function comparableType(string $type): string
    {
        $type = strtolower(trim(preg_replace('/\s+/', ' ', $type)));
        $type = str_replace(' (', '(', $type);
        // Display width on an integer type only: the length of a varchar or a decimal's
        // precision is part of the type and a difference there is worth reporting.
        $type = preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\s*\(\s*\d+\s*\)/', '$1', $type);
        // enum('a', 'b') and enum('a','b') are the same set.
        $type = preg_replace_callback('/^(enum|set)\((.*)\)$/', static function (array $m): string {
            return $m[1] . '(' . preg_replace('/,\s+/', ',', $m[2]) . ')';
        }, (string) $type);

        return (string) $type;
    }

    /**
     * @param string $table
     *
     * @return array<string,array{type:string,nullable:bool}>
     * @throws DbException
     */
    private function liveColumns(string $table): array
    {
        $rows = $this->conn->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            array($table)
        );

        $out = array();
        foreach ($rows as $row) {
            $out[(string) $row['COLUMN_NAME']] = array(
                'type'     => self::comparableType((string) $row['COLUMN_TYPE']),
                'nullable' => strtoupper((string) $row['IS_NULLABLE']) === 'YES',
            );
        }

        return $out;
    }

    /**
     * Whether an index leading on this column exists only to back a foreign key.
     *
     * @param string            $table
     * @param array<int,string> $columns
     *
     * @return bool
     * @throws DbException
     */
    private function backsForeignKey(string $table, array $columns): bool
    {
        if ($columns === array()) {
            return false;
        }
        if (!isset($this->foreignKeyColumns[$table])) {
            $rows = $this->conn->select(
                'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                array($table)
            );
            $names = array();
            foreach ($rows as $row) {
                $names[] = strtolower((string) $row['COLUMN_NAME']);
            }
            $this->foreignKeyColumns[$table] = $names;
        }

        return in_array($columns[0], $this->foreignKeyColumns[$table], true);
    }

    /**
     * Whether some other index on the table already covers exactly these columns, in order.
     *
     * @param array<string,array<int,string>> $liveIndexes
     * @param array<int,string>               $columns
     *
     * @return bool
     */
    private function coveredElsewhere(array $liveIndexes, array $columns): bool
    {
        foreach ($liveIndexes as $existing) {
            if ($existing === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $table
     *
     * @return array<string,array<int,string>> index name => columns in order
     * @throws DbException
     */
    private function liveIndexes(string $table): array
    {
        $rows = $this->conn->select(
            'SELECT INDEX_NAME, COLUMN_NAME, INDEX_TYPE FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            array($table)
        );

        $out = array();
        foreach ($rows as $row) {
            $name                              = (string) $row['INDEX_NAME'];
            $out[$name][]                      = strtolower((string) $row['COLUMN_NAME']);
            $this->indexTypes[$table][$name]   = strtoupper((string) $row['INDEX_TYPE']);
        }

        return $out;
    }
}
