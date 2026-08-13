<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;

/**
 * Make the location import's source id unique per country rather than globally.
 *
 * i_source_id records which row a location came from upstream, and 0023 made it unique
 * across the whole table. That holds only while every row came from one source. It stops
 * holding the moment the published dataset changes: the previous one numbered cities
 * 57,584-147,939, and the Wikidata ids replacing them start at 1, so the two ranges
 * overlap almost completely. Wikidata's id for Castlewellan in County Down is 58126,
 * which the old data had already issued to Bangarmau in Uttar Pradesh.
 *
 * With a global unique key those two rows cannot coexist, so importing the new data for
 * one country fails outright on ids another country already holds. Scoped to the country
 * they coexist happily, which is what the identifier actually means -- unique within the
 * dataset for that country, never a global handle.
 *
 * This pairs with LocationImporter::citiesBySourceId(), which looks a row up within the
 * country for the same reason. Before both changes the importer matched purely on the
 * integer and quietly renamed the Indian city, moved it under a British region and left
 * its country code pointing at India.
 *
 * Widening a unique key never rejects rows the narrower one accepted, so this cannot fail
 * on existing data. Idempotent: the key is only rebuilt when it is still the single-column
 * one.
 */
return new class () implements MigrationInterface {
    /** table => the columns the key should cover, in order. */
    private const KEYS = array(
        't_region' => 'uq_region_source',
        't_city'   => 'uq_city_source',
    );

    public function up(Connection $conn): void
    {
        foreach (self::KEYS as $suffix => $keyName) {
            $table = DB_TABLE_PREFIX . $suffix;

            if (!$this->indexExists($conn, $table, $keyName)) {
                // Never created, or already dropped by hand. Add it in its correct shape.
                $conn->execute(
                    'ALTER TABLE ' . $table
                    . ' ADD UNIQUE KEY ' . $keyName . ' (fk_c_country_code, i_source_id)'
                );
                continue;
            }

            if ($this->indexColumnCount($conn, $table, $keyName) > 1) {
                continue; // already scoped
            }

            $conn->execute('ALTER TABLE ' . $table . ' DROP INDEX ' . $keyName);
            $conn->execute(
                'ALTER TABLE ' . $table
                . ' ADD UNIQUE KEY ' . $keyName . ' (fk_c_country_code, i_source_id)'
            );
        }

        // t_city's foreign key on fk_c_country_code was created while the unique key
        // still covered i_source_id alone, so MySQL added its own index to back it. The
        // widened key now leads on that column and backs the foreign key by itself, and
        // struct.sql declares no separate index — a fresh install therefore has one
        // index here where an upgraded one would keep two. Dropping the leftover is what
        // makes the two agree. Ordered after the ADD above so the foreign key is never
        // left without an index to stand on. (t_region declares its index explicitly and
        // keeps it, so it is untouched.)
        $city = DB_TABLE_PREFIX . 't_city';
        if ($this->indexExists($conn, $city, 'fk_c_country_code')) {
            $conn->execute('ALTER TABLE ' . $city . ' DROP INDEX fk_c_country_code');
        }
    }

    /**
     * Whether $index exists on $table in the current database.
     */
    private function indexExists(Connection $conn, string $table, string $index): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            array($table, $index)
        );

        return (int) $count > 0;
    }

    /**
     * How many columns $index covers. One means it is still the global form.
     */
    private function indexColumnCount(Connection $conn, string $table, string $index): int
    {
        return (int) $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            array($table, $index)
        );
    }
};
