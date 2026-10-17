<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The media library's reads: the summary counts files by owner and storage, and search matches
 * the file name, alone or with a filter.
 *
 * Usage:  php tests/models/media-query.php       (standalone, own scratch database)
 *         php tests/run-models.php media-query   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\database\Db;
use mindstellar\storage\MediaQuery;

api_admin_boot('osc_models_media_query');

$res = DB_TABLE_PREFIX . 't_resource';
Db::execute("DELETE FROM $res");
Db::execute('INSERT INTO ' . DB_TABLE_PREFIX . "t_pages (s_internal_name, b_indelible, dt_pub_date) VALUES ('media-test', 0, NOW())");
$pageId = (int) Db::scalar('SELECT MAX(pk_i_id) FROM ' . DB_TABLE_PREFIX . 't_pages');

$add = static function (string $type, int $owner, string $name): int {
    Db::execute(
        'INSERT INTO ' . DB_TABLE_PREFIX . "t_resource (s_owner_type, i_owner_id, s_name, s_extension, s_content_type, s_path, s_storage, dt_created)"
        . " VALUES (?, ?, ?, 'jpg', 'image/jpeg', 'oc-content/uploads/0/', 'local', NOW())",
        array($type, $owner, $name)
    );

    return (int) Db::scalar('SELECT MAX(pk_i_id) FROM ' . DB_TABLE_PREFIX . 't_resource');
};
$add(\ItemResource::OWNER, 999991, 'listing-photo');
$add('user', 999992, 'user-photo');
$library  = $add('library', 0, 'logo-upload');
$add('page', $pageId, 'page-image');

harness_section('Summary');
$summary = MediaQuery::summary();
pin('counts every file', 4, $summary['total']);
pin('by owner', array('item' => 1, 'library' => 1, 'page' => 1, 'user' => 1), $summary['owners']);
pin('by storage', array('local' => 4), $summary['storage']);

harness_section('Search');
$found = array_map(static fn (array $r): int => (int) $r['id'], MediaQuery::page('all', 1, 25, 'logo')['rows']);
pin('matches the file name', array($library), $found);
pin('a wildcard is searched as text', 0, MediaQuery::page('all', 1, 25, '%')['total']);
pin('search and a filter combine', 0, MediaQuery::page('user', 1, 25, 'logo')['total']);

exit(harness_result());
