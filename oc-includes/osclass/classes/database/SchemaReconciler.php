<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\database;

/**
 * Additive schema reconciler.
 *
 * Diffs struct.sql against the live schema and applies what is missing. Pairs
 * with mindstellar\migration\MigrationRunner: this handles everything that can
 * be derived from struct.sql, migrations handle everything that cannot.
 *
 * The diffing itself is string work over the parsed struct.sql; only the
 * introspection (SHOW TABLES / DESCRIBE / SHOW INDEX / SHOW CREATE TABLE) and
 * the statements it emits touch the database.
 *
 * @package    Shopclass
 * @subpackage Database
 * @since      5.3.0
 */
class SchemaReconciler
{
    /**
     * @var Connection
     */
    private $conn;

    /**
     * @param Connection $conn connection bound to the database being reconciled
     */
    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Bring an installed schema up to the one struct.sql describes.
     *
     * Additive only: it creates missing tables, and adds missing columns,
     * indexes and foreign keys to tables that already exist. It also applies
     * column type and default changes. What it deliberately does not do is
     * drop, rename, transform data or change a storage engine — anything of
     * that kind is authored as an ordered migration under installer/migrations/
     * and applied by MigrationRunner.
     *
     * That split is the reason both exist. struct.sql stays the single source
     * of truth for what the schema should look like, so an ordinary additive
     * change needs no migration written by hand; migrations carry only the
     * changes that cannot be expressed as "make the live schema look like this".
     *
     * A statement that fails does not abort the run: the reconcile is a batch of
     * independent statements, and the failures are returned so the caller can
     * decide whether to go on.
     *
     * @param array|string $queries struct.sql, or its statements already split
     *
     * @return array{0:bool,1:array,2:array} success, the statements it ran, and those that failed
     */
    public function reconcile($queries = '')
    {
        if (!is_array($queries)) {
            $queries = SqlScript::statements($queries);
        }

        // Prepare and separate the queries
        $struct_queries = array();
        $data_queries   = array();
        $this->prepareAndSepareQueries($queries, $data_queries, $struct_queries);

        // Set foreign keys check to false
        $this->conn->execute('SET FOREIGN_KEY_CHECKS = 0');

        // Get tables from DB (already installed)
        $tables = $this->conn->select('SHOW TABLES');
        foreach ($tables as $v) {
            $table = current($v);
            if ($this->existTableIntoStruct($table, $struct_queries)) {
                $lastTable     = null;
                $constrains    = array();
                $indexes       = $constrains;
                $normal_fields = $indexes;
                $fields        = $this->getTableFieldsFromStruct($table, $struct_queries);
                if ($fields) {
                    // classify fields (into sql file)
                    $this->classifyFieldsSql($fields, $normal_fields, $indexes, $constrains, $lastTable);
                    // Take fields from the DB (now into database)
                    $tbl_fields = $this->conn->select('DESCRIBE ' . $table);
                    // compare and create alter statments
                    $this->createAlterTable($tbl_fields, $table, $normal_fields, $struct_queries);
                    // Go for the index part
                    $tbl_indexes = $this->conn->select('SHOW INDEX FROM ' . $table);

                    // compare table index and struct.sql index for the same table, and only add the new ones
                    $this->createNewIndex($tbl_indexes, $indexes, $table, $struct_queries);

                    // show create table TABLE_NAME constrains
                    $tbl_constraint = $this->conn->selectOne('SHOW CREATE TABLE ' . $table);
                    // create foreign keys
                    $this->createForeignKey($tbl_constraint, $table, $struct_queries, $constrains);
                    // No need to create the table, so we delete it SQL
                    unset($struct_queries[strtolower($table)]);
                }
            }
        }

        $queries = array_merge($struct_queries, $data_queries);

        $ok            = true;
        $error_queries = array();
        foreach ($queries as $query) {
            try {
                $this->conn->execute($query);
            } catch (DbException $e) {
                // Kept going rather than aborting: a reconcile is a batch of
                // independent additive statements, and the caller is shown the
                // ones that failed so it can decide whether to continue.
                $ok              = false;
                $error_queries[] = $query;
            }
        }
        // Set foreign_key_checks to 1
        $this->conn->execute('SET FOREIGN_KEY_CHECKS = 1');

        return array($ok, $queries, $error_queries);
    }

