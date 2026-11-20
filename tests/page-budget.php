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
 * Speed budget for public pages: a fresh install with 300 listings is served by PHP's own web
 * server with a small test theme, and the home, search, category and listing pages may include
 * no more files, send no more database statements and no more cache commands than
 * tests/page-budget.json allows. Counts, not timings, so a slow runner cannot fail it.
 * Without the object cache it runs once; with OSC_TEST_REDIS set it also runs on Valkey.
 *
 * Usage:  php tests/page-budget.php            check against the stored budget
 *         php tests/page-budget.php --update   store what the pages cost now, after a change
 *                                              that is meant to cost more (or now costs less)
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/scratchdb.php';

const BUDGET_FILE = __DIR__ . '/page-budget.json';
// Files may grow by a couple before the test fails, so a small helper split is not a build break.
const FILE_SLACK = 2;
const QUERY_SLACK = 0;
const TRIP_SLACK = 1;
const PAGES = array(
    'home'     => '/',
    'search'   => '/index.php?page=search&sPattern=listing',
    'category' => '/index.php?page=search&sCategory=%category%',
    'listing'  => '/index.php?page=item&id=%item%',
);

$update  = in_array('--update', $argv, true);
$scratch = 'osc_page_budget';
$admin   = scratchdb_bootstrap($scratch);
$host    = getenv('DRIFT_DB_HOST') ?: '127.0.0.1';
$port    = (int) (getenv('DRIFT_DB_PORT') ?: 33061);
$pass    = getenv('DRIFT_DB_PASS');
$pass    = $pass === false ? 'root' : $pass;
$user    = getenv('DRIFT_DB_USER') ?: 'root';
$admin->query("DROP DATABASE IF EXISTS `$scratch`");
$admin->query("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4");
register_shutdown_function(static fn () => $admin->query("DROP DATABASE IF EXISTS `$scratch`"));

/** A free local TCP port. */
function budget_port(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($sock, false);
    fclose($sock);

    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

$webPort = budget_port();
$env     = array(
    'OSC_IGNORE_CONFIG_FILE' => '1',
    'DB_HOST'                => $host . ':' . $port,
    'DB_NAME'                => $scratch,
    'DB_USER'                => $user,
    'DB_PASSWORD'            => $pass,
    'DB_TABLE_PREFIX'        => 'oc_',
    'WEB_PATH'               => 'http://127.0.0.1:' . $webPort . '/',
    'OSC_CACHE'              => '',
    'PATH'                   => (string) getenv('PATH'),
);

harness_section('a fresh install with listings');
$install = proc_open(
    array(PHP_BINARY, ABS_PATH . 'oc-cli.php', 'install', '--unattended', '--site-type=classifieds'),
    array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
    $pipes,
    ABS_PATH,
    $env + array('OSC_ADMIN_EMAIL' => 'admin@example.test', 'OSC_ADMIN_PASSWORD' => 'budget-admin-1')
);
$said = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
check('the installer finishes', proc_close($install) === 0, $said);

$admin->select_db($scratch);
$p = 'oc_';
foreach (array("UPDATE {$p}t_preference SET s_value = 'budget' WHERE s_section = 'osclass' AND s_name = 'theme'",
    "UPDATE {$p}t_preference SET s_value = '0' WHERE s_section = 'osclass' AND s_name = 'auto_cron'",
    // As the hourly sweep leaves a site that sold no listing upgrades.
    "REPLACE INTO {$p}t_preference (s_section, s_name, s_value, e_type) VALUES ('osclass', 'item_upgrade_rows', '0', 'BOOLEAN')",
    "INSERT INTO {$p}t_item (fk_i_category_id, dt_pub_date, dt_first_pub_date, f_price, i_price, fk_c_currency_code, s_contact_name, s_contact_email, s_ip, b_enabled, b_active, b_spam, s_secret, b_show_email, b_premium)
     SELECT c.pk_i_id, NOW() - INTERVAL s.seq HOUR, NOW() - INTERVAL s.seq HOUR, s.seq * 10, s.seq * 10000000, 'USD', CONCAT('Seller ', s.seq), CONCAT('s', s.seq, '@example.test'), '127.0.0.1', 1, 1, 0, 'x', 0, s.seq % 10 = 0
     FROM (SELECT a.n + b.n * 20 + 1 AS seq FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19) a,
           (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) b) s
     JOIN (SELECT pk_i_id, @r := @r + 1 AS rn FROM {$p}t_category, (SELECT @r := 0) r WHERE fk_i_parent_id IS NOT NULL ORDER BY pk_i_id) c ON c.rn = 1 + (s.seq % 20)",
    "INSERT INTO {$p}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description) SELECT pk_i_id, 'en_US', CONCAT('Listing number ', pk_i_id, ' & co'), CONCAT('A description for listing ', pk_i_id, '.') FROM {$p}t_item",
    "INSERT INTO {$p}t_item_location (fk_i_item_id, s_country, s_region, s_city) SELECT pk_i_id, 'United States', 'California', 'San Jose' FROM {$p}t_item",
    "INSERT INTO {$p}t_item_stats (fk_i_item_id, dt_date) SELECT pk_i_id, CURDATE() FROM {$p}t_item",
) as $sql) {
    if (!$admin->query($sql)) {
        check('seed: ' . substr($sql, 0, 60), false, $admin->error);
    }
}
$listings = (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_item")->fetch_assoc()['c'];
pin('300 listings', 300, $listings);
$category = (int) $admin->query("SELECT fk_i_category_id c FROM {$p}t_item ORDER BY pk_i_id LIMIT 1")->fetch_assoc()['c'];
$item     = (int) $admin->query("SELECT MIN(pk_i_id) i FROM {$p}t_item")->fetch_assoc()['i'];

/**
 * What each page costs on a server with $extra settings: the third request of each, so start-up
 * work of the first one is not counted.
 *
 * @return array<string,array{files:int,queries:int,trips:int,list:string[],status:int}>
 */
function budget_measure(array $env, int $webPort, array $paths): array
{
    $out = tempnam(sys_get_temp_dir(), 'budget');
    $env += array('PAGE_BUDGET_OUT' => $out, 'PAGE_BUDGET_THEMES' => __DIR__ . '/fixtures/budget-theme');
    $server = proc_open(
        array(PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/lib/page-budget-probe.php', '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $webPort, '-t', ABS_PATH),
        array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
        $pipes,
        ABS_PATH,
        $env
    );
    for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $webPort, $errno, $errstr, 0.1); $i++) {
        usleep(50000);
    }
    $result = array();
    foreach ($paths as $name => $path) {
        for ($i = 0; $i < 3; $i++) {
            file_put_contents($out, '');
            @file_get_contents('http://127.0.0.1:' . $webPort . $path, false, stream_context_create(array('http' => array('ignore_errors' => true, 'timeout' => 30))));
        }
        $row           = json_decode((string) strtok((string) file_get_contents($out), "\n"), true) ?: array();
        $result[$name] = array(
            'files'   => count($row['files'] ?? array()),
            'queries' => (int) ($row['queries'] ?? -1),
            'trips'   => (int) ($row['trips'] ?? -1),
            'list'    => $row['files'] ?? array(),
            'status'  => (int) ($row['status'] ?? 0),
        );
    }
    proc_terminate($server);
    proc_close($server);
    @unlink($out);

    return $result;
}

