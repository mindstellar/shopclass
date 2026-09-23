<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;
use mindstellar\migration\SchemaProbes;

/**
 * The location admin lists one level at a time: a region's cities or a country's regions,
 * filtered by a name prefix, sorted by name, one page at a time. The single-column parent
 * indexes find the rows but not in name order, so every page sorted the whole level.
 *
 * (parent, s_name) covers the parent match, the prefix LIKE and the ORDER BY in one walk.
 *
 * Idempotent: guarded by an information_schema lookup, so a re-run after an interrupted
 * upgrade is safe.
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    /** Table => [index name, column list]. */
    private const INDEXES = array(
        't_city'   => array('idx_region_name', 'fk_i_region_id, s_name'),
        't_region' => array('idx_country_name', 'fk_c_country_code, s_name'),
    );

    /**
     * Add the (parent, s_name) indexes to t_city and t_region, unless already there.
     *
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        foreach (self::INDEXES as $table => [$index, $columns]) {
            $name = DB_TABLE_PREFIX . $table;
            if (!$this->indexExists($conn, $name, $index)) {
                $conn->execute('CREATE INDEX ' . $index . ' ON ' . $name . ' (' . $columns . ')');
            }
        }
    }

};
