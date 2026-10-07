<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Db;
use mindstellar\database\TablePrefix;

/**
 * Model database for Dump database tables
 *
 * @package    Shopclass
 * @subpackage Model
 */
class Dump extends DAO
{
    /**
     * It references to self object: Dump.
     * It is used as a singleton
     *
     * @var Dump
     */
    private static $instance;

    /** Bytes collected before table_data() writes them out. */
    private const CHUNK = 1048576;

    /** Rows per INSERT statement, so a restore never holds a whole table. */
    private const INSERT_ROWS = 1000;

    /** Bytes of values per INSERT statement, kept well under max_allowed_packet. */
    private const INSERT_BYTES = 1048576;

    /**
     * Return the shared Dump model instance, creating it on first use.
     *
     * @return \Dump
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * A runtime table name is an identifier, not a value, so it can never be a
     * bound parameter. Every name this model receives originates from SHOW TABLES
     * on the connection's own database (see osc_dbdump()), but the public methods
     * could also be reached with a caller-supplied name, so each name is validated
     * against the same strict allowlist the query builder enforces before it is
     * interpolated (and always backtick-quoted).
     *
     * @param string $table
     *
     * @return bool
     */
    private function isValidTableName($table)
    {
        return is_string($table) && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1;
    }

    /**
     * Whether a table is one of this site's.
     *
     * @param string $table
     *
     * @return bool
     */
    private function hasPrefix($table)
    {
        return DB_TABLE_PREFIX !== '' && TablePrefix::owns($table, DB_TABLE_PREFIX);
    }

    /**
     * Return all tables from database
     *
     * @return array<int,array<string,string>> One single-column row per table
     */
    public function showTables()
    {
        // SHOW TABLES is not a SELECT/INSERT/UPDATE/DELETE and carries no values,
        // so it runs as raw SQL through the parameterized layer with no bindings.
        try {
            $rows = Db::select('SHOW TABLES');
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return Db::stringifyRows($rows);
    }

    /**
     * Dump into path the table structure of $table
     *
     * @param string $path
     * @param string $table
     * @param bool   $prefixToken write the prefix token in place of this site's table prefix
     *
     * @return bool
     */
    public function table_structure($path, $table, $prefixToken = false)
    {
        if (!is_writable($path)) {
            return false;
        }

        $_str = '/* Table structure for table `' . $table . "` */\n";

        // SHOW CREATE TABLE takes an identifier, not a value; $table is validated
        // (isValidTableName) and backtick-quoted before it reaches SQL. An invalid
        // identifier is treated exactly like a query that returned nothing, which
        // is the legacy failed-query branch (header written, true returned).
        $result = array();
        if ($this->isValidTableName($table)) {
            try {
                $result = Db::select('SHOW CREATE TABLE `' . $table . '`');
            } catch (\mindstellar\database\DbException $e) {
                $result = array();
            }
        }

        foreach ($result as $_line) {
            $create = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $_line['Create Table'] . ';');
            if ($prefixToken && $this->hasPrefix($table)) {
                $create = (string) preg_replace(
                    '/(CREATE TABLE IF NOT EXISTS |REFERENCES )`' . preg_quote(DB_TABLE_PREFIX, '/') . 't_/',
                    '$1`' . TablePrefix::TOKEN . 't_',
                    $create
                );
            }
            $_str .= $create . "\n\n";
        }
        $this->appendFile($path, $_str);

        return true;
    }

    /**
     * Dump all table rows into path
     *
     * Rows are read unbuffered and written in chunks, so memory use does not grow with
     * the table. A new INSERT starts every 1000 rows or about 1 MB of values, so each
     * statement fits in max_allowed_packet when restored.
     *
     * @param string $path
     * @param string $table
     * @param bool   $prefixToken write the prefix token in place of this site's table prefix
     *
     * @return bool
     */
    public function table_data($path, $table, $prefixToken = false)
    {
        if (!is_writable($path)) {
            return false;
        }

        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            trigger_error(sprintf('Could not open %s for writing', $path), E_USER_WARNING);

            return true;
        }

        // The read stays on the raw mysqli handle: the per-column quoting needs the
        // result-set field types, which the parameterized Connection layer does not expose.
        // An unusable identifier or a failed query writes only the trailing newline.
        $res = false;
        if ($this->isValidTableName($table)) {
            $conn = \mindstellar\database\ConnectionManager::getInstance()->getHandle();
            if ($conn instanceof mysqli) {
                try {
                    $res = $conn->query('SELECT * FROM `' . $table . '`', MYSQLI_USE_RESULT);
                } catch (Exception $e) {
                    $res = false;
                }
            }
        }

        $buffer = '';
        if ($res instanceof mysqli_result) {
            $fields = $res->fetch_fields();
            $target = $prefixToken && $this->hasPrefix($table)
                ? TablePrefix::TOKEN . substr($table, strlen(DB_TABLE_PREFIX))
                : $table;
            $count  = 0;
            $batch  = 0;
            $bytes  = 0;
            $write  = function (array $row) use ($table, $target, $fields, $handle, &$buffer, &$count, &$batch, &$bytes) {
                if ($count === 0) {
                    $buffer .= '/* dumping data for table `' . $table . "` */\n";
                }
                $buffer .= $batch === 0 ? 'insert into `' . $target . "` values\n" : ",\n";
                $values  = $this->rowValues($row, $fields);
                $buffer .= $values;
                $count++;
                $batch++;
                $bytes += strlen($values);
                if ($batch >= self::INSERT_ROWS || $bytes >= self::INSERT_BYTES) {
                    $buffer .= ";\n";
                    $batch   = 0;
                    $bytes   = 0;
                }
                if (strlen($buffer) >= self::CHUNK) {
                    $this->writeChunk($handle, $buffer);
                    $buffer = '';
                }
            };

            if ($table == DB_TABLE_PREFIX . 't_category') {
                // Parents must come before their children, so this small table is read whole.
                $rows = $res->fetch_all(MYSQLI_ASSOC);
                $res->free();
                foreach ($this->_dump_table_category($rows) as $row) {
                    $write($row);
                }
            } else {
                while (($row = $res->fetch_assoc()) !== null) {
                    $write($row);
                }
                $res->free();
            }

            if ($batch > 0) {
                $buffer .= ";\n";
            }
        }

