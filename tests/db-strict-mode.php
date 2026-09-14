<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Every core aggregate, run on a connection with the strict SQL modes ON.
 *
 * ConnectionManager::setSQLMode() strips five modes from every connection —
 * NO_ZERO_DATE, ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES, STRICT_ALL_TABLES and
 * TRADITIONAL — unless OSC_DB_STRICT_MODE is defined. ONLY_FULL_GROUP_BY is the
 * dangerous one: it does not degrade a query, it rejects it outright, and every
 * aggregate below swallows the failure and returns an empty array. A site that
 * turns strict mode on therefore loses its alert emails, its custom fields, its
 * latest-search list, its dashboard charts and its footer links, with nothing in
 * any log to say so. That is what this file exists to catch.
 *
 * The strict modes are SET on the session by hand rather than through the
 * constant, for two reasons. A connection is opened once, so the constant cannot
 * be flipped mid-process; and the CI database is MariaDB, whose default sql_mode
 * omits ONLY_FULL_GROUP_BY entirely — relying on the server's own defaults would
 * make the whole file pass without ever exercising the thing it is about. The
 * modes are asserted to be in force before any pin runs.
 *
 * MariaDB matters for a second reason: it does not implement MySQL's functional
 * dependency detection, so "GROUP BY <primary key>" with whole rows selected is
 * legal on MySQL 5.7+ and rejected on MariaDB. Every query pinned here is written
 * to need no such inference.
 *
 * Usage:  php tests/db-strict-mode.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 *         (default 127.0.0.1:33061 root/root — the throwaway container)
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_db_strict_mode');

/** Every session this file opens is set to these by hand — see the note above. */
const STRICT_SESSION_MODES_SQL = "SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,"
    . "STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'";

/*
 * osc_search_footer_links() lives in functions.php, which registers a hook at
 * load and so needs a plugins path, and builds URLs from the base one. Both
 * stand-ins are guarded and return what the real helpers return in a test
 * process. They cannot come from hDefines.php: that file declares
 * osc_uploads_path() with no function_exists guard and the scratch bootstrap has
 * already defined a stand-in for it.
 */
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';

/* ----------------------------------------------------------------------------
 * Fixtures, seeded raw. Everything is in place before the first Preference read,
 * so the singleton's one-shot table load sees rewriteEnabled.
 *
 * The admin session goes strict FIRST, so the seeds themselves are written under
 * the modes the file is about. Seeded on a relaxed connection they would prove
 * nothing about whether the shapes core writes fit the columns that hold them.
 * ------------------------------------------------------------------------- */
$prefix = DB_TABLE_PREFIX;

$admin->query(STRICT_SESSION_MODES_SQL);

seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_preference (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, 'BOOLEAN')",
    'sss',
    array('osclass', 'rewriteEnabled', '1')
);

seed_locale($admin);
seed_country($admin, 'US', 'United States');
seed_currency($admin);
$regionId = seed_region($admin, 'US', 'Alpha');
$cityId   = seed_city($admin, $regionId, 'Springfield');

$catParent = seed_category($admin, 'Motors');
$catChild  = seed_category($admin, 'Cars', $catParent);

$userId = seed_user($admin);
$itemA  = seed_item($admin, $catChild, $userId, 'Listing A');
$itemB  = seed_item($admin, $catChild, $userId, 'Listing B');

// seed_item() writes a country-only location row; the footer links only consider
// listings that carry both a region and a city.
$admin->query(
    "UPDATE {$prefix}t_item_location SET fk_i_region_id = $regionId, s_region = 'Alpha',"
    . " fk_i_city_id = $cityId, s_city = 'Springfield'"
);

// A second description row for one listing, in another locale. This is what made
// the dashboard's latest_items() need a GROUP BY in the first place.
seed_locale($admin, 'es_ES', 'Espanol');
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description)
     VALUES (?, 'es_ES', ?, ?)",
    'iss',
    array($itemA, 'Anuncio A', 'Cuerpo')
);

seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_item_comment
     (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active)
     VALUES (?, NOW(), 'Nice', 'Ann', 'ann@example.test', 'Interested', 1, 1)",
    'i',
    array($itemA)
);

seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_item_stats_daily (dt_date, i_bucket, i_num_views, i_num_spam) VALUES (CURDATE(), 0, 12, 1)",
    '',
    array()
);

/** Two alerts share one saved search, so the grouping is observable. */
$seedAlert = static function (string $email, string $search, string $type) use ($admin, $prefix): int {
    return seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_alerts (s_email, fk_i_user_id, s_search, s_secret, b_active, e_type, dt_date)
         VALUES (?, NULL, ?, ?, 1, ?, NOW())",
        'ssss',
        array($email, $search, md5($email . $search), $type)
    );
};
$seedAlert('one@example.test', '{"q":"cars"}', 'DAILY');
$seedAlert('two@example.test', '{"q":"cars"}', 'DAILY');
$seedAlert('three@example.test', '{"q":"boats"}', 'DAILY');
$seedAlert('four@example.test', '{"q":"planes"}', 'WEEKLY');

$seedSearch = static function (string $date, string $term) use ($admin, $prefix): void {
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_latest_searches (d_date, s_search) VALUES (?, ?)",
        'ss',
        array($date, $term)
    );
};
$seedSearch('2026-01-01 00:00:00', 'car');
$seedSearch('2026-01-02 00:00:00', 'car');
$seedSearch('2026-01-03 00:00:00', 'bike');

/*
 * Custom fields: one loose field on the parent category (inherited by the child),
 * one loose field on the child, and one field placed in a form assigned to the
 * child. findByCategory() has to union all three and collapse them to one row per
 * field — which is exactly the shape ONLY_FULL_GROUP_BY rejects.
 */
$seedField = static function (string $name, string $slug, int $position) use ($admin, $prefix): int {
    return seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_meta_fields (s_name, s_slug, e_type, i_position) VALUES (?, ?, 'TEXT', ?)",
        'ssi',
        array($name, $slug, $position)
    );
};
$fieldLoose     = $seedField('Mileage', 'mileage', 1);
$fieldInherited = $seedField('Colour', 'colour', 0);
$fieldGrouped   = $seedField('Doors', 'doors', 2);

seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)",
    'ii',
    array($catChild, $fieldLoose)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)",
    'ii',
    array($catParent, $fieldInherited)
);

$groupId = seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_group (s_name, s_slug, i_position) VALUES ('Vehicle', 'vehicle', 5)",
    '',
    array()
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_group_fields (fk_i_group_id, fk_i_field_id, i_position) VALUES (?, ?, 0)",
    'ii',
    array($groupId, $fieldGrouped)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_group_categories (fk_i_group_id, fk_i_category_id) VALUES (?, ?)",
    'ii',
    array($groupId, $catChild)
);

/* ----------------------------------------------------------------------------
 * setSQLMode()'s default: the five modes it takes off every connection.
 * ------------------------------------------------------------------------- */
harness_section('setSQLMode — what a default install strips');

$reflected = new ReflectionClass('mindstellar\database\ConnectionManager');
$stripList = $reflected->getProperty('incompatible_modes');
$stripList->setAccessible(true);
pin(
    'the strip list is exactly these five modes',
    array('NO_ZERO_DATE', 'ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES', 'TRADITIONAL'),
    $stripList->getValue($reflected->newInstanceWithoutConstructor())
);

/*
 * The singleton's own session, before this file touches it. The server is asked what
 * it had on first: a server with none of these configured would make the pin below
 * pass for the wrong reason.
 *
 * Both branches of setSQLMode() are covered, whichever way the process was started.
 * Run plainly the constant is undefined and the modes must be gone; run with
 * OSC_DB_STRICT_MODE already defined (which is how a new install runs) the method
 * returns early and every one of them must have survived.
 */
$modeList    = $stripList->getValue($reflected->newInstanceWithoutConstructor());
$serverModes = array_map(
    'strtoupper',
    explode(',', (string) $admin->query('SELECT @@GLOBAL.sql_mode AS m')->fetch_assoc()['m'])
);
$strippable = array_values(array_intersect($serverModes, $modeList));
check(
    'the server itself has at least one strippable mode on, so the next pin means something',
    $strippable !== array(),
    implode(',', $serverModes)
);
$survivors = array_values(array_intersect(harness_sql_mode(), $modeList));
if (defined('OSC_DB_STRICT_MODE') && OSC_DB_STRICT_MODE) {
    pin('with the constant set, the server keeps every one of them', $strippable, $survivors);
} else {
    pin('with the constant unset, none of them survive on a connection', array(), $survivors);
}

