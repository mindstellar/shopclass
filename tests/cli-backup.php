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
 * The backup:* commands: a backup made, listed and restored on a real database; a restore
 * asks first and refuses without --yes when nobody can answer; it works with web restore
 * turned off and stops while a database update holds the lock; a folder inside the site
 * is refused; a local .zip or .sql restores; the bucket round trip runs on a fake S3 client,
 * needs a site address that is set, and nothing printed carries a key or a signed link.
 *
 * Needs a database (the scratch container by default). Usage:  php tests/cli-backup.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$scratch = 'osc_cli_backup_' . getmypid();
$admin   = scratchdb_session($scratch);

require_once __DIR__ . '/lib/stubs.php';
require_once __DIR__ . '/lib/fake-s3.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hMaintenance.php';

use mindstellar\backup\BackupBucket;
use mindstellar\backup\BackupStore;
use mindstellar\cli\BackupCommands;
use mindstellar\storage\S3Storage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const TEST_ACCESS_KEY = 'AKIACLITESTKEY0001';
const TEST_SECRET_KEY = 'cli-test-secret-9f8e7d6c';

$base    = sys_get_temp_dir() . '/osc_cli_backup_' . getmypid();
$site    = $base . '/site';
$content = $site . '/oc-content';
$outside = $base . '/elsewhere';
@mkdir($content . '/uploads/1', 0777, true);
@mkdir($outside, 0777, true);
register_shutdown_function(static function () use ($base) {
    exec('rm -rf ' . escapeshellarg($base));
});
file_put_contents($content . '/uploads/1/photo.txt', 'the photo as it was');
$store = new BackupStore($content . '/downloads/backups/');

$admin->select_db($scratch);
$admin->query("INSERT INTO oc_t_preference (s_section, s_name, s_value, e_type) VALUES ('clitest', 'marker', 'before', 'STRING')");
$marker = static function () use ($admin): string {
    return (string) $admin->query("SELECT s_value FROM oc_t_preference WHERE s_section = 'clitest' AND s_name = 'marker'")->fetch_row()[0];
};
$setMarker = static function (string $value) use ($admin): void {
    $admin->query("UPDATE oc_t_preference SET s_value = '" . $admin->real_escape_string($value) . "' WHERE s_section = 'clitest' AND s_name = 'marker'");
};

$GLOBALS['busy']    = false;
$GLOBALS['printed'] = '';
$GLOBALS['answer']  = null;
$GLOBALS['migrated'] = 0;

/** Run one command and hand back its exit code, what it printed, and what it said on stderr. */
function backup_cli(string $command, array $args, array $extra = array()): array
{
    global $site, $content, $store;
    $out  = '';
    $err  = '';
    $cmds = new BackupCommands(
        static function (string $t) use (&$out): void {
            $out .= $t;
        },
        static function (string $t) use (&$err): void {
            $err .= $t;
        },
        $extra + array(
            'site'     => $site,
            'content'  => $content,
            'store'    => $store,
            'ask'      => null,
            'busy'     => static function (): bool {
                return $GLOBALS['busy'];
            },
            'facts'    => static function (): array {
                return array('db_version' => '1', 'site_url' => 'http://shop.test/', 'db_server' => '', 'photos_in_bucket' => null);
            },
            'restorer' => array(
                'after'   => static function (): void {
                },
                'migrate' => static function (): void {
                    $GLOBALS['migrated']++;
                },
            ),
            'effects'  => array(
                'saved'    => static function (): bool {
                    return true;
                },
                'restored' => static function (): bool {
                    return true;
                },
                'log'      => static function (): bool {
                    return true;
                },
            ),
        )
    );
    $code = $cmds->$command($args);
    $GLOBALS['printed'] .= $out . $err;

    return array($code, $out, $err);
}

/** The name of the newest backup in the store. */
function newest(BackupStore $store): string
{
    return (string) ($store->all()[0]['name'] ?? '');
}

harness_section('Make, list and restore on the server');

list($code, $out) = backup_cli('create', array('what' => 'everything', 'to' => 'server'));
pin('backup:create --what=everything --to=server finishes', 0, $code);
$name = newest($store);
check('...and saves an Everything backup', (bool) preg_match('/-everything-[a-z2-7]{16}\.zip$/', $name), $name);
check('...printing its name, size and place', strpos($out, 'Saved ' . $name) !== false && strpos($out, $store->dir() . $name) !== false, $out);
check('...with progress lines on the way', strpos($out, '%  ') !== false, $out);