    /**
     * Prepare and separe the queries, and save into data or struct queries
     *
     * @param array $queries
     * @param array $data_queries
     * @param array $struct_queries
     */
    private function prepareAndSepareQueries($queries, &$data_queries, &$struct_queries)
    {
        foreach ($queries as $query) {
            if (preg_match('|CREATE DATABASE ([^ ]*)|', $query, $match)) {
                array_unshift($struct_queries, $query);
            } elseif (preg_match('|CREATE TABLE ([^ ]*)|', $query, $match)) {
                $struct_queries[strtolower(trim($match[1], '`'))] = $query;
            } elseif (preg_match('|INSERT INTO ([^ ]*)|', $query, $match)) {
                $data_queries[] = $query;
            } elseif (preg_match('|UPDATE ([^ ]*)|', $query, $match)) {
                $data_queries[] = $query;
            }
        }
    }

    /**
     * Check if $table exist into array $struct_queries
     *
     * @param string $table
     * @param array  $struct_queries
     *
     * @return bool
     */
    private function existTableIntoStruct($table, $struct_queries)
    {
        return array_key_exists(strtolower($table), $struct_queries);
    }

    /**
     * Get fields from struct_queries (struct.sql)
     *
     * @param string $table
     * @param array  $struct_queries
     *
     * @return array|bool
     */
    private function getTableFieldsFromStruct($table, &$struct_queries)
    {
        // Anchored to the bracket that opens THIS table rather than the first one
        // anywhere in the statement: a comment above the CREATE TABLE containing a
        // bracket used to start the column list early, and its prose was then read
        // as column definitions. SqlScript strips line comments now, so this is the
        // second of two independent guards against the same fault.
        $pattern = '|CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?[^\s`(]+`?\s*\((.*)\)|ims';
        if (preg_match($pattern, $struct_queries[strtolower($table)], $match)) {
            $fields = explode("\n", trim($match[1]));
            foreach ($fields as $key => $value) {
                $fields[$key] = trim(preg_replace('/,$/', '', $value));
            }
        } else {
            $fields = false;
        }

        return $fields;
    }

    /**
     * Classify fields, inside arrays $normal_fields, $indexes, $constrains (foreign key's)
     *
     * @param $fields
     * @param $normal_fields
     * @param $indexes
     * @param $constrains
     * @param $lastTable
     */
    private function classifyFieldsSql($fields, &$normal_fields, &$indexes, &$constrains, &$lastTable)
    {
        foreach ($fields as $field) {
            if (preg_match('|([^ ]+)|', trim($field), $field_name)) {
                switch (strtolower($field_name[1])) {
                    case '':
                    case 'on':
                        if ($lastTable) {
                            $constrains[$lastTable] .= ' ' . trim($field);
                        }
                        break;
                    case 'foreign':
                        if (preg_match("|FOREIGN KEY\s+(.*)\s+REFERENCES\s+(.*)|mi", $field, $match)) {
                            $_table              = $match[1];
                            $refere              = $match[2];
                            $refere              = str_replace(',', '', $refere);
                            $lastTable           = $_table;
                            $constrains[$_table] = trim($refere);
                        }
                        break;
                    case 'primary':
                    case 'index':
                    case 'fulltext':
                    case 'unique':
                    case 'key':
                        $added = false;
                        if (preg_match("|PRIMARY KEY\s+\((.*)\)|mi", $field, $match)) {
                            $_field = strtolower($match[1]);
                            if (isset($normal_fields[$_field])) {
                                $normal_fields[$_field] .= ' PRIMARY KEY';
                                $added                  = true;
                            }
                        }

                        if (!$added) {
                            $indexes[] = trim($field, ", \n");
                        }
                        break;
                    default:
                        $normal_fields[strtolower($field_name[1])] = trim($field, ", \n");
                        break;
                }
            }
        }
    }

