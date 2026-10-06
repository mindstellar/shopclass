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
 * The market catalogue cache lives in the `market` key-value group, not in t_preference,
 * which every request loads. Migration 0065 moves an old cache over and can be re-run.
 *
 * Usage:  php tests/models/market-cache.php        (standalone, own scratch database)
 *         php tests/run-models.php market-cache    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;

$admin = scratchdb_session('osc_models_market_cache');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hKv.php';
$prefs = DB_TABLE_PREFIX . 't_preference';
$kv    = DB_TABLE_PREFIX . 't_key_value';
$conn  = Connection::getInstance();
$count = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];
$migrate = static function () use ($conn): void {
    (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0065_market_cache_to_key_value.php')->up($conn);
};

harness_section('migration 0065');
$admin->query("DELETE FROM $prefs WHERE s_name LIKE 'market\\_%'");
$admin->query("INSERT INTO $prefs VALUES ('osclass', 'market_plugins_index_json', '{\"a\":1}', 'STRING'), ('osclass', 'market_themes_etag', 'W/\"x\"', 'STRING'), ('osclass', 'market_place', 'keep', 'STRING')");
$migrate();
pin('the cache rows leave preferences', 0, $count("SELECT COUNT(*) FROM $prefs WHERE s_name IN ('market_plugins_index_json', 'market_themes_etag')"));
pin('a preference that only starts with market stays', 1, $count("SELECT COUNT(*) FROM $prefs WHERE s_name = 'market_place'"));
pin('the cache reads back from the market group', '{"a":1}', osc_kv_get('market', 'market_plugins_index_json'));
pin('and so does a header value', 'W/"x"', osc_kv_get('market', 'market_themes_etag'));
$migrate();
pin('a second run changes nothing', 2, $count("SELECT COUNT(*) FROM $kv WHERE s_group = 'market'"));

harness_section('Catalog');
$src = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/market/Catalog.php');
check('Catalog no longer reads or writes preferences', strpos($src, 'osc_get_preference') === false && strpos($src, 'osc_set_preference') === false);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
