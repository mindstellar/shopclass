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
 * Backups in the bucket: every key sits under backups/ with 16 random characters in its
 * name and its manifest beside it; the listing counts only those; the prune keeps the
 * newest N of each kind, on the server and in the bucket, and deletes the manifest with
 * the zip; a download link lives 15 minutes and saves under the backup's name.
 *
 * No network: S3Storage runs on a fake client.
 * Usage:  php tests/backup-s3-keys.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once __DIR__ . '/lib/fake-s3.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use AsyncAws\S3\S3Client;
use mindstellar\backup\BackupBucket;
use mindstellar\backup\BackupStore;
use mindstellar\storage\S3Storage;

$client = new FakeS3Client();
$bucket = new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'shop-backups', 'access_key' => 'k', 'secret_key' => 's', 'client' => $client));
$store  = new BackupStore(sys_get_temp_dir() . '/osc_s3_keys_' . getmypid());

harness_section('Keys');

$name = BackupStore::newName('everything');
pin('a backup name is date, time, what and 16 random base32 characters', 1, preg_match('/^\d{4}-\d{2}-\d{2}-\d{6}-everything-[a-z2-7]{16}\.zip$/', $name));
check('...so two made in the same second differ', BackupStore::newName('database') !== BackupStore::newName('database'));
pin('its key is under backups/', 'backups/' . $name, BackupBucket::key($name));
pin('...with the manifest beside it', 'backups/' . substr($name, 0, -4) . '.json', BackupBucket::sidecarKey($name));
pin('the bucket needs every optional method', array('putLarge', 'abortLarge', 'getLarge', 'list', 'deleteMany', 'downloadUrl'), BackupBucket::METHODS);
check('...and the core S3 adapter has them all', BackupBucket::supports($bucket));

harness_section('The listing');

/** A backup in the bucket, $age minutes old; with its manifest unless told not to. */
$seed = static function (string $what, int $age, bool $sidecar = true) use ($client): string {
    $name = date('Y-m-d-His', time() - $age * 60) . '-' . $what . '-' . BackupStore::random(16) . '.zip';
    $client->objects[BackupBucket::key($name)] = str_repeat('z', 100 + $age);
    if ($sidecar) {
        $client->objects[BackupBucket::sidecarKey($name)] = '{}';
    }

    return $name;
};
$newest  = $seed('database', 1);
$older   = $seed('everything', 50);
$noSide  = $seed('files', 5, false);
$client->objects['backups/notes.zip']            = 'x';
$client->objects['backups/sub/' . $newest]       = 'x';
$client->objects['photos/' . $older]             = 'x';
$client->objects['backups/upload-aaaaaaaaaaaaaaaa.zip'] = 'x';

$rows = $store->bucketAll($bucket);
pin('only named backups with a manifest beside them are listed, newest first', array($newest, $older), array_column($rows, 'name'));
pin('...each said to be in the bucket', array('bucket', 'bucket'), array_column($rows, 'where'));
pin('...with its size from the listing', 150, $rows[1]['size']);
pin('...and what it holds from its name', 'everything', $rows[1]['what']);
pin('...and its date from its name', date('c', strtotime(substr($older, 0, 10) . ' ' . implode(':', str_split(substr($older, 11, 6), 2)))), $rows[1]['created']);
pin('...from one request', 1, count($client->calls('listObjectsV2')));
pin('...under backups/', 'backups/', $client->calls('listObjectsV2')[0]['params']['Prefix']);

$broken = new FakeS3Client();
$broken->before = static function (): void {
    throw new RuntimeException('Access denied');
};
pin('a bucket that cannot be read lists nothing, and says so', null, $store->bucketAll(new S3Storage(array('bucket' => 'b', 'client' => $broken))));

harness_section('Prune keeps the newest N of each kind');

$rows = array(
    array('name' => 'f', 'kind' => 'safety'),
    array('name' => 'e', 'kind' => 'backup'),
    array('name' => 'd', 'kind' => 'safety'),
    array('name' => 'c', 'kind' => 'backup'),
    array('name' => 'b', 'kind' => 'safety'),
    array('name' => 'a', 'kind' => 'backup'),
);
pin('backups past 2 go, oldest first in the list', array('a'), BackupStore::pruneNames($rows, 'backup', 2));
pin('...safety copies are counted on their own', array('b'), BackupStore::pruneNames($rows, 'safety', 2));
pin('...keep 1 leaves the newest only', array('c', 'a'), BackupStore::pruneNames($rows, 'backup', 1));
pin('...a keep below 1 still keeps one', array('c', 'a'), BackupStore::pruneNames($rows, 'backup', 0));
pin('...and nothing goes while there are fewer than N', array(), BackupStore::pruneNames($rows, 'backup', 5));