/* ----------------------------------------------------------------------------
 * From here on the session is strict, whatever the server's defaults are.
 * ------------------------------------------------------------------------- */
harness_section('the strict session under test');

DBConnectionClass::newInstance()->getOsclassDb()->query(STRICT_SESSION_MODES_SQL);

$strictModes = harness_sql_mode();
check('ONLY_FULL_GROUP_BY is in force', in_array('ONLY_FULL_GROUP_BY', $strictModes, true), implode(',', $strictModes));
check('STRICT_TRANS_TABLES is in force', in_array('STRICT_TRANS_TABLES', $strictModes, true), implode(',', $strictModes));
check('STRICT_ALL_TABLES is in force', in_array('STRICT_ALL_TABLES', $strictModes, true), implode(',', $strictModes));
check('harness_strict_writes() agrees', harness_strict_writes(), implode(',', $strictModes));

// The seeding connection, asked after the fact. Moving the SET back below the
// fixtures would leave every pin in this file passing while nothing above had
// actually been written strict.
$seedModes = array_map(
    'strtoupper',
    explode(',', (string) $admin->query('SELECT @@SESSION.sql_mode AS m')->fetch_assoc()['m'])
);
check(
    'the fixtures above were written on a strict session too',
    in_array('STRICT_ALL_TABLES', $seedModes, true) && in_array('ONLY_FULL_GROUP_BY', $seedModes, true),
    implode(',', $seedModes)
);

/* ----------------------------------------------------------------------------
 * Alerts. findByTypeGroup() feeds the alert cron: an empty return is a silent
 * "no subscriber gets mail, ever".
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findByTypeGroup');

$daily = Alerts::newInstance()->findByTypeGroup('DAILY', true);
check('two saved searches survive the grouping', count($daily) === 2, describe($daily));
$searches = array_map(static function ($row) {
    return $row['s_search'];
}, $daily);
sort($searches);
pin('one row per distinct search', array('{"q":"boats"}', '{"q":"cars"}'), $searches);
check('the rows are whole alert rows, not just the grouped column', isset($daily[0]['pk_i_id'], $daily[0]['e_type']), describe($daily[0]));
pin('an unmatched type still groups to an empty array', array(), Alerts::newInstance()->findByTypeGroup('CUSTOM', true));

/* ----------------------------------------------------------------------------
 * Custom fields. An empty return here is a listing form with no custom fields on
 * it and a search page with nothing to filter by.
 * ------------------------------------------------------------------------- */
harness_section('Field::findByCategory');

$fields = Field::newInstance()->findByCategory($catChild);
check('the child category sees three fields', count($fields) === 3, describe(array_column($fields, 's_slug')));
pin(
    'loose fields sort before grouped ones, then by field position',
    array('colour', 'mileage', 'doors'),
    array_column($fields, 's_slug')
);
check('each row carries the field columns', isset($fields[0]['pk_i_id'], $fields[0]['e_type']), describe($fields[0]));
pin('a category with no fields returns an empty array', array(), Field::newInstance()->findByCategory($catParent + 9000));

harness_section('FieldGroup::findByCategory');

$groups = FieldGroup::newInstance()->findByCategory($catChild);
check('the form assigned to the category is found once', count($groups) === 1, describe($groups));
pin('and it is the right one', 'vehicle', $groups[0]['s_slug'] ?? null);

/* ----------------------------------------------------------------------------
 * Latest searches. These return false rather than an empty array on failure, so
 * the admin screen and the theme widget both degrade to nothing.
 * ------------------------------------------------------------------------- */
harness_section('LatestSearches');

$model   = LatestSearches::newInstance();
$grouped = $model->getSearches(20);
check('three rows collapse into two groups', is_array($grouped) && count($grouped) === 2, describe($grouped));
pin('the three selected columns are unchanged', array('d_date', 's_search', 'i_total'), array_keys($grouped[0]));
pin('the most recently seen term sorts first', 'bike', $grouped[0]['s_search']);
pin('the repeated term carries its count', '2', $grouped[1]['i_total']);
pin('and reports the latest date it was seen, not an arbitrary one', '2026-01-02 00:00:00', $grouped[1]['d_date']);