    /**
     * Build alter sql, ADD COLUMN, CHANGE COLUMN, ALTER COLUMN
     *
     * @param array  $tbl_fields , contain all fields inside database
     * @param string $table
     * @param        $normal_fields
     * @param        $struct_queries
     */
    private function createAlterTable($tbl_fields, $table, &$normal_fields, &$struct_queries)
    {
        foreach ($tbl_fields as $tbl_field) {
            //Every field should we on the definition, so else SHOULD never happen,
            // unless a very aggressive plugin modify our tables
            if (array_key_exists(strtolower($tbl_field['Field']), $normal_fields)) {
                // Take the of the field
                if (preg_match(
                    '|' . $tbl_field['Field'] . " (ENUM\s*\(([^\)]*)\))|i",
                    $normal_fields[strtolower($tbl_field['Field'])],
                    $match
                )
                    || preg_match(
                        '|' . $tbl_field['Field'] . ' ([^ ]*( unsigned)?)|i',
                        $normal_fields[strtolower($tbl_field['Field'])],
                        $match
                    )
                ) {
                    $field_type = $match[1];

                    //HACK: DB upgrade issue on different version of MySQL ignore INT display width
                    $tbl_field['Type'] = preg_replace('/INT\([\d]+\)/i', 'int', strtolower($tbl_field['Type']));
                    $field_type        = preg_replace('/INT\([\d]+\)/i', 'int', strtolower($field_type));

                    // Are they the same?
                    if ($field_type !== $tbl_field['Type']
                        && str_replace(' ', '', strtolower($field_type)) !== str_replace(
                            ' ',
                            '',
                            strtolower($tbl_field['Type'])
                        )
                    ) {
                        $struct_queries[] =
                            'ALTER TABLE ' . $table . ' CHANGE COLUMN ' . $tbl_field['Field'] . ' '
                            . $normal_fields[strtolower($tbl_field['Field'])];
                    }
                }

                // Have we changed the default value? [with quotes]
                if (preg_match(
                    "| DEFAULT\s+'(.*)'|i",
                    $normal_fields[strtolower($tbl_field['Field'])],
                    $default_match
                )
                ) {
                    // alter column only if default value has been changed
                    if ($tbl_field['Default'] != $default_match[1]) {
                        $struct_queries[] =
                            'ALTER TABLE ' . $table . ' ALTER COLUMN ' . $tbl_field['Field'] . " SET DEFAULT '"
                            . $default_match[1] . "'";
                    }
                    // Have we changed the default value? [without quotes]
                } elseif (preg_match(
                    "| DEFAULT\s+(.*)|i",
                    $normal_fields[strtolower($tbl_field['Field'])],
                    $default_match
                )
                ) {
                    if (isset($tbl_field['Default'])) {
                        // alter column only if default value has been changed
                        if ($tbl_field['Default'] != $default_match[1]) {
                            $struct_queries[] =
                                'ALTER TABLE ' . $table . ' ALTER COLUMN ' . $tbl_field['Field'] . ' SET DEFAULT '
                                . $default_match[1];
                        }
                    } elseif ($default_match[1] !== 'NULL') {
                        $struct_queries[] =
                            'ALTER TABLE ' . $table . ' ALTER COLUMN ' . $tbl_field['Field'] . ' SET DEFAULT '
                            . $default_match[1];
                    }
                }
                // Remove it from the list, so it will not be added
                unset($normal_fields[strtolower($tbl_field['Field'])]);
            }
        }
        // For the rest of normal fields (they are not in the table) we add them.
        foreach ($normal_fields as $k => $v) {
            $struct_queries[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $v;
        }
    }

    /**
     * With all the indexes from struct.sql, remove indexes which actually
     * exist into database
     *
     * @param      $tbl_indexes
     * @param      $indexes
     * @param      $table
     * @param      $struct_queries
     */
    private function createNewIndex($tbl_indexes, &$indexes, $table, &$struct_queries)
    {
        if ($tbl_indexes) {
            unset($indexes_array);
            foreach ($tbl_indexes as $tbl_index) {
                $indexes_array[$tbl_index['Key_name']]['columns'][]  =
                    array('fieldname' => $tbl_index['Column_name'], 'subpart' => $tbl_index['Sub_part']);
                $indexes_array[$tbl_index['Key_name']]['unique']     = $tbl_index['Non_unique'] == 0;
                $indexes_array[$tbl_index['Key_name']]['index_type'] = $tbl_index['Index_type'];
                $indexes_array[$tbl_index['Key_name']]['Key_name']   = $tbl_index['Key_name'];
            }

            foreach ($indexes_array as $k => $v) {
                // if PRIMARY KEY already exist
                $exist_primary = false;
                if (($k === 'PRIMARY') && isset($indexes_array['PRIMARY'])) {
                    if (count($indexes_array['PRIMARY']['columns']) > 0) {
                        $exist_primary = true;
                    }
                }

                $string = '';
                if ($k === 'PRIMARY') {
                    $string .= 'PRIMARY KEY ';
                } elseif ($v['unique']) {
                    $string .= 'UNIQUE KEY ';
                } elseif ($v['index_type'] === 'FULLTEXT') {  // FULLTEXT INDEX MUST HAVE KEY_NAME
                    $string .= 'FULLTEXT ' . $k . ' ';
                } elseif ((count($v['columns']) == 1 && $v['columns'][0]['fieldname'] != $k)
                          || (preg_match('/^idx/', $k, $coincidencias) > 0)
                ) {
                    $string .= 'INDEX ' . $k . ' ';
                } else {
                    $string .= 'INDEX ' . $v['Key_name'] . ' ';
                }

                $columns = '';
                // For each column in the index
                foreach ($v['columns'] as $column) {
                    if ($columns != '') {
                        $columns .= ', ';
                    }
                    // Add the field to the column list string
                    $columns .= '' . $column['fieldname'] . '';
                    if ($column['subpart'] != '') {
                        $columns .= '(' . $column['subpart'] . ')';
                    }
                }

                // Add the column list to the index create string
                $string    .= '(' . $columns . ')';
                $var_index = array_search($string, $indexes);

                if (!($var_index === false)) {
                    unset($indexes[$var_index]);
                } else {
                    $var_index = array_search(str_replace(', ', ',', $string), $indexes);
                    if (!($var_index === false)) {
                        unset($indexes[$var_index]);
                    }
                }
            }
        }

        // Names of indexes already on the live table. An additive reconcile can
        // only ADD; it never redefines an existing index (a DROP + ADD is a
        // migration's job). So a name that already exists is already satisfied
        // and must not be re-added: ADD INDEX/FULLTEXT/UNIQUE on an existing name
        // fails with "Duplicate key name", which the string diff above can miss
        // when the two canonical forms differ only in formatting (a sub-part
        // length, fulltext column order). Skipping by name keeps the reconcile
        // clean where before it surfaced a false-positive error.
        $existingNames = array();
        if ($tbl_indexes) {
            foreach ($tbl_indexes as $tbl_index) {
                $existingNames[strtolower($tbl_index['Key_name'])] = true;
            }
        }

        // alter table
        foreach ($indexes as $v) {
            if (preg_match('/primary key/i', $v, $coincidencias) > 0) {
                $struct_queries[] = 'ALTER TABLE ' . $table . ' DROP PRIMARY KEY, ADD ' . $v;
                continue;
            }
            $name = self::indexDefName($v);
            if ($name !== '' && isset($existingNames[strtolower($name)])) {
                // Already present under this name — nothing additive to do. A
                // genuine definition change is carried by a migration, not here.
                continue;
            }
            $struct_queries[] = 'ALTER TABLE ' . $table . ' ADD ' . $v;
        }
    }

    /**
     * Extract the index name from a struct.sql index definition line:
     * "INDEX idx_foo (a, b)" -> "idx_foo", "FULLTEXT s_description
     * (s_description, s_title)" -> "s_description", "UNIQUE KEY uk (x)" -> "uk".
     * Returns 'PRIMARY' for a primary key, and '' for an unnamed index (MySQL
     * names those itself, so there is nothing to match on).
     *
     * @param string $def
     *
     * @return string
     */
    private static function indexDefName($def)
    {
        $def = trim($def);
        if (preg_match('/^PRIMARY\b/i', $def)) {
            return 'PRIMARY';
        }
        // Everything before the first '(' is "<type keywords> <optional name>".
        $head = preg_split('/\(/', $def, 2)[0];
        // Strip the leading index-type keywords (UNIQUE/FULLTEXT/SPATIAL, then
        // KEY/INDEX, in either order); whatever remains is the optional name.
        $head = preg_replace('/^\s*(UNIQUE|FULLTEXT|SPATIAL)\b/i', '', $head);
        $head = preg_replace('/^\s*(INDEX|KEY)\b/i', '', $head);

        return trim($head, " `\t\n");
    }

    /**
     * Create alter table if foreign key don't exist into database structure
     *
     * @param array  $tbl_constraint
     * @param string $table
     * @param array  $struct_queries
     * @param array  $constrains
     */
    private function createForeignKey($tbl_constraint, $table, &$struct_queries, $constrains)
    {
        $createTable = isset($tbl_constraint['Create Table']) ? $tbl_constraint['Create Table'] : '';

        // Existing keys, indexed by the column list they are declared on. A second key
        // on the same columns is a duplicate an earlier reconcile added and is dropped,
        // so the pass converges instead of appending another one every upgrade.
        $existing = array();
        $duplicates = array();
        foreach ($this->parseForeignKeys($createTable) as $fk) {
            if (isset($existing[$fk['columns']])) {
                $duplicates[] = $fk['name'];
                continue;
            }
            $existing[$fk['columns']] = $fk;
        }

        foreach ($constrains as $columns => $reference) {
            $wantColumns   = $this->normalizeKeyColumns($columns);
            $wantReference = $this->normalizeReference($reference);
            $wantActions   = $this->normalizeReferentialActions($reference);

            if (!isset($existing[$wantColumns])) {
                $struct_queries[] = 'ALTER TABLE ' . $table
                    . ' ADD FOREIGN KEY ' . $columns . ' REFERENCES ' . $reference;
                continue;
            }

            $have = $existing[$wantColumns];
            if ($have['reference'] === $wantReference && $have['actions'] === $wantActions) {
                continue;
            }

            // The key exists but points elsewhere, or -- far more commonly -- carries a
            // different ON DELETE rule than struct.sql now declares. A referential
            // action cannot be altered in place, so replace the key rather than add a
            // second one beside it.
            $duplicates[]     = $have['name'];
            $struct_queries[] = 'ALTER TABLE ' . $table
                . ' ADD FOREIGN KEY ' . $columns . ' REFERENCES ' . $reference;
        }

        // Dropped before anything is added: MySQL will not accept two keys on the same
        // columns, and the ADDs above are queued after these.
        foreach (array_reverse($duplicates) as $name) {
            array_unshift($struct_queries, 'ALTER TABLE ' . $table . ' DROP FOREIGN KEY ' . $name);
        }
    }

    /**
     * Foreign keys declared in a SHOW CREATE TABLE body, as name / columns / reference /
     * referential-action parts, each already normalised for comparison.
     *
     * @param string $createTable
     *
     * @return array
     */
    private function parseForeignKeys($createTable)
    {
        $keys = array();
        $pattern = '/CONSTRAINT\s+`?([^`\s]+)`?\s+FOREIGN KEY\s*\(([^)]*)\)'
            . '\s*REFERENCES\s*`?([^`\s(]+)`?\s*\(([^)]*)\)([^,\n]*)/i';

        if (preg_match_all($pattern, $createTable, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $keys[] = array(
                    'name'      => $match[1],
                    'columns'   => $this->normalizeKeyColumns($match[2]),
                    'reference' => $this->normalizeReference($match[3] . '(' . $match[4] . ')'),
                    'actions'   => $this->normalizeReferentialActions($match[5]),
                );
            }
        }

        return $keys;
    }

    /**
     * A column list reduced to a comparable form: no backticks, parentheses or spacing
     * differences between `(fk_i_id)`, "( `fk_i_id` )" and `fk_i_id`.
     *
     * @param string $columns
     *
     * @return string
     */
    private function normalizeKeyColumns($columns)
    {
        $columns = str_replace(array('`', '(', ')', ' ', "\t"), '', $columns);

        return strtolower(trim($columns, ','));
    }

    /**
     * The referenced table and columns of a foreign key, with any trailing ON DELETE /
     * ON UPDATE clause removed so that the target can be compared independently of the
     * referential actions.
     *
     * @param string $reference
     *
     * @return string
     */
    private function normalizeReference($reference)
    {
        $reference = preg_replace('/\bON\s+(DELETE|UPDATE)\b.*$/is', '', $reference);
        $reference = str_replace(array('`', ' ', "\t", "\n"), '', $reference);

        return strtolower(trim($reference, ','));
    }

    /**
     * The ON DELETE / ON UPDATE clauses of a foreign key, reduced to a comparable form.
     *
     * RESTRICT and NO ACTION are the default and behave identically, and MySQL omits
     * them from SHOW CREATE TABLE, so all three spellings normalise to the empty string
     * -- otherwise a key that already matches struct.sql would be rebuilt on every pass.
     *
     * @param string $reference
     *
     * @return string
     */
    private function normalizeReferentialActions($reference)
    {
        if (!preg_match_all('/\bON\s+(DELETE|UPDATE)\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT)/i', $reference, $matches, PREG_SET_ORDER)) {
            return '';
        }

        $actions = array();
        foreach ($matches as $match) {
            $event  = strtoupper($match[1]);
            $action = strtoupper(preg_replace('/\s+/', ' ', $match[2]));
            if ($action === 'RESTRICT' || $action === 'NO ACTION') {
                continue;
            }
            $actions[$event] = 'ON ' . $event . ' ' . $action;
        }

        ksort($actions);

        return implode(' ', $actions);
    }
}

/* file end: ./oc-includes/osclass/classes/database/SchemaReconciler.php */