        $buffer .= "\n";
        $this->writeChunk($handle, $buffer);
        fclose($handle);

        return true;
    }

    /**
     * One row as its "(v1,v2,...)" tuple.
     *
     * @param array<string,mixed> $row
     * @param \stdClass[]         $fields mysqli field metadata
     *
     * @return string
     */
    private function rowValues(array $row, array $fields)
    {
        $out  = '(';
        $last = count($fields) - 1;
        foreach ($fields as $i => $field) {
            $v = $row[$field->name];
            if (null === $v) {
                $out .= 'null';
            } else {
                $this->_quotes($field->type, $out, $v);
            }
            if ($i < $last) {
                $out .= ',';
            }
        }

        return $out . ')';
    }

    /**
     * Write a chunk of dump text, warning rather than throwing on failure.
     *
     * @param resource $handle
     * @param string   $content
     *
     * @return void
     */
    private function writeChunk($handle, $content)
    {
        if ($content !== '' && fwrite($handle, $content) === false) {
            trigger_error('Could not write the database backup', E_USER_WARNING);
        }
    }

    /**
     * t_category rows ordered so every parent comes before its children.
     *
     * @param array<int,array<string,mixed>> $result
     *
     * @return array<int,array<string,mixed>>
     */
    private function _dump_table_category($result)
    {
        $short_rows   = array();
        $unshort_rows = array();
        foreach ($result as $row) {
            if ($row['fk_i_parent_id'] == null) {
                $short_rows[] = $row;
            } else {
                $unshort_rows[$row['pk_i_id']] = $row;
            }
        }

        while (!empty($unshort_rows)) {
            foreach ($unshort_rows as $k => $v) {
                foreach ($short_rows as $r) {
                    if ($r['pk_i_id'] == $v['fk_i_parent_id']) {
                        unset($unshort_rows[$k]);
                        $short_rows[] = $v;
                    }
                }
            }
        }

        return $short_rows;
    }

    /**
     * Add quotes if it's necessary
     *
     * data =>  http://www.php.net/manual/es/mysqli-result.fetch-field.php#106064
     *
     * @param int                   $type  mysqli field type constant
     * @param string                 $_str  Dump text, appended to in place
     * @param string|int|float       $value
     *
     * @return void
     */
    private function _quotes($type, &$_str, $value)
    {
        //            * numeric *
        //            BIT: 16 - TINYINT: 1 - BOOL: 1 - SMALLINT: 2 - MEDIUMINT: 9
        //            INTEGER: 3 - BIGINT: 8 - SERIAL: 8 - FLOAT: 4 - DOUBLE: 5
        //            DECIMAL: 246 - NUMERIC: 246 - FIXED: 246
        //            * dates *
        //            DATE: 10 - DATETIME: 12 - TIMESTAMP: 7 - TIME: 11 - YEAR: 13
        //            * strings & binary *
        //            CHAR: 254 - VARCHAR: 253 - ENUM: 254 - SET: 254 - BINARY: 254
        //            VARBINARY: 253 - TINYBLOB: 252 - BLOB: 252 - MEDIUMBLOB: 252
        //            TINYTEXT: 252 - TEXT: 252 - MEDIUMTEXT: 252 - LONGTEXT: 252

        $aNumeric = array(16, 1, 2, 9, 3, 8, 4, 5, 246);
        $aDates   = array(10, 12, 7, 11, 13);
        $aString  = array(254, 253, 252);

        if (in_array($type, $aNumeric, true)) {
            $_str .= $value;
        } elseif (in_array($type, $aDates, true)) {
            $_str .= $this->quoteValue($value);
        } elseif (in_array($type, $aString, true)) {
            $_str .= $this->quoteValue($value);
        }
    }

    /**
     * Quote a row value for the generated backup SQL. This produces FILE TEXT for
     * a dump the site owner re-imports, not a live query, so there is no value to
     * bind; it reproduces the legacy escape() rules verbatim (an is_numeric() value
     * is emitted bare unless it is longer than one character and starts with '0',
     * otherwise the value is real_escape_string()'d and single-quoted) so the
     * produced bytes are unchanged. The value being quoted originates from the
     * database being dumped, never from request input.
     *
     * @param string|int|float $value
     *
     * @return string|int|float Quoted text, or the value itself when it is emitted bare
     */
    private function quoteValue($value)
    {
        if (is_numeric($value)) {
            if (strlen((string)$value) > 1 && strpos((string)$value, '0') === 0) {
                return "'" . $value . "'";
            }

            return $value;
        }

        $conn = \mindstellar\database\ConnectionManager::getInstance()->getHandle();
        $escaped = $conn instanceof mysqli ? $conn->real_escape_string((string)$value) : addslashes((string)$value);

        return "'" . $escaped . "'";
    }

    /**
     * Append text to the dump file, warning rather than throwing on failure.
     *
     * @param string $file
     * @param string $content
     *
     * @return void
     */
    private function appendFile($file, $content)
    {
        try {
            (new \mindstellar\utility\FileSystem())->writeToFile($file, $content, true);
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
        }
    }
}
/* file end: ./oc-includes/osclass/model/Dump.php */