$byDate = $model->getSearchesByDate(strtotime('2026-01-02 00:00:00'), 20);
check('the date-filtered form also groups', is_array($byDate) && count($byDate) === 2, describe($byDate));

check('purgeNumber() runs its grouped read', is_int($model->purgeNumber(1)), describe($model->purgeNumber(1)));

/* ----------------------------------------------------------------------------
 * Dashboard statistics. Every one of these is wrapped in a catch that returns an
 * empty array, so a rejected query draws an empty chart rather than an error.
 * ------------------------------------------------------------------------- */
harness_section('Stats — the bucketed counts, all three granularities');

$stats = Stats::newInstance();
$from  = '2000-01-01 00:00:00';

foreach (array('day', 'week', 'month') as $bucket) {
    check("new_users_count($bucket) returns its row", count($stats->new_users_count($from, $bucket)) === 1, $bucket);
    check("new_items_count($bucket) returns its row", count($stats->new_items_count($from, $bucket)) === 1, $bucket);
    check("new_comments_count($bucket) returns its row", count($stats->new_comments_count($from, $bucket)) === 1, $bucket);
    check("new_reports_count($bucket) returns its row", count($stats->new_reports_count($from, $bucket)) === 1, $bucket);
    check("new_alerts_count($bucket) returns its row", count($stats->new_alerts_count($from, $bucket)) === 1, $bucket);
    check(
        "new_subscribers_count($bucket) returns its row",
        count($stats->new_subscribers_count($from, $bucket)) === 1,
        $bucket
    );
}

pin('the bucketed count is the number of seeded users', '1', $stats->new_users_count($from, 'day')[0]['num']);
pin('and of seeded listings', '2', $stats->new_items_count($from, 'day')[0]['num']);
pin('the daily rollup is summed, not counted', '12', $stats->new_reports_count($from, 'day')[0]['views']);
pin('alerts are counted per bucket', '4', $stats->new_alerts_count($from, 'day')[0]['num']);
pin('subscribers count distinct addresses', '4', $stats->new_subscribers_count($from, 'day')[0]['num']);

harness_section('Stats — the ungrouped and derived-table reads');

check('users_by_country still runs', count($stats->users_by_country()) === 1, describe($stats->users_by_country()));
check('users_by_region still runs', count($stats->users_by_region()) === 1, describe($stats->users_by_region()));
check('items_by_user averages over its derived table', count($stats->items_by_user()) === 1, describe($stats->items_by_user()));
check('latest_users still runs', count($stats->latest_users()) === 1, describe($stats->latest_users()));
check('latest_comments still runs', count($stats->latest_comments()) === 1, describe($stats->latest_comments()));

harness_section('Stats::latest_items — one row per listing despite two locales');

$latest = $stats->latest_items();
check('two listings, not three rows', count($latest) === 2, describe(array_column($latest, 'pk_i_id')));
check(
    'the row still carries listing, location and description columns',
    isset($latest[0]['pk_i_id'], $latest[0]['s_title'], $latest[0]['s_country']),
    describe(array_slice(array_keys($latest[0]), 0, 12))
);

/* ----------------------------------------------------------------------------
 * Theme footer links. A public helper: bender renders it on search and on a
 * user's listing list, and an empty return simply removes the block.
 * ------------------------------------------------------------------------- */
harness_section('osc_search_footer_links');

require_once ABS_PATH . 'oc-includes/osclass/functions.php';

$links = osc_search_footer_links();
check('the region with listings is returned', count($links) === 1, describe($links));
$link = $links[0] ?? array();
pin('both listings are counted into it', '2', $link['total'] ?? null);
pin('the region id is carried through for the URL', (string) $regionId, $link['fk_i_region_id'] ?? null);
pin('so is the city id', (string) $cityId, $link['fk_i_city_id'] ?? null);
pin('so is the category id', (string) $catChild, $link['fk_i_category_id'] ?? null);
pin('and the region name the link is titled with', 'Alpha', $link['s_region'] ?? null);

