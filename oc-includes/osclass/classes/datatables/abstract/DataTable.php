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

    public function __construct()
    {
        $this->aColumns = array();
        $this->aRows    = array();
        $this->rawRows  = array();
    }


    /**
     * FUNCTIONS THAT SHOULD BE REDECLARED IN SUB-CLASSES
     *
     * @param null $results
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
     * @param $aRow
     */
    protected function addRow($aRow)
    {
        $this->aRows[] = $aRow;
    }

    /**
     * Add a colum
     *
     * @param     $id
     * @param     $text
     * @param int $priority
     */
    public function addColumn($id, $text, $priority = 5)
    {
        $this->removeColumn($id);
        $this->aColumns[$priority][$id] = $text;
    }

    /**
     * @param $id
     */
    public function removeColumn($id)
    {
        for ($priority = 1; $priority <= 10; $priority++) {
            unset($this->aColumns[$priority][$id]);
        }
    }

    /**
     * @return array
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
     * @return array
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
     * @return array
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
     * @return array
     */
    public function rawRows()
    {
        return $this->rawRows;
    }
}
