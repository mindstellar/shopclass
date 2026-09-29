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
 * cron age rules, the healthy sentence per tab, and the tab a URL opens.
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
    'php_too_old'          => array('server', array('php' => '7.3.0'), 'danger', '#server-help'),
    'php_7'                => array('server', array('php' => '7.4.33'), 'warning', '#server-help'),
    'ext_missing'          => array('server', array('extensions' => array('curl')), 'danger', '#server-help'),
    'no_image_library'     => array('server', array('imagick' => false, 'gd' => false), 'danger', '#server-help'),
    'uploads_read_only'    => array('server', array('uploads_writable' => false), 'danger', null),
    'proxy_mismatch'       => array('server', array('proxy' => array('header' => 'X-Forwarded-For', 'proxy' => '10.0.0.1')), 'danger', SystemChecks::DOCS_PROXY),
    'backups_open'         => array('server', array('backup_probe' => true), 'danger', SystemChecks::DOCS_BACKUPS),
    'memory_low'           => array('server', array('memory' => 64 * 1024 * 1024), 'warning', '#server-help'),
    'post_below_upload'    => array('server', array('post' => 8 * 1024 * 1024), 'warning', '#server-help'),
    'uploads_below_photos' => array('server', array('max_files' => 5), 'warning', $base . '?page=items&action=settings'),
    'opcache_off'          => array('server', array('opcache' => false), 'warning', '#server-help'),
    'cron_never'           => array('server', array('cron_last' => 0), 'warning', SystemChecks::DOCS_CRON),
    'cron_stale'           => array('server', array('cron_last' => $now - 3 * 86400), 'warning', SystemChecks::DOCS_CRON),
    'cache_unsupported'    => array('server', array('cache_driver' => 'redis', 'cache_supported' => false), 'warning', '#server-help'),
    'debug_on'             => array('server', array('debug' => true), 'warning', '#server-help'),
    'config_writable'      => array('server', array('config_writable' => true), 'warning', '#server-help'),
    'disk_low'             => array('server', array('free_disk' => 100 * 1024 * 1024), 'warning', null),
    'maintenance_on'       => array('server', array('maintenance' => 'locked'), 'warning', $base . '?page=tools&action=maintenance'),
    'backup_none'          => array('overview', array('backup_last' => null), 'warning', $base . '?page=tools&action=backup'),
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
    $got    = $action === null ? null : ($action['url'] ?? ($action['attrs']['data-osc-dialog-open'] ?? ''));
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

check('exactly 25 hours: fine', $issue('server', $env(array('cron_last' => $now - SystemChecks::CRON_MAX_AGE)), 'cron_stale') === null);
check('a minute later: stopped', $issue('server', $env(array('cron_last' => $now - SystemChecks::CRON_MAX_AGE - 60)), 'cron_stale') !== null);
pin('the facts say when', 'ran 5 minutes ago', $facts['Cron']);

harness_section('Limits and storage');

check('no memory limit is fine', $issue('server', $env(array('memory' => -1)), 'memory_low') === null);
check('128 MB is fine', $issue('server', $env(array('memory' => SystemChecks::MEMORY_FLOOR)), 'memory_low') === null);
check('a free-space read that failed says nothing', $issue('server', $env(array('free_disk' => null)), 'disk_low') === null);
check('no photos offered: no files-per-request line', $issue('server', $env(array('photos' => 0, 'max_files' => 1)), 'uploads_below_photos') === null);
check('the default object cache is not a problem', $issue('server', $env(array('cache_driver' => 'default', 'cache_supported' => false)), 'cache_unsupported') === null);
pin('the banner-only maintenance line', 'Maintenance mode is on, but visitors can still use the site and see a banner.', $issue('server', $env(array('maintenance' => 'banner')), 'maintenance_on')['text']);
pin('an unchecked backups folder is not a problem', null, $issue('server', $env(array('backup_probe' => null)), 'backups_open'));
pin('missing extensions are named', 'Required PHP extensions are missing: zip, openssl.', $issue('server', $env(array('extensions' => array_diff(SystemChecks::EXTENSIONS, array('zip', 'openssl')))), 'ext_missing')['text']);
pin('photos in a bucket', 'S3 bucket "shop" (local copies kept)', array_column(SystemChecks::report('overview', $env(array('storage' => array('active' => 's3', 'bucket' => 'shop', 'keep_local' => 'all'))))['groups'][0]['rows'], 'value', 'label')['Photo storage']);
pin('ini sizes', array(-1, 0, 128 * 1024 * 1024, 2 * 1024 * 1024 * 1024, 512 * 1024, 900), array_map(array(SystemChecks::class, 'iniBytes'), array('-1', '', '128M', '2G', '512k', '900')));
pin('Server tab groups', array('PHP and web server', 'Files and storage', 'Scheduled tasks and cache', 'Paths'), array_column(SystemChecks::report('server', $env())['groups'], 'title'));

harness_section('Which tab a URL opens');

pin('no tab: Overview', 'overview', SystemChecks::tab(''));
pin('database', 'database', SystemChecks::tab('database'));
pin('server', 'server', SystemChecks::tab('server'));
pin('the old PHP settings link: Server', 'server', SystemChecks::tab('', 'php-info'));
pin('an unknown tab: Overview', 'overview', SystemChecks::tab('../../etc'));
pin('tab URLs', array(
    $base . '?page=tools&action=system-info',
    $base . '?page=tools&action=system-info&tab=server#server-help',
), array(SystemChecks::url($env(), 'overview'), SystemChecks::url($env(), 'server', 'server-help')));

exit(harness_result());

/* file end: ./tests/system-checks.php */
