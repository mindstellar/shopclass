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
 * Fold the core feature preferences that had been given their own sections back
 * into the shared `osclass` section, the project convention that keeps all core
 * settings in one place. Sitemap, spam moderation, cleanup, stats and the admin
 * log each seeded a dedicated section; this relocates their keys, preserving
 * whatever value an admin set.
 *
 * Scoped by explicit (section, key) rather than by whole section: `cleanup`,
 * `stats` and `log` are generic names a third-party plugin could also use, and a
 * blind section move would scoop up its rows. Theme/plugin sections are never
 * touched — those legitimately keep their own namespace.
 *
 * Idempotent and collision-safe: UPDATE IGNORE skips a key whose target
 * (osclass, name) already exists (the unique key blocks it), and the follow-up
 * DELETE drops the old-section leftover, so the osclass value wins and a re-run
 * after an interrupted upgrade converges without clobbering the live setting.
 */
return new class () implements MigrationInterface {
    /**
     * Old section => the core keys that lived under it.
     *
     * @var array<string, string[]>
     */
    private const MAP = array(
        'sitemap' => array(
            'sitemap_number', 'sitemap_categories', 'sitemap_pages', 'sitemap_cities',
            'sitemap_regions', 'sitemap_countries', 'sitemap_cat_regions', 'sitemap_cat_city',
            'custom_urls',
        ),
        'moderation' => array(
            'keyword_spam_enabled', 'keyword_spam_hard_block', 'report_autoblock', 'report_threshold',
        ),
        'cleanup' => array(
            'batch_limit',
            'enabled_blocked', 'enabled_expired', 'enabled_inactive_listings',
            'enabled_inactive_users', 'enabled_reported', 'enabled_spam',
            'days_blocked', 'days_expired', 'days_inactive_listings',
            'days_inactive_users', 'days_spam',
        ),
        'stats' => array('item_views_enabled', 'count_bot_views', 'item_stats_retention_days'),
        'log'   => array('admin_log_enabled', 'admin_log_retention_days'),
    );

    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_preference';

        foreach (self::MAP as $section => $names) {
            $in     = implode(',', array_fill(0, count($names), '?'));
            $params = array_merge(array($section), $names);

            $conn->execute(
                'UPDATE IGNORE ' . $table
                . " SET s_section = 'osclass' WHERE s_section = ? AND s_name IN ($in)",
                $params
            );
            $conn->execute(
                'DELETE FROM ' . $table . " WHERE s_section = ? AND s_name IN ($in)",
                $params
            );
        }
    }
};
