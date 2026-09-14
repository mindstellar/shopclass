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
 * DataTable class
 *
 * @since      3.1
 * @package    Shopclass
 * @subpackage classes
 * @author     Shopclass
 */
abstract class DataTable
{
    protected $aColumns;
    protected $aRows;
    protected $rawRows;

    protected $limit;
    protected $start;
    protected $iPage;
    protected $total;
    protected $totalFiltered;

    /**
     * Initialises the column, row and raw-row buffers as empty arrays.
     */
    public function __construct()
    {
        $this->aColumns = array();
        $this->aRows    = array();
        $this->rawRows  = array();
    }

    /**
     * FUNCTIONS THAT SHOULD BE REDECLARED IN SUB-CLASSES
     *
     * Fills the table from a plain result set, deriving the columns from the first row.
     *
     * @param array<int,array<string,mixed>>|null $results
     *
     * @return void
     */
    public function setResults($results = null)
    {
        if (is_array($results)) {
            $this->start         = 0;
            $this->limit         = count($results);
            $this->total         = count($results);
            $this->totalFiltered = count($results);

            if (count($results) > 0) {
                foreach ($results as $r) {
                    $row = array();
                    if (is_array($r)) {
                        foreach ($r as $k => $v) {
                            $row[$k] = $v;
                        }
                    }
                    $this->addRow($row);
                }
                if (is_array($results[0])) {
                    foreach ($results[0] as $k => $v) {
                        $this->addColumn($k, $k);
                    }
                }
            }
        }
    }

    /**
     * COMMON FUNCTIONS . DO NOT MODIFY THEM
     */

    /**
     * Resolve the ORDER BY from the request's sort and direction params.
     *
     * $sortable maps the column id rendered in the header to the column it orders by, so
     * only a column the table actually offers can reach the query. An unknown or absent
     * sort falls back to $default rather than ordering by nothing.
     *
     * @param array<string,mixed>  $request   Request params, as passed to table()
     * @param array<string,string> $sortable  Header column id => order column
     * @param string               $default   Order column used when none is requested
     * @param string               $direction Direction used when none is requested
     *
     * @return array{column_name:string,type:string}
     */
    protected function resolveOrder($request, array $sortable, $default, $direction = 'DESC')
    {
        $sort = isset($request['sort']) && !is_array($request['sort'])
            ? (string)$request['sort']
            : '';
        $type = isset($request['direction']) && !is_array($request['direction'])
            ? strtoupper(trim((string)$request['direction']))
            : '';
        if ($type !== 'ASC' && $type !== 'DESC') {
            $type = strtoupper($direction);
        }

        return array(
            'column_name' => $sortable[$sort] ?? $default,
            'type'        => $type,
        );
    }

    /**
     * Appends one row to the table.
     *
     * @param array<string,mixed> $aRow
     *
     * @return void
     */
    protected function addRow($aRow)
    {
        $this->aRows[] = $aRow;
    }

    /**
     * Add a colum
     *
     * @param string $id
     * @param string $text
     * @param int    $priority Lower values sort earlier; 1 to 10
     *
     * @return void
     */
    public function addColumn($id, $text, $priority = 5)
    {
        $this->removeColumn($id);
        $this->aColumns[$priority][$id] = $text;
    }

    /**
     * Drops a column from every priority bucket.
     *
     * @param string $id
     *
     * @return void
     */
    public function removeColumn($id)
    {
        for ($priority = 1; $priority <= 10; $priority++) {
            unset($this->aColumns[$priority][$id]);
        }
    }

    /**
     * Returns the table payload consumed by the admin datatable renderer.
     *
     * @return array{aColumns:array<string,string>,aRows:array<int,array<string,mixed>>,iDisplayLength:int|null,iTotalDisplayRecords:int|null,iTotalRecords:int|null,iPage:int|null}
     */
    public function getData()
    {
        return array(
            'aColumns'             => $this->sortedColumns()
            ,
            'aRows'                => $this->sortedRows()
            ,
            'iDisplayLength'       => $this->limit
            ,
            'iTotalDisplayRecords' => $this->totalFiltered
            ,
            'iTotalRecords'        => $this->total
            ,
            'iPage'                => $this->iPage
        );
    }

    /**
     * Flattens the priority buckets into a single ordered column map.
     *
     * @return array<string,string>
     */
    public function sortedColumns()
    {
        $columns_ordered = array();
        for ($priority = 1; $priority <= 10; $priority++) {
            if (isset($this->aColumns[$priority]) && is_array($this->aColumns[$priority])) {
                foreach ($this->aColumns[$priority] as $k => $v) {
                    $columns_ordered[$k] = $v;
                }
            }
        }

        return $columns_ordered;
    }

    /**
     * Returns the rows reduced to the sorted column set, missing cells filled with ''.
     *
     * @return array<int,array<string,mixed>>
     */
    public function sortedRows()
    {
        $rows    = array();
        $aRows   = (array)$this->aRows;
        $columns = (array)$this->sortedColumns();
        if (count($aRows) === 0) {
            return $rows;
        }
        foreach ($aRows as $row) {
            $aux_row = array();
            foreach ($columns as $k => $v) {
                if (isset($row[$k])) {
                    $aux_row[$k] = $row[$k];
                } else {
                    $aux_row[$k] = '';
                }
            }
            $rows[] = $aux_row;
        }

        return $rows;
    }

    /**
     * Returns the unformatted rows as fetched from the model.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rawRows()
    {
        return $this->rawRows;
    }
}