$paths = array_map(static fn (string $p): string => str_replace(array('%category%', '%item%'), array((string) $category, (string) $item), $p), PAGES);
$runs  = array('plain' => array());
$redis = (string) getenv('OSC_TEST_REDIS');
if ($redis !== '') {
    [$redisHost, $redisPort] = explode(':', $redis) + array(1 => '6379');
    $runs['redis'] = array('OSC_CACHE' => 'redis', 'OSC_CACHE_HOST' => $redisHost, 'OSC_CACHE_PORT' => $redisPort);
}

$budget = is_file(BUDGET_FILE) ? (array) json_decode((string) file_get_contents(BUDGET_FILE), true) : array();
$fresh  = $budget;
foreach ($runs as $run => $extra) {
    harness_section('pages, ' . ($run === 'plain' ? 'no object cache' : 'Valkey object cache'));
    $measured = budget_measure($extra + $env, budget_port(), $paths);
    foreach ($measured as $page => $got) {
        pin("$run $page answers 200", 200, $got['status']);
        $fresh[$run][$page] = array('files' => $got['files'], 'queries' => $got['queries'], 'trips' => $got['trips']);
        if ($update) {
            continue;
        }
        $want = $budget[$run][$page] ?? null;
        if (!is_array($want)) {
            check("$run $page has a budget in tests/page-budget.json", false, 'run php tests/page-budget.php --update and commit the file');
            continue;
        }
        foreach (array('files' => FILE_SLACK, 'queries' => QUERY_SLACK, 'trips' => TRIP_SLACK) as $what => $slack) {
            $over = $got[$what] - (int) $want[$what];
            check(
                sprintf('%s %s: %d %s, budget %d', $run, $page, $got[$what], $what, $want[$what]),
                $over <= $slack,
                $what === 'files' ? "included files:\n    " . implode("\n    ", $got['list']) . "\n" : ''
            );
            if ($over > $slack) {
                echo "        A page costs more than its budget. Find what was added, or, when the cost is wanted,\n"
                    . "        run php tests/page-budget.php --update and commit tests/page-budget.json with the change.\n";
            } elseif ($over < -$slack) {
                echo "        NOTE  $run $page now costs less ($what); run php tests/page-budget.php --update to keep the gain.\n";
            }
        }
    }
}

if ($update) {
    // Keep the Valkey numbers when this machine has no Valkey to measure them with.
    file_put_contents(BUDGET_FILE, json_encode($fresh, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo 'Stored ' . BUDGET_FILE . "\n";
}

exit(harness_result());
