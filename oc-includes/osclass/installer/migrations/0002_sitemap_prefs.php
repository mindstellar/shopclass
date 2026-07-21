<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\migration\MigrationInterface;

/**
 * Seed the core sitemap preferences (section `sitemap`) and adopt any values an
 * existing install already set under the bundled theme's `shopclass_theme`
 * section.
 *
 * Both steps are idempotent. Seeding uses INSERT IGNORE against the
 * (s_section, s_name) unique key, so a value already present in the `sitemap`
 * section is never overwritten — including on a re-run after an interrupted
 * upgrade. The adoption step reads the theme's values first and seeds those in
 * place of the hard defaults, so an operator who had, say, city sitemaps enabled
 * in the theme keeps them enabled in core.
 *
 * The theme-section rows are read, not deleted: an install whose theme has not
 * yet deferred to core still reads them, and leaving them in place keeps that
 * theme working until it opts in. Once both `sitemap` and `shopclass_theme` hold
 * the value, the `sitemap` section is authoritative for the core generator.
 */
return new class implements MigrationInterface {
    /** Legacy source section the bundled theme used for these preferences. */
    private const LEGACY_SECTION = 'shopclass_theme';

    /** Target section the core generator reads. */
    private const TARGET_SECTION = 'sitemap';

    /**
     * key => [default value, e_type]. The defaults mirror installer/basic_data.sql
     * so a fresh install and an upgraded install converge on the same seed.
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const PREFERENCES = array(
        'sitemap_number'      => array('5000', 'INTEGER'),
        'sitemap_cities'      => array('0', 'BOOLEAN'),
        'sitemap_regions'     => array('0', 'BOOLEAN'),
        'sitemap_countries'   => array('0', 'BOOLEAN'),
        'sitemap_cat_regions' => array('0', 'BOOLEAN'),
        'sitemap_cat_city'    => array('0', 'BOOLEAN'),
        'custom_urls'         => array('', 'STRING'),
    );

    public function up(DBCommandClass $comm): void
    {
        $table  = DB_TABLE_PREFIX . 't_preference';
        $legacy = $this->sectionValues($comm, $table, self::LEGACY_SECTION);

        foreach (self::PREFERENCES as $name => $spec) {
            [$default, $type] = $spec;
            // Prefer the value the theme already held; fall back to the default.
            $value = array_key_exists($name, $legacy) ? $legacy[$name] : $default;

            $sql = 'INSERT IGNORE INTO ' . $table
                . ' (s_section, s_name, s_value, e_type) VALUES ('
                . $comm->escape(self::TARGET_SECTION) . ', '
                . $comm->escape($name) . ', '
                . $comm->escape($value) . ', '
                . $comm->escape($type) . ')';

            if ($comm->query($sql) === false) {
                throw new RuntimeException('sitemap prefs migration: failed to seed ' . $name);
            }
        }
    }

    /**
     * All (s_name => s_value) rows for one preference section.
     *
     * @return array<string, string>
     */
    private function sectionValues(DBCommandClass $comm, string $table, string $section): array
    {
        $result = $comm->query(
            'SELECT s_name, s_value FROM ' . $table
            . ' WHERE s_section = ' . $comm->escape($section)
        );
        if (!is_object($result)) {
            return array();
        }

        $values = array();
        foreach ($result->result('array') as $row) {
            $values[$row['s_name']] = $row['s_value'];
        }

        return $values;
    }
};
