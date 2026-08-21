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
 * Evict the cached location manifest from t_preference.
 *
 * Preferences are read whole on every request, front-end page views included, so this
 * one row put the catalog's per-country checksums -- upwards of 120KB on the published
 * catalog, more than the rest of the table put together -- into every page view, to
 * spare an admin screen a network call it makes a handful of times in a site's life.
 *
 * The cache now lives in a file beside the uploads and holds only the fields an import
 * reads. Deleting the row is all that is needed: nothing looks for it any more, and the
 * file repopulates the first time an admin opens the locations screen.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $conn->execute(
            'DELETE FROM ' . DB_TABLE_PREFIX . 't_preference'
            . ' WHERE s_section = ? AND s_name = ?',
            array('osclass', 'location_catalog_cache')
        );

        // The freshness stamp is kept but reset, so the first read after the upgrade
        // refetches rather than trusting a stamp whose cache no longer exists.
        $conn->execute(
            'UPDATE ' . DB_TABLE_PREFIX . 't_preference SET s_value = ?'
            . ' WHERE s_section = ? AND s_name = ?',
            array('0', 'osclass', 'location_catalog_checked')
        );
    }
};

/* file end: ./oc-includes/osclass/installer/migrations/0028_location_catalog_cache_out_of_preferences.php */