/* ----------------------------------------------------------------------------
 * The install and the upgrade, both replayed on their own strict connections.
 *
 * A new install is the case that actually gets the strict modes, so the schema it
 * builds and the rows it seeds have to survive them. The upgrade path matters for
 * the site that opts in and then upgrades: the baseline is imported relaxed,
 * because that is how the site it stands for was built, and only the migrations
 * are made to run strict.
 *
 * Each of these opens its own database and its own connection; none of them touch
 * the fixtures above.
 * ------------------------------------------------------------------------- */
harness_section('the bundled schema and seed data, imported under strict');

$installerDir = ABS_PATH . 'oc-includes/osclass/installer/';

/** A freshly created database plus a Connection whose session is strict. */
$strictConnection = static function (string $name) use ($admin): \mindstellar\database\Connection {
    $admin->query("DROP DATABASE IF EXISTS `$name`");
    $admin->query("CREATE DATABASE `$name` DEFAULT CHARACTER SET utf8mb4");
    register_shutdown_function(static function () use ($admin, $name) {
        $admin->query("DROP DATABASE IF EXISTS `$name`");
    });

    $handle = (new DBConnectionClass(DB_HOST, DB_USER, DB_PASSWORD, $name))->getOsclassDb();
    $handle->query(STRICT_SESSION_MODES_SQL);

    return new \mindstellar\database\Connection($handle);
};

/** Run a script and report the message rather than the exception object. */
$importUnder = static function (\mindstellar\database\Connection $conn, string $sql): string {
    try {
        $conn->executeScript($sql);

        return '';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};

$freshConn = $strictConnection('osc_db_strict_fresh');
pin(
    'struct.sql builds the whole schema with the strict modes on',
    '',
    $importUnder($freshConn, file_get_contents($installerDir . 'struct.sql'))
);

// The order the installer uses: the site language row is written before the seed
// files, which carry rows pointing at it.
pin(
    'the site language row the installer writes goes in',
    '',
    $importUnder($freshConn, "INSERT INTO " . DB_TABLE_PREFIX . "t_locale
        (pk_c_code, s_name, s_short_name, s_description, s_version, s_author_name, s_author_url,
         s_currency_format, s_date_format, b_enabled, b_enabled_bo)
        VALUES ('en_US', 'English (US)', 'EN', 'English (US)', '1.0', 'Mindstellar', '',
                '%n%c', 'F j, Y', 1, 1)")
);

pin(
    'basic_data.sql and pages.sql seed clean too',
    '',
    $importUnder(
        $freshConn,
        file_get_contents($installerDir . 'basic_data.sql') . file_get_contents($installerDir . 'pages.sql')
    )
);

check(
    'and the seeded preferences are really there, so the pins above are not passing on an empty script',
    (int) $freshConn->scalar('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_preference') > 20
);

harness_section('every migration from the oldest baseline, applied under strict');

$baselines = glob(ABS_PATH . 'tests/fixtures/schema-baselines/*.sql');
sort($baselines, SORT_STRING);
check('there is at least one baseline to upgrade from', $baselines !== array(), describe($baselines));

foreach ($baselines as $index => $baselineFile) {
    $label   = basename($baselineFile, '.sql');
    $upgrade = $strictConnection('osc_db_strict_up' . $index);

    // The baseline is what a site of that release already had, built on a relaxed
    // connection; importing it strict would be testing history, not the upgrade.
    $upgrade->handle()->query("SET SESSION sql_mode=''");
    $imported = $importUnder($upgrade, file_get_contents($baselineFile));
    $upgrade->handle()->query(STRICT_SESSION_MODES_SQL);

    pin("the $label baseline imports", '', $imported);

    $runner = new \mindstellar\migration\MigrationRunner($upgrade, $installerDir . 'migrations');
    $runner->ensureLedger();
    $result = $runner->run();
    pin("every migration over $label applies with the strict modes on", null, $result['failed']);
    check(
        "and there were migrations to apply, not an empty run over $label",
        count($result['applied']) > 0,
        describe(count($result['applied']))
    );
}

exit(harness_result());

/* file end: ./tests/db-strict-mode.php */
