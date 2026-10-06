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
 * System info checks: the verdict's tone and order, each issue's action, the backup and
 * cron age rules, the queue, security and cache checks, the Overview's one line per tab,
 * the healthy sentence per tab, and the tab a URL opens.
 *
 * No database. Usage:  php tests/system-checks.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

if (!function_exists('_n')) {
    function _n($one, $many, $n)
    {
        return (int) $n === 1 ? $one : $many;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

use mindstellar\admin\SystemChecks;
use mindstellar\database\SchemaDoctor;

$now = 1790000000;

/** A healthy site; each case changes only what it tests. */
$env = static function (array $over = array()) use ($now): array {
    return $over + array(
        'admin_url'        => 'https://example.com/oc-admin/index.php',
        'now'              => $now,
        'version'          => '6.4.0',
        'db_version'       => '6.4.0',
        'php'              => '8.2.10',
        'db_server'        => '10.6.12-MariaDB',
        'extensions'       => SystemChecks::EXTENSIONS,
        'imagick'          => true,
        'imagick_on'       => true,
        'gd'               => true,
        'opcache'          => true,
        'uploads_writable' => true,
        'memory'           => 256 * 1024 * 1024,
        'upload'           => 16 * 1024 * 1024,
        'post'             => 20 * 1024 * 1024,
        'max_files'        => 20,
        'photos'           => 12,
        'free_disk'        => 20 * 1024 * 1024 * 1024,
        'cron_last'        => $now - 300,
        'cache_driver'     => 'default',
        'cache_supported'  => true,
        'cache_working'    => null,
        'jobs'             => array('pending' => 0, 'running' => 0, 'error' => 0, 'oldest' => null, 'due' => 0, 'due_since' => null, 'stuck' => 0, 'overdue' => null, 'orphans' => array()),
        'me'               => 1,
        'admins'           => array(array('id' => 1, 'name' => 'Owner', 'username' => 'owner', 'moderator' => false, 'two_factor' => true)),
        'throttle'         => array('enabled' => true, 'window' => 15, 'max_ip' => 20, 'max_account' => 10, 'captcha' => false, 'blocked' => 0),
        'debug'            => false,
        'config_writable'  => false,
        'maintenance'      => '',
        'proxy'            => null,
        'backup_last'      => array('date' => date('c', $now - 2 * 86400), 'what' => 'everything', 'where' => 'server'),
        'backup_probe'     => false,
        'pending'          => array(),
        'findings'         => array(),
        'findings_error'   => '',
    );
};

/** The ids of a report's issues, in order. */
$ids = static function (array $report): array {
    return array_column($report['issues'], 'id');
};

/** One issue by id, from any tab's report. */
$issue = static function (string $tab, array $e, string $id): ?array {
    foreach (SystemChecks::report($tab, $e)['issues'] as $row) {
        if ($row['id'] === $id) {
            return $row;
        }
    }

    return null;
};

harness_section('A healthy site');

foreach (SystemChecks::TABS as $tab) {
    pin("$tab: nothing to say", array(), SystemChecks::report($tab, $env())['issues']);
}
pin('Overview says so', 'Everything looks fine.', SystemChecks::healthy('overview'));
pin('Database says so', 'Your database is up to date and healthy.', SystemChecks::healthy('database'));
pin('Server says so', 'The server meets what Shopclass needs.', SystemChecks::healthy('server'));
pin('Jobs says so', 'Background jobs are running normally.', SystemChecks::healthy('jobs'));
pin('Security says so', 'All the checks below pass.', SystemChecks::healthy('security'));
pin('Cache says so', 'The cache is working.', SystemChecks::healthy('cache'));
pin('no issues: the box is green', 'success', SystemChecks::tone(array()));

harness_section('Worst tone wins, red first');

$mixed = array(
    array('id' => 'a', 'tone' => 'info', 'text' => 'a'),
    array('id' => 'b', 'tone' => 'warning', 'text' => 'b'),
    array('id' => 'c', 'tone' => 'danger', 'text' => 'c'),
    array('id' => 'd', 'tone' => 'warning', 'text' => 'd'),
    array('id' => 'e', 'tone' => 'danger', 'text' => 'e'),
);
pin('red, then amber, then the rest, each in the order found', array('c', 'e', 'b', 'd', 'a'), array_column(SystemChecks::rank($mixed), 'id'));
pin('one red line makes the box red', 'danger', SystemChecks::tone($mixed));
pin('amber only: amber', 'warning', SystemChecks::tone(array($mixed[0], $mixed[1])));
pin('info only: info', 'info', SystemChecks::tone(array($mixed[0])));
pin('an unknown tone reads as info', 'info', SystemChecks::tone(array(array('tone' => 'purple', 'text' => 'x'))));

$problem = $env(array('uploads_writable' => false, 'pending' => array('0099_x.php')));
pin('Overview: the uploads line leads the waiting update', array('uploads_read_only', 'db_pending'), $ids(SystemChecks::report('overview', $problem)));
pin('...in a red box', 'danger', SystemChecks::tone(SystemChecks::report('overview', $problem)['issues']));

harness_section('Each issue and its action');

$base = 'https://example.com/oc-admin/index.php';
$cases = array(
    // id => [tab, env changes, tone, action target]
    'db_pending'           => array('database', array('pending' => array('0099_x.php')), 'warning', '#db-update-dialog'),
    'db_repairable'        => array('database', array('findings' => array(array('kind' => SchemaDoctor::MISSING_INDEX, 'table' => 't', 'name' => 'i'))), 'warning', '#db-check'),
    'db_closer_look'       => array('database', array('findings' => array(array('kind' => SchemaDoctor::NULLABILITY, 'table' => 't', 'name' => 'c'))), 'warning', '#db-check'),
    'db_unreadable'        => array('database', array('findings_error' => 'no access'), 'danger', null),
    'db_server_old'        => array('database', array('db_server' => '5.7.4'), 'danger', null),
    'ext_missing'          => array('server', array('extensions' => array('curl')), 'danger', '#server-help'),
    'no_image_library'     => array('server', array('imagick' => false, 'gd' => false), 'danger', '#server-help'),
    'uploads_read_only'    => array('server', array('uploads_writable' => false), 'danger', null),
    'core_read_only'       => array('server', array('read_only' => array('core'), 'php_user' => 'nobody', 'file_owner' => 'tony'), 'warning', null),
    'packages_read_only'   => array('server', array('read_only' => array('plugins')), 'warning', null),
    'memory_low'           => array('server', array('memory' => 64 * 1024 * 1024), 'warning', '#server-help'),
    'post_below_upload'    => array('server', array('post' => 8 * 1024 * 1024), 'warning', '#server-help'),
    'uploads_below_photos' => array('server', array('max_files' => 5), 'warning', $base . '?page=items&action=settings'),
    'opcache_off'          => array('server', array('opcache' => false), 'warning', '#server-help'),
    'debug_on'             => array('server', array('debug' => true), 'warning', '#server-help'),
    'disk_low'             => array('server', array('free_disk' => 100 * 1024 * 1024), 'warning', null),
    'maintenance_on'       => array('server', array('maintenance' => 'locked'), 'warning', $base . '?page=tools&action=maintenance'),
    'backup_none'          => array('overview', array('backup_last' => null), 'warning', $base . '?page=tools&action=backup'),
    'cron_never'           => array('jobs', array('cron_last' => 0), 'warning', SystemChecks::DOCS_CRON),
    'cron_stale'           => array('jobs', array('cron_last' => $now - 3 * 86400), 'warning', SystemChecks::DOCS_CRON),
    'jobs_failed'          => array('jobs', array('jobs' => array('error' => 2)), 'warning', '#jobs-failed'),
    'jobs_stuck'           => array('jobs', array('jobs' => array('stuck' => 1)), 'warning', 'form:jobs-run-form'),
    'jobs_orphans'         => array('jobs', array('jobs' => array('orphans' => array('gone.plugin'))), 'warning', null),
    'proxy_mismatch'       => array('security', array('proxy' => array('header' => 'X-Forwarded-For', 'proxy' => '10.0.0.1')), 'danger', SystemChecks::DOCS_PROXY),
    'backups_open'         => array('security', array('backup_probe' => true), 'danger', SystemChecks::DOCS_BACKUPS),
    'config_writable'      => array('security', array('config_writable' => true), 'warning', null),
    'signin_protection_off' => array('security', array('throttle' => array('enabled' => false)), 'warning', $base . '?page=settings&action=spamNbots#login-throttle-settings'),
    'admins_no_2fa'        => array('security', array('admins' => array(array('id' => 2, 'username' => 'ed', 'two_factor' => false))), 'warning', $base . '?page=admins'),
    'signin_blocked'       => array('security', array('throttle' => array('enabled' => true, 'blocked' => 3)), 'info', '#signin-activity'),
    'cache_unsupported'    => array('cache', array('cache_driver' => 'redis', 'cache_supported' => false), 'warning', $base . '?page=tools&action=system-info&tab=server#server-help'),
    'cache_unreachable'    => array('cache', array('cache_driver' => 'memcached', 'cache_supported' => true, 'cache_working' => false), 'warning', null),
    'backup_old'           => array('overview', array('backup_last' => array('date' => date('c', $now - 31 * 86400), 'what' => 'database')), 'warning', $base . '?page=tools&action=backup'),
);
foreach ($cases as $id => list($tab, $over, $tone, $target)) {
    $row = $issue($tab, $env($over), $id);
    check("$id is raised on $tab", $row !== null);
    if ($row === null) {
        continue;
    }
    pin("...as $tone", $tone, $row['tone']);
    $action = $row['action'];
    $got    = $action === null ? null : ($action['url'] ?? ($action['attrs']['data-osc-dialog-open'] ?? 'form:' . ($action['attrs']['form'] ?? '')));
    pin('...with its action', $target, $got);
    check('...and a line of words', trim((string) $row['text']) !== '');
}

harness_section('From the Overview, actions point at the right tab');

$over = $env(array('findings' => array(array('kind' => SchemaDoctor::MISSING_INDEX, 'table' => 't', 'name' => 'i')), 'opcache' => false, 'uploads_writable' => false));
pin('See the list opens the Database tab', $base . '?page=tools&action=system-info&tab=database#db-check', $issue('overview', $over, 'db_repairable')['action']['url']);
pin('How to change it opens the Server tab help', $base . '?page=tools&action=system-info&tab=server#server-help', $issue('overview', $over, 'opcache_off')['action']['url']);
pin('the uploads line offers Server details', $base . '?page=tools&action=system-info&tab=server', $issue('overview', $over, 'uploads_read_only')['action']['url']);
pin('Run it opens the same dialog', '#db-update-dialog', $issue('overview', $env(array('pending' => array('0099_x.php'))), 'db_pending')['action']['attrs']['data-osc-dialog-open']);
check('a waiting update hides the Repair line', $issue('database', $env(array('pending' => array('0099_x.php')) + array('findings' => $over['findings'])), 'db_repairable') === null);

harness_section('No backup in 30 days');

$age = static function (int $days) use ($env, $now, $issue) {
    return $issue('overview', $env(array('backup_last' => array('date' => date('c', $now - $days * 86400), 'what' => 'files'))), 'backup_old');
};
check('29 days: fine', $age(29) === null);
check('30 days: fine', $age(30) === null);
check('31 days: asks for a backup', $age(31) !== null);
check('a garbled date counts as none', $issue('overview', $env(array('backup_last' => array('date' => 'soon'))), 'backup_none') !== null);
check('the backup rule is not on the Server tab', $issue('server', $env(array('backup_last' => null)), 'backup_none') === null);
$facts = array_column(SystemChecks::report('overview', $env())['groups'][0]['rows'], 'value', 'label');
pin('the facts say when and what', '2 days ago · Everything · on the server', $facts['Last backup']);
pin('...and none when there is none', 'none saved on the server', array_column(SystemChecks::report('overview', $env(array('backup_last' => null)))['groups'][0]['rows'], 'value', 'label')['Last backup']);

harness_section('Cron');

check('exactly 25 hours: fine', $issue('jobs', $env(array('cron_last' => $now - SystemChecks::CRON_MAX_AGE)), 'cron_stale') === null);
check('a minute later: stopped', $issue('jobs', $env(array('cron_last' => $now - SystemChecks::CRON_MAX_AGE - 60)), 'cron_stale') !== null);
pin('the facts say when', 'ran 5 minutes ago', $facts['Cron']);

harness_section('Limits and storage');

check('no memory limit is fine', $issue('server', $env(array('memory' => -1)), 'memory_low') === null);
check('128 MB is fine', $issue('server', $env(array('memory' => SystemChecks::MEMORY_FLOOR)), 'memory_low') === null);
check('a free-space read that failed says nothing', $issue('server', $env(array('free_disk' => null)), 'disk_low') === null);
check('no photos offered: no files-per-request line', $issue('server', $env(array('photos' => 0, 'max_files' => 1)), 'uploads_below_photos') === null);
check('the default object cache is not a problem', $issue('cache', $env(array('cache_driver' => 'default', 'cache_supported' => false)), 'cache_unsupported') === null);
pin('the banner-only maintenance line', 'Maintenance mode is on, but visitors can still use the site and see a banner.', $issue('server', $env(array('maintenance' => 'banner')), 'maintenance_on')['text']);
pin('an unchecked backups folder is not a problem', null, $issue('security', $env(array('backup_probe' => null)), 'backups_open'));
pin('missing extensions are named', 'Required PHP extensions are missing: zip, openssl.', $issue('server', $env(array('extensions' => array_diff(SystemChecks::EXTENSIONS, array('zip', 'openssl')))), 'ext_missing')['text']);
pin('photos in a bucket', 'S3 bucket "shop" (local copies kept)', array_column(SystemChecks::report('overview', $env(array('storage' => array('active' => 's3', 'bucket' => 'shop', 'keep_local' => 'all'))))['groups'][0]['rows'], 'value', 'label')['Photo storage']);
pin('ini sizes', array(-1, 0, 128 * 1024 * 1024, 2 * 1024 * 1024 * 1024, 512 * 1024, 900), array_map(array(SystemChecks::class, 'iniBytes'), array('-1', '', '128M', '2G', '512k', '900')));
pin('Server tab groups', array('PHP and web server', 'Files and storage', 'Debug and maintenance', 'Paths'), array_column(SystemChecks::report('server', $env())['groups'], 'title'));

harness_section('One source per check');

$loud = $env(array(
    'proxy'           => array('header' => 'X-Forwarded-For', 'proxy' => '10.0.0.1'),
    'backup_probe'    => true,
    'config_writable' => true,
    'cron_last'       => 0,
    'cache_driver'    => 'redis',
    'cache_supported' => false,
));
pin('the Server tab no longer raises what moved to other tabs', array(), array_values(array_intersect(
    array('proxy_mismatch', 'backups_open', 'config_writable', 'cron_never', 'cache_unsupported'),
    $ids(SystemChecks::report('server', $loud))
)));
pin('the Overview shows one line per tab with problems, red first', array('security_summary', 'jobs_summary', 'cache_summary'), $ids(SystemChecks::report('overview', $loud)));
$line = $issue('overview', $loud, 'security_summary');
pin('...the worst line of that tab, with a count of the rest', 'Visitor addresses look wrong: the site is behind a proxy (X-Forwarded-For), so every visitor seems to come from 10.0.0.1. Sign-in protection and IP bans cannot tell visitors apart. And 2 more.', $line['text']);
pin('...in its tone', 'danger', $line['tone']);
pin('...and opens the tab', array('Open Security', $base . '?page=tools&action=system-info&tab=security'), array($line['action']['label'], $line['action']['url']));
check('an info line alone stays off the Overview', $issue('overview', $env(array('throttle' => array('enabled' => true, 'blocked' => 4))), 'security_summary') === null);
pin('one issue: no count', 'config.php can be written by the web server. It holds your database password; make it read-only.', $issue('overview', $env(array('config_writable' => true)), 'security_summary')['text']);

harness_section('The queue');

check('cron stopped: the queue line waits for it', $issue('jobs', $env(array('cron_last' => 0, 'jobs' => array('stuck' => 2))), 'jobs_stuck') === null);
check('a job due an hour ago: fine', $issue('jobs', $env(array('jobs' => array('overdue' => date('Y-m-d H:i:s', $now - SystemChecks::JOBS_OVERDUE)))), 'jobs_stuck') === null);
check('a minute more: stuck', $issue('jobs', $env(array('jobs' => array('overdue' => date('Y-m-d H:i:s', $now - SystemChecks::JOBS_OVERDUE - 60)))), 'jobs_stuck') !== null);
pin('from the Overview, stuck points at the queue', $base . '?page=tools&action=system-info&tab=jobs', $issue('overview', $env(array('jobs' => array('stuck' => 1))), 'jobs_summary')['action']['url']);
pin('failed jobs are counted', '2 background jobs stopped after failing again and again.', $issue('jobs', $env(array('jobs' => array('error' => 2))), 'jobs_failed')['text']);
pin('unhandled types are named', 'Queued work has nothing to run it: a.b, c.d. A plugin was probably turned off with jobs still waiting.', $issue('jobs', $env(array('jobs' => array('orphans' => array('a.b', 'c.d')))), 'jobs_orphans')['text']);
$jobFacts = array_column(SystemChecks::report('jobs', $env(array('jobs' => array('pending' => 3, 'oldest' => date('Y-m-d H:i:s', $now - 90000), 'due' => 2, 'due_since' => date('Y-m-d H:i:s', $now - 7200), 'error' => 1))))['groups'][0]['rows'], 'value', 'label');
pin('the facts', array('Waiting' => '3 · the oldest due for 2 hours', 'Running' => '0', 'Gave up' => '1', 'Cron' => 'ran 5 minutes ago'), $jobFacts);

harness_section('Security');

$admins = array(
    array('id' => 1, 'name' => 'Owner', 'username' => 'owner', 'moderator' => false, 'two_factor' => true),
    array('id' => 2, 'name' => 'Ed', 'username' => 'ed', 'moderator' => true, 'two_factor' => false),
    array('id' => 3, 'name' => 'Jo', 'username' => 'jo', 'moderator' => false, 'two_factor' => false),
);
$no2fa = $issue('security', $env(array('admins' => $admins)), 'admins_no_2fa');
pin('admins without 2FA are named', '2 admins sign in with a password only: ed, jo.', $no2fa['text']);
pin('...and the list of admins is offered', $base . '?page=admins', $no2fa['action']['url']);
pin('an admin without it is sent to their own profile', $base . '?page=admins&action=edit', $issue('security', $env(array('admins' => $admins, 'me' => 3)), 'admins_no_2fa')['action']['url']);
check('everyone with 2FA: no line', $issue('security', $env(), 'admins_no_2fa') === null);
$groups = SystemChecks::report('security', $env(array('admins' => $admins, 'web_restore_off' => true, 'package_installs_off' => true)))['groups'];
pin('the groups', array('Sign-in protection', 'Admins', 'Site'), array_column($groups, 'title'));
pin('sign-in protection links to its settings', $base . '?page=settings&action=spamNbots#login-throttle-settings', $groups[0]['link']['url']);
pin('one row per admin, saying only on or off', array('owner' => 'two-step sign-in', 'ed' => 'password only', 'jo' => 'password only'), array_column($groups[1]['rows'], 'value', 'label'));
check('no row carries a secret', strpos(json_encode($groups), 'secret') === false);
$site = array_column($groups[2]['rows'], 'value', 'label');
pin('the switches', array('off, command line only', 'off'), array($site['Restore from the admin'], $site['Plugin and theme installs']));
pin('with a captcha there is no account limit', 'A captcha is set up, so there is no limit per account.', array_column(SystemChecks::report('security', $env(array('throttle' => array('enabled' => true, 'captcha' => true))))['groups'][0]['rows'], 'note', 'label')['Limits']);
check('protection off: no limits row', !in_array('Limits', array_column(SystemChecks::report('security', $env(array('throttle' => array('enabled' => false))))['groups'][0]['rows'], 'label'), true));

harness_section('Cache');

check('a working driver: nothing to say', SystemChecks::report('cache', $env(array('cache_driver' => 'apcu', 'cache_supported' => true, 'cache_working' => true)))['issues'] === array());
check('an unchecked driver is not a problem', $issue('cache', $env(array('cache_driver' => 'apcu', 'cache_supported' => true, 'cache_working' => null)), 'cache_unreachable') === null);
$cacheFacts = array_column(SystemChecks::report('cache', $env(array(
    'cache_driver'    => 'memcached',
    'cache_supported' => true,
    'cache_working'   => false,
    'cache_drivers'   => array('apcu' => false, 'memcached' => true, 'memcache' => false),
)))['groups'][1]['rows'], 'value', 'label');
pin('which drivers the server has', array('APCu' => 'not installed', 'Memcached' => 'installed · in use', 'Memcache' => 'not installed'), $cacheFacts);
pin('the working row says why not', 'no, it did not answer', array_column(SystemChecks::report('cache', $env(array('cache_driver' => 'memcached', 'cache_supported' => true, 'cache_working' => false)))['groups'][0]['rows'], 'value', 'label')['Working']);
pin('stats rows, skipping what the driver does not give', array(
    'Hit rate' => '75% (300 hits, 100 misses)',
    'Entries'  => '1,200',
    'Memory'   => '1 MB of 64 MB',
    'Uptime'   => '2 hours',
), array_column(SystemChecks::cacheStatRows(array(
    'entries' => 1200, 'hits' => 300, 'misses' => 100, 'memory_used' => 1048576, 'memory_total' => 67108864,
    'uptime' => 7200, 'evictions' => null, 'server' => null,
)), 'value', 'label'));
pin('no stats: no rows', array(), SystemChecks::cacheStatRows(array()));

harness_section('Strict SQL mode');

$ready = array(
    'server_mode' => 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', 'session_mode' => 'NO_ENGINE_SUBSTITUTION', 'constant' => false,
    'zero_dates' => array(), 'zero_defaults' => array(), 'settings' => array(), 'refused' => array(), 'error' => '',
);
$notReady = array(
    'refused'  => array(
        array('column' => 'oc_t_log.s_data', 'kind' => 'data_too_long', 'count' => 3, 'last' => date('Y-m-d H:i:s', $now - 7200)),
        array('column' => 'oc_t_item_description.s_title', 'kind' => 'data_too_long', 'count' => 2, 'last' => date('Y-m-d H:i:s', $now - 60)),
    ),
    'zero_dates' => array('oc_t_item.dt_expiration' => 4),
    'settings'   => array(array('setting' => 'title_character_length', 'value' => 200, 'column' => 'oc_t_item_description.s_title', 'width' => 100)),
) + $ready;

pin('ready: no line on Database', array(), SystemChecks::report('database', $env(array('strict' => $ready)))['issues']);
pin('...nor on Overview', array(), SystemChecks::report('overview', $env(array('strict' => $ready)))['issues']);
$dbReport = SystemChecks::report('database', $env(array('strict' => $notReady)));
pin('not ready: one amber line per check on Database', array('strict_refused', 'strict_zero_dates', 'strict_setting_title_character_length'), $ids($dbReport));
pin('...amber box', 'warning', SystemChecks::tone($dbReport['issues']));
pin('refused writes are summed', '5 writes were refused by strict SQL mode in the last 7 days.', $dbReport['issues'][0]['text']);
pin('...See which opens the section', array('See which', '#db-strict'), array($dbReport['issues'][0]['action']['label'], $dbReport['issues'][0]['action']['url']));
pin('the setting line names both widths', 'The Title length setting is 200 characters, but its column holds 100. Strict SQL mode refuses the longer values.', $dbReport['issues'][2]['text']);
pin('...and offers the settings', $base . '?page=items&action=settings', $dbReport['issues'][2]['action']['url']);
$ov = SystemChecks::report('overview', $env(array('strict' => $notReady)))['issues'];
pin('Overview: one line, the first of them', array('database_summary'), array_column($ov, 'id'));
pin('...with the count of the rest', '5 writes were refused by strict SQL mode in the last 7 days. And 2 more.', $ov[0]['text']);
pin('...opening the Database tab', $base . '?page=tools&action=system-info&tab=database', $ov[0]['action']['url']);
pin('a failed read is an info line', array('strict_unreadable'), $ids(SystemChecks::report('database', $env(array('strict' => array('error' => 'denied') + $ready)))));

$groups = array_column(SystemChecks::report('database', $env(array('strict' => $notReady)))['groups'], null, 'title');
check('the facts sit in the Strict SQL mode group, anchored for See which', ($groups['Strict SQL mode']['id'] ?? '') === 'db-strict');
$facts = array_column($groups['Strict SQL mode']['rows'], 'value', 'label');
pin('facts: server mode, constant, and each check', array(
    'Server mode' => 'strict', 'OSC_DB_STRICT_MODE' => 'not set', 'Refused writes, last 7 days' => '5',
    'Zero dates' => '1 column', 'Zero-date defaults' => 'none', 'Length settings' => '1 is larger than its column',
), $facts);
pin('refused writes listed by column, newest first as read, with kind and count', array(
    array('oc_t_log.s_data', 'value too long', '3 times · last 2 hours ago'),
    array('oc_t_item_description.s_title', 'value too long', '2 times · last 1 minute ago'),
), array_map(static function ($r) {
    return array($r['label'], $r['value'], $r['note']);
}, $groups['Writes strict SQL mode refused']['rows']));
$readyGroups = array_column(SystemChecks::report('database', $env(array('strict' => $ready)))['groups'], null, 'title');
check('ready: no refused-writes list', !isset($readyGroups['Writes strict SQL mode refused']));
pin('ready: every check reads clean', array('none', 'none', 'none', 'fit their columns'), array_values(array_intersect_key(
    array_column($readyGroups['Strict SQL mode']['rows'], 'value', 'label'),
    array_flip(array('Refused writes, last 7 days', 'Zero dates', 'Zero-date defaults', 'Length settings'))
)));
pin('Overview does not count zero dates', 'not checked here', array_column(SystemChecks::report('database', $env(array('strict' => array('zero_dates' => null) + $ready)))['groups'][1]['rows'], 'value', 'label')['Zero dates']);
pin('no strict report: no group', 1, count(SystemChecks::report('database', $env())['groups']));

harness_section('Strict SQL mode: activity log off');

$logOff = array('log_enabled' => false, 'refused' => null) + $ready;
$logOffDb = SystemChecks::report('database', $env(array('strict' => $logOff)));
pin('an honest "unknown" line, not silence', array('strict_refused_unknown'), $ids($logOffDb));
pin('...its wording', 'Refused writes are not recorded while the activity log is off, so this cannot confirm there are none.', $logOffDb['issues'][0]['text']);
$logOffFacts = array_column(array_column($logOffDb['groups'], null, 'title')['Strict SQL mode']['rows'], 'value', 'label');
pin('facts: "unknown", never "none"', 'unknown', $logOffFacts['Refused writes, last 7 days']);
$logOffNote = array_column(array_column($logOffDb['groups'], null, 'title')['Strict SQL mode']['rows'], 'note', 'label');
pin('...with the same honest note', 'Refused writes are not recorded while the activity log is off.', $logOffNote['Refused writes, last 7 days']);
$mixedIds = $ids(SystemChecks::report('database', $env(array('strict' => array('log_enabled' => false, 'refused' => null) + $notReady))));
check('log off wins over stale refused data: "unknown" shown, not the ordinary refused line',
    in_array('strict_refused_unknown', $mixedIds, true) && !in_array('strict_refused', $mixedIds, true));

harness_section('Which tab a URL opens');

pin('no tab: Overview', 'overview', SystemChecks::tab(''));
pin('database', 'database', SystemChecks::tab('database'));
pin('server', 'server', SystemChecks::tab('server'));
foreach (array('jobs', 'security', 'cache') as $t) {
    pin($t, $t, SystemChecks::tab($t));
}
pin('the tab strip', array('overview', 'jobs', 'security', 'cache', 'database', 'server'), array_keys(SystemChecks::labels()));
pin('the old PHP settings link: Server', 'server', SystemChecks::tab('', 'php-info'));
pin('an unknown tab: Overview', 'overview', SystemChecks::tab('../../etc'));
pin('tab URLs', array(
    $base . '?page=tools&action=system-info',
    $base . '?page=tools&action=system-info&tab=server#server-help',
), array(SystemChecks::url($env(), 'overview'), SystemChecks::url($env(), 'server', 'server-help')));

harness_section('File ownership');

$owned = $issue('server', $env(array('read_only' => array('downloads'), 'php_user' => 'nobody', 'file_owner' => 'tony')), 'core_read_only');
check('a read-only downloads folder blocks updates too', $owned !== null);
check('...and names both users', $owned !== null && strpos($owned['text'], 'nobody') !== false && strpos($owned['text'], 'tony') !== false);
pin('an image-managed install is not told to update in place', null, $issue('server', $env(array('read_only' => array('core'), 'self_update_off' => true)), 'core_read_only'));

exit(harness_result());

/* file end: ./tests/system-checks.php */