$client->objects = array();
$names = array();
for ($i = 0; $i < 7; $i++) {
    $names[] = $seed('everything', 10 + $i * 10);
}
$other = 'backups/notes.zip';
$client->objects[$other] = 'keep me';
$client->calls = array();
pin('the bucket prune deletes the two past 5', 2, $store->bucketPrune($bucket, 5));
pin('...in one request', 1, count($client->calls('deleteObjects')));
pin('...zip and manifest of each', array(
    BackupBucket::key($names[5]), BackupBucket::sidecarKey($names[5]),
    BackupBucket::key($names[6]), BackupBucket::sidecarKey($names[6]),
), array_column($client->calls('deleteObjects')[0]['params']['Delete']['Objects'], 'Key'));
pin('...leaving the newest 5', array_slice($names, 0, 5), array_column($store->bucketAll($bucket), 'name'));
check('...and anything that is not a backup', isset($client->objects[$other]));
$client->calls = array();
pin('a second prune deletes nothing', 0, $store->bucketPrune($bucket, 5));
pin('...and sends no delete', 0, count($client->calls('deleteObjects')));

harness_section('Delete and download take a backup name only');

pin('a listed backup is deleted with its manifest', true, $store->bucketDelete($bucket, $names[0]));
check('...both keys gone', !isset($client->objects[BackupBucket::key($names[0])]) && !isset($client->objects[BackupBucket::sidecarKey($names[0])]));
$client->calls = array();
foreach (array('../' . $names[1], 'sub/' . $names[1], 'notes.zip', '', '../../photos/1.jpg') as $bad) {
    pin("refused: '$bad'", false, $store->bucketDelete($bucket, $bad));
    pin("...no link for '$bad'", '', $store->bucketLink($bucket, $bad));
}
pin('...without asking the bucket', array(), $client->calls);
pin('no link for a backup that is not there', '', $store->bucketLink($bucket, $names[0]));

$client->calls = array();
$link = $store->bucketLink($bucket, $names[1]);
check('a listed backup gets a link', $link !== '');
$presign = $client->calls('presign')[0]['params'];
pin('...to its key', BackupBucket::key($names[1]), $presign['Key']);
check('...that lives 15 minutes', abs($presign['expires'] - (time() + 900)) <= 5, $presign['expires'] - time());
pin('...the link time is 15 minutes', 900, BackupBucket::LINK_TTL);

$real = new S3Client(array('endpoint' => 'https://s3.example', 'region' => 'auto', 'accessKeyId' => 'AKIAFAKE', 'accessKeySecret' => 'not-a-secret', 'pathStyleEndpoint' => true));
$url  = (new S3Storage(array('bucket' => 'shop-backups', 'client' => $real)))->downloadUrl('backups/' . $names[1], 900, $names[1]);
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
pin('the real link is signed for 900 seconds', '900', $query['X-Amz-Expires'] ?? null);
pin('...and saves under the backup name', 'attachment; filename="' . $names[1] . '"', $query['response-content-disposition'] ?? null);
check('...and never carries the secret', strpos($url, 'not-a-secret') === false);
$url = (new S3Storage(array('bucket' => 'b', 'client' => $real)))->downloadUrl('k', 999999, 'x.zip');
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
pin('a link never lives past an hour', '3600', $query['X-Amz-Expires'] ?? null);

harness_section('A separate bucket is private');

$photos = new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'photos', 'public_url_base' => 'https://cdn.example', 'client' => $client));
$copy   = $photos->withBucket('shop-backups');
pin('the photo adapter is public', true, $photos->isPublic());
pin('...its backups copy is not', false, $copy->isPublic());
check('...and has no public URL', strpos($copy->url('backups/x.zip'), 'cdn.example') === false);
pin('...while the photo adapter is left as it was', 'https://cdn.example/x.jpg', $photos->url('x.jpg'));

exit(harness_result());

/* file end: ./tests/backup-s3-keys.php */
