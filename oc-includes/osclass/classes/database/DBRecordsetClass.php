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
 * Database recordset object
 *
 * @package    Shopclass
 * @subpackage Database
 * @since      2.3
 */
class DBRecordsetClass
{
    /**
     * Database connection object to Shopclass database
     *
     * @since  2.3
     * @var mysqli|null
     */
    public $connId;
    /**
     * Database result object
     *
     * @since  2.3
     * @var mysqli_result|null
     */
    public $resultId;
    /**
     * Result array
     *
     * @since  2.3
     * @var array<int,array<string,mixed>>
     */
    public $resultArray;
    /**
     * Rows fetched as objects
     *
     * @since  2.3
     * @var array<int,object>
     */
    public $resultObject;
    /**
     * Number of rows
     *
     * @since  2.3
     * @var int
     */
    public $numRows;
    /**
     * Current row
     *
     * @since  2.3
     * @var int
     */
    protected $currentRow;

    /**
     * Initialize Recordset Class
     *
     * @param mysqli|null        $connId
     * @param mysqli_result|null $resultId
     */
    public function __construct($connId = null, $resultId = null)
    {
        $this->connId       = $connId;
        $this->resultId     = $resultId;
        $this->resultArray  = array();
        $this->resultObject = array();
        $this->currentRow   = 0;
        $this->numRows      = 0;
    }

    /**
     * Get a result row as an array or object
     *
     * @param int    $n
     * @param string $type 'array' for an associative row, anything else for an object
     *
     * @return array<string,mixed>|object|array{} An empty array when there are no rows
     */
    public function row($n = 0, $type = 'array')
    {
        if (!is_numeric($n)) {
            $n = 0;
        }

        if ($type === 'array') {
            return $this->rowArray($n);
        }

        return $this->rowObject($n);
    }

    /**
     * Get a result row as an array
     *
     * @param int $n
     *
     * @return array<string,mixed>|array{} An empty array when there are no rows
     * @since  2.3
     */
    public function rowArray($n = 0)
    {
        $result = $this->resultArray();

        if (count($result) == 0) {
            return $result;
        }

        if ($n != $this->currentRow && isset($result[$n])) {
            $this->currentRow = $n;
        }

        return $result[$this->currentRow];
    }

    /**
     * Get the results of MySQLi_Result object in array format
     *
     * @return array<int,array<string,mixed>>
     * @since  2.3
     */
    public function resultArray()
    {
        if (count($this->resultArray) > 0) {
            return $this->resultArray;
        }

        $this->_dataSeek();
        while ($row = $this->_fetchArray()) {
            $this->resultArray[] = $row;
        }

        return $this->resultArray;
    }

    /**
     * Adjust resultId pointer to the selected row
     *
     * @param int $offset Must be between zero and the total number of rows minus one
     *
     * @return bool true on success or false on failure
     * @since  2.3
     */
    public function _dataSeek($offset = 0)
    {
        return $this->resultId->data_seek($offset);
    }

    /**
     * Returns the current row of a result set as an array
     *
     * @return array<string,mixed>|null null once the rows are exhausted
     * @since  2.3
     */
    public function _fetchArray()
    {
        return $this->resultId->fetch_assoc();
    }

    /**
     * Get a result row as an object
     *
     * @param int $n
     *
     * @return object|array{} An empty array when there are no rows
     * @since  2.3
     */
    public function rowObject($n = 0)
    {
        $result = $this->resultObject();

        if (count($result) == 0) {
            return $result;
        }

        if ($n != $this->currentRow && isset($result[$n])) {
            $this->currentRow = $n;
        }

        return $result[$this->currentRow];
    }

    /**
     * Get the results of MySQLi_Result object in object format
     *
     * @return array<int,object>
     * @since  2.3
     */
    public function resultObject()
    {
        if (count($this->resultObject) > 0) {
            return $this->resultObject;
        }

        $this->_dataSeek();
        while ($row = $this->_fetchObject()) {
            $this->resultObject[] = $row;
        }

        return $this->resultObject;
    }

    /**
     * Returns the current row of a result set as an object
     *
     * @return object|null null once the rows are exhausted
     * @since  2.3
     */
    public function _fetchObject()
    {
        return $this->resultId->fetch_object();
    }

    /**
     * Get the first row as an array or object
     *
     * @param string $type 'array' for associative rows, anything else for objects
     *
     * @return array<string,mixed>|object|array{} An empty array when there are no rows
     * @since  2.3
     */
    public function firstRow($type = 'array')
    {
        $result = $this->result($type);

        if (count($result) == 0) {
            return $result;
        }

        return $result[0];
    }

    /**
     * Get the results of MySQLi_Result object
     *
     * @param string $type 'array' for associative rows, anything else for objects
     *
     * @return array<int,array<string,mixed>>|array<int,object>
     * @since  2.3
     */
    public function result($type = 'array')
    {
        if ($type === 'array') {
            return $this->resultArray();
        }

        return $this->resultObject();
    }

    /**
     * Get the last row as an array or object
     *
     * @param string $type 'array' for an associative row, anything else for an object
     *
     * @return array<string,mixed>|object|array{} An empty array when there are no rows
     * @since  2.3
     */
    public function lastRow($type = 'array')
    {
        $result = $this->result($type);

        if (count($result) == 0) {
            return $result;
        }

        return $result[count($result) - 1];
    }

    /**
     * Get next row as an array or object
     *
     * @param string $type 'array' for an associative row, anything else for an object
     *
     * @return array<string,mixed>|object|array{} An empty array when there are no rows
     * @since  2.3
     */
    public function nextRow($type = 'array')
    {
        $result = $this->result($type);

        if (count($result) == 0) {
            return $result;
        }

        if (isset($result[$this->currentRow + 1])) {
            $this->currentRow++;
        }

        return $result[$this->currentRow];
    }

    /**
     * Get previous row as an array or object
     *
     * @param string $type 'array' for an associative row, anything else for an object
     *
     * @return array<string,mixed>|object|array{} An empty array when there are no rows
     * @since  2.3
     */
    public function previousRow($type = 'array')
    {
        $result = $this->result($type);

        if (count($result) == 0) {
            return $result;
        }

        if (isset($result[$this->currentRow - 1])) {
            $this->currentRow--;
        }

        return $result[$this->currentRow];
    }

    /**
     * Get number of rows
     *
     * @return int
     * @since  2.3
     */
    public function numRows()
    {
        return $this->resultId->num_rows;
    }

    /**
     * Get the number of fields in a result
     *
     * @return int
     * @since  2.3
     */
    public function numFields()
    {
        return $this->resultId->field_count;
    }

    /**
     * Get the name of the fields in an array
     *
     * @return string[]
     * @since  2.3
     */
    public function listFields()
    {
        $fieldNames = array();
        while ($field = $this->resultId->fetch_field()) {
            $fieldNames[] = $field->name;
        }

        return $fieldNames;
    }
}

/* file end: ./oc-includes/osclass/classes/database/DBRecordsetClass.php */
