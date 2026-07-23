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
 * Class Widget
 */
class Widget extends DAO
{
    /**
     *
     * @var \Widget
     */
    private static $instance;

    /**
     * Widget constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_widget');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array('pk_i_id', 's_description', 's_location', 'e_kind', 's_content', 'i_order', 's_type', 's_config'));
    }

    /**
     * @return \Widget
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     *
     * @access public
     *
     * @param string $location
     *
     * @return array
     * @since  unknown
     */
    public function findByLocation($location)
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->where('s_location', $location)
                // i_order ascending, then primary key ascending as a tiebreak so
                // legacy rows (all i_order = 0) keep their current relative order.
                ->orderBy('i_order', 'ASC')
                ->orderBy($this->getPrimaryKey(), 'ASC')
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Every location that currently holds at least one widget.
     *
     * Used by the admin to spot widgets stranded in a section the active theme no
     * longer declares — a theme switch does not delete them, it just leaves them
     * with nowhere to render.
     *
     * @return string[]
     */
    public function distinctLocations()
    {
        // New code uses the query builder rather than the legacy DAO this model is
        // otherwise written against.
        $rows = osc_db_table($this->getTableName())
            ->select('s_location')
            ->groupBy('s_location')
            ->get();

        $locations = array();
        foreach ($rows as $row) {
            if (isset($row['s_location']) && $row['s_location'] !== '') {
                $locations[] = (string)$row['s_location'];
            }
        }

        return $locations;
    }

    /**
     * Persist a new ordering for a set of widgets, assigning each id its position
     * in $orderedIds as i_order. Runs inside a single transaction: on any failure
     * the whole reorder is rolled back and false is returned.
     *
     * @param int[] $orderedIds Widget primary keys in the desired order.
     *
     * @return bool True on commit, false on rollback.
     */
    public function reorder(array $orderedIds)
    {
        $table = DB_TABLE_PREFIX . 't_widget';
        try {
            osc_db_transaction(static function () use ($orderedIds, $table) {
                $position = 0;
                foreach ($orderedIds as $id) {
                    osc_db_table($table)
                        ->where('pk_i_id', (int) $id)
                        ->update(array('i_order' => $position));
                    $position++;
                }
            });
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Next i_order value for a location: MAX(i_order) + 1, or 0 when the location
     * has no widgets yet. Used so a newly added widget appends to the end.
     *
     * @param string $location
     *
     * @return int
     */
    public function getNextOrder($location)
    {
        $max = osc_db_scalar(
            'SELECT MAX(i_order) FROM ' . DB_TABLE_PREFIX . 't_widget WHERE s_location = ?',
            array($location)
        );

        return $max === null ? 0 : (int) $max + 1;
    }

    /**
     *
     * @access public
     *
     * @param string $description
     *
     * @return array
     * @since  3.3.3+
     */
    public function findByDescription($description)
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->where('s_description', $description)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }
}

/* file end: ./oc-includes/osclass/model/Widget.php */
