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

/**
 * Model database for Dump database tables
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class Dump extends DAO
{
    /**
     * It references to self object: Dump.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var Dump
     */
    private static $instance;

    /**
     * @return \Dump
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
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
     * Return all tables from database
     *
     * @return array
     */
    public function showTables()
    {
        // SHOW TABLES is not a SELECT/INSERT/UPDATE/DELETE and carries no values,
        // so it runs as raw SQL through the parameterized layer with no bindings.
        try {
            $rows = osc_db_select('SHOW TABLES');
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Dump into path the table structure of $table
     *
     * @param string $path
     * @param string $table
     *
     * @return bool
     */
    public function table_structure($path, $table)
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
                $result = osc_db_select('SHOW CREATE TABLE `' . $table . '`');
            } catch (\mindstellar\database\DbException $e) {
                $result = array();
            }
        }

        foreach ($result as $_line) {
            $_str .= str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $_line['Create Table'] . ';');
            $_str .= "\n\n";
        }
        $this->appendFile($path, $_str);

        return true;
    }

    /**
     * Dump all table rows into path
     *
     * @param string $path
     * @param string $table
     *
     * @return bool
     */
    public function table_data($path, $table)
    {
        if (!is_writable($path)) {
            return false;
        }

        // SELECT * FROM <ident> discovers the table at runtime; $table is validated
        // and backtick-quoted before it reaches SQL (previously it was interpolated
        // raw and unvalidated). The read stays on the metadata-bearing driver path
        // because the per-column quoting below is driven by the mysqli RESULT-SET
        // FIELD TYPES (fetch_fields()->type), which the parameterized Connection
        // layer does not expose. An unusable identifier or a failed query yields no
        // rows, matching the legacy failed-query branch (just the trailing newline,
        // true returned).
        $result = array();
        $num_rows   = 0;
        $num_fields = 0;
        $fields     = array();

        if ($this->isValidTableName($table)) {
            $conn = DBConnectionClass::newInstance()->getOsclassDb();
            $res  = false;
            if ($conn instanceof mysqli) {
                try {
                    $res = $conn->query('SELECT * FROM `' . $table . '`');
                } catch (Exception $e) {
                    $res = false;
                }
            }
            if ($res instanceof mysqli_result) {
                $result     = $res->fetch_all(MYSQLI_ASSOC);
                $num_rows   = $res->num_rows;
                $num_fields = $res->field_count;
                $fields     = $res->fetch_fields();
                $res->free();
            }
        }

        $_str = '';
        if ($num_fields > 0) {
            if ($num_rows > 0) {
                $_str .= '/* dumping data for table `' . $table . '` */';
                $_str .= "\n";

                $field_type = array();
                $i          = 0;

                foreach ($fields as $meta) {
                    $field_type[] = $meta->type;
                }

                $_str .= 'insert into `' . $table . '` values';
                $_str .= "\n";

                $index = 0;
                if ($table == DB_TABLE_PREFIX . 't_category') {
                    $this->_dump_table_category($result, $num_fields, $field_type, $fields, $index, $num_rows, $_str);
                } else {
                    foreach ($result as $row) {
                        $_str .= '(';
                        for ($i = 0; $i < $num_fields; $i++) {
                            $v = $row[$fields[$i]->name];
                            if (null === $v) {
                                $_str .= 'null';
                            } else {
                                $this->_quotes($fields[$i]->type, $_str, $row[$fields[$i]->name]);
                            }
                            if ($i < $num_fields - 1) {
                                $_str .= ',';
                            }
                        }
                        $_str .= ')';

                        if ($index < $num_rows - 1) {
                            $_str .= ',';
                        } else {
                            $_str .= ';';
                        }
                        $_str .= "\n";

                        $index++;
                    }
                }
            }
        }

        $_str .= "\n";

        $this->appendFile($path, $_str);

        return true;
    }

    /**
     * Specific dump for t_category table
     *
     * @param $result
     * @param $num_fields
     * @param $field_type
     * @param $fields
     * @param $index
     * @param $num_rows
     * @param $_str
     */
    private function _dump_table_category($result, $num_fields, $field_type, $fields, $index, $num_rows, &$_str)
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

        foreach ($short_rows as $row) {
            $_str .= '(';
            for ($i = 0; $i < $num_fields; $i++) {
                $v = $row[$fields[$i]->name];
                if (null === $v) {
                    $_str .= 'null';
                } else {
                    $this->_quotes($fields[$i]->type, $_str, $v);
                }
                if ($i < $num_fields - 1) {
                    $_str .= ',';
                }
            }
            $_str .= ')';

            if ($index < $num_rows - 1) {
                $_str .= ',';
            } else {
                $_str .= ';';
            }
            $_str .= "\n";

            $index++;
        }
    }


    /**
     * Add quotes if it's necessary
     *
     * data =>  http://www.php.net/manual/es/mysqli-result.fetch-field.php#106064
     *
     * @param $type
     * @param $_str
     * @param $value
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
     * @param string $value
     *
     * @return string
     */
    private function quoteValue($value)
    {
        if (is_numeric($value)) {
            if (strlen((string)$value) > 1 && strpos((string)$value, '0') === 0) {
                return "'" . $value . "'";
            }

            return $value;
        }

        $conn = DBConnectionClass::newInstance()->getOsclassDb();
        $escaped = $conn instanceof mysqli ? $conn->real_escape_string((string)$value) : addslashes((string)$value);

        return "'" . $escaped . "'";
    }

    /**
     * @param $file
     * @param $content
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