list($code, $out) = backup_cli('list', array());
pin('backup:list finishes', 0, $code);
check('...naming the backup with what it holds', (bool) preg_match('/Everything\s+server\s+[\d.]+ [KMG]?B\s+' . preg_quote($name, '/') . '/', $out), $out);

$setMarker('after');
file_put_contents($content . '/uploads/1/photo.txt', 'changed since');

list($code, , $err) = backup_cli('restore', array('_' => array($name)));
pin('a restore with nobody to ask and no --yes is refused', 2, $code);
check('...saying to add --yes', strpos($err, '--yes') !== false, $err);
pin('...and nothing changed', 'after', $marker());

$GLOBALS['answer'] = 'no';
list($code) = backup_cli('restore', array('_' => array($name)), array('ask' => static function (): string {
    return (string) $GLOBALS['answer'];
}));
pin('a wrong answer at the prompt stops it', 1, $code);
pin('...and nothing changed', 'after', $marker());

// Web restore off is the admin's switch; the command line is the way left open.
define('OSC_DISABLE_WEB_RESTORE', true);
list($code, $out, $err) = backup_cli('restore', array('_' => array($name)), array('ask' => static function (): string {
    return "restore\n";
}));
pin('typing "restore" restores it, with web restore turned off', 0, $code);
check('...saying so', strpos($out, 'Restored the backup') !== false, $out . $err);
pin('the database is back', 'before', $marker());
pin('the files are back', 'the photo as it was', file_get_contents($content . '/uploads/1/photo.txt'));
check('the site is open again', !is_file($site . '/.maintenance'));
$safety = array_values(array_filter($store->all(), static function (array $row): bool {
    return $row['kind'] === 'safety';
}));
check('a safety copy was saved first', count($safety) === 1);
check('...and named in the output', $safety !== array() && strpos($out, $safety[0]['name']) !== false, $out);

harness_section('Refusals');

$GLOBALS['busy'] = true;
list($code, , $err) = backup_cli('create', array());
pin('no backup while another runs', 1, $code);
list($code) = backup_cli('restore', array('_' => array($name), 'yes' => true));
pin('...and no restore', 1, $code);
$GLOBALS['busy'] = false;

$setMarker('locked');
$copies = count($store->all());
$lock = (new \mindstellar\migration\MigrationRunner(\mindstellar\database\Connection::getInstance(), ABS_PATH . 'oc-includes/osclass/installer/migrations'))->lockName();
$admin->query("SELECT GET_LOCK('" . $admin->real_escape_string($lock) . "', 0)");
list($code, , $err) = backup_cli('restore', array('_' => array($name), 'yes' => true));
pin('a restore while a database update holds the lock is refused', 1, $code);
check('...saying why', strpos($err, 'A database update is running') !== false, $err);
pin('...and nothing changed', 'locked', $marker());
check('...and the site was never closed', !is_file($site . '/.maintenance'));
pin('...nor a safety copy made', $copies, count($store->all()));
$admin->query("SELECT RELEASE_LOCK('" . $admin->real_escape_string($lock) . "')");
$setMarker('before');

list($code, , $err) = backup_cli('create', array('to' => $content . '/uploads'));
pin('--to a folder inside the site is refused', 2, $code);
check('...saying anyone could download it', strpos($err, 'inside the site') !== false, $err);
list($code) = backup_cli('create', array('to' => $site));
pin('...the site folder itself too', 2, $code);
list($code) = backup_cli('create', array('to' => $base . '/missing'));
pin('...and a folder that is not there', 2, $code);
list($code) = backup_cli('create', array('what' => 'photos'));
pin('an unknown --what is refused', 2, $code);
list($code) = backup_cli('restore', array('_' => array('2026-01-01-000000-database-aaaaaaaaaaaaaaaa.zip'), 'yes' => true));
pin('a name that is not a backup is refused', 2, $code);

harness_section('A folder outside the site, and a local file');

list($code, $out) = backup_cli('create', array('what' => 'database', 'to' => $outside));
pin('--to a folder outside the site works', 0, $code);
$away = newest(new BackupStore($outside));
check('...and the backup is there', $away !== '' && is_file($outside . '/' . $away), $out);
check('...not in the site folder', !is_file($store->dir() . $away));
check('...with no run state left behind', !is_file($outside . '/.state.json'));
list($code, $out) = backup_cli('list', array('to' => $outside));
check('backup:list --to=<folder> lists it', $code === 0 && strpos($out, $away) !== false, $out);

$setMarker('outside');
list($code, $out, $err) = backup_cli('restore', array('_' => array($outside . '/' . $away), 'yes' => true));
pin('restoring a .zip given by path works', 0, $code);
pin('...and the database is back', 'before', $marker());
check('...and the file is left where it was', is_file($outside . '/' . $away));

$zip = new ZipArchive();
$zip->open($outside . '/' . $away);
file_put_contents($outside . '/dump.sql', (string) $zip->getFromName('database.sql'));
$zip->close();
$setMarker('sql');
list($code, $out, $err) = backup_cli('restore', array('_' => array($outside . '/dump.sql'), 'yes' => true));
pin('restoring a bare .sql given by path works', 0, $code);
pin('...and runs the database updates after it, as it has no version', 1, $GLOBALS['migrated']);
check('...warning that it has no version information', strpos($out, 'no version information') !== false, $out . $err);
pin('...and the database is back', 'before', $marker());
check('...and the .sql is left where it was', is_file($outside . '/dump.sql'));

$setMarker('files only');
file_put_contents($content . '/uploads/1/photo.txt', 'changed again');
list($code) = backup_cli('restore', array('_' => array($name), 'only' => 'files', 'yes' => true));
pin('--only=files puts back the files', 0, $code);
pin('...the photo is back', 'the photo as it was', file_get_contents($content . '/uploads/1/photo.txt'));
pin('...and the database is left alone', 'files only', $marker());
$setMarker('before');

harness_section('The bucket');

$client = new FakeS3Client();
$bucket = new S3Storage(array(
    'endpoint'   => 'https://s3.example',
    'bucket'     => 'shop-backups',
    'access_key' => TEST_ACCESS_KEY,
    'secret_key' => TEST_SECRET_KEY,
    'client'     => $client,
    'http'       => new MockHttpClient(new MockResponse('', array('http_code' => 403))),
));
BackupBucket::use($bucket);

list($code, , $err) = backup_cli('create', array('what' => 'database', 'to' => 's3'));
pin('with no site address set, --to=s3 is refused', 1, $code);
check('...saying to set WEB_PATH', strpos($err, 'Set WEB_PATH') !== false, $err);

BackupBucket::useBase('https://shop.example.com/');
list($code, $out, $err) = backup_cli('create', array('what' => 'database', 'to' => 's3'));
pin('backup:create --to=s3 finishes', 0, $code);
$keys = array_keys($client->objects);
$zips = preg_grep('#^backups/shop\.example\.com-[0-9a-f]{8}/.*-database-[a-z2-7]{16}\.zip$#', $keys);
check('...the zip is in the site folder of the bucket', count($zips) === 1, implode(', ', $keys));
$inBucket = basename((string) reset($zips));
check('...with its manifest beside it', isset($client->objects[BackupBucket::sidecarKey($inBucket)]));
check('...the copy here is gone', !is_file($store->dir() . $inBucket));
check('...and the output says where it is', strpos($out, 'in the bucket: ' . BackupBucket::key($inBucket)) !== false, $out);

list($code, $out) = backup_cli('list', array('to' => 's3'));
check('backup:list --to=s3 lists it', $code === 0 && strpos($out, $inBucket) !== false && strpos($out, ' s3 ') !== false, $out);

$setMarker('bucket');
list($code, $out, $err) = backup_cli('restore', array('_' => array($inBucket), 'from' => 's3', 'yes' => true));
pin('backup:restore --from=s3 fetches and restores it', 0, $code);
pin('...and the database is back', 'before', $marker());

list($code) = backup_cli('delete', array('_' => array($inBucket), 'from' => 's3'));
pin('backup:delete asks first too', 2, $code);
list($code) = backup_cli('delete', array('_' => array($inBucket), 'from' => 's3', 'yes' => true));
pin('backup:delete --from=s3 --yes deletes it', 0, $code);
check('...zip and manifest', !isset($client->objects[BackupBucket::key($inBucket)]) && !isset($client->objects[BackupBucket::sidecarKey($inBucket)]));
list($code) = backup_cli('delete', array('_' => array($name), 'yes' => true));
pin('backup:delete on the server', 0, $code);
check('...removes it', !is_file($store->dir() . $name));

harness_section('Nothing secret is printed');

foreach (array(TEST_ACCESS_KEY, TEST_SECRET_KEY, 'X-Amz-Signature', 'X-Amz-Credential') as $secret) {
    check("no '$secret' in anything printed", strpos($GLOBALS['printed'], $secret) === false);
}
check('...though the bucket was used with those keys', count($client->calls('putObject')) + count($client->calls('uploadPart')) > 0);

BackupBucket::use(false);
BackupBucket::useBase(null);

exit(harness_result());
