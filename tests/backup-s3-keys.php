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
 * Backups in the bucket: every key sits under backups/<site>/ with 16 random characters
 * in its name and its manifest beside it; the listing counts only those; the prune keeps
 * the newest N of each kind, on the server and in the bucket, and deletes the manifest
 * with the zip; a download link lives 15 minutes and saves under the backup's name. Two
 * sites sharing a bucket never see or prune each other's backups. The page's listing has
 * a short timeout and is kept a minute; an upload readable without signing is flagged;
 * a plain http endpoint on a public address is refused.
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
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$GLOBALS['prefs'] = array();
function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['prefs'][$key] ?? '';
}
function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
{
    $GLOBALS['prefs'][$key] = $value;

    return true;
}
function osc_job_stats($type = null)
{
    return array('pending' => 0, 'running' => 0);
}

BackupBucket::useBase('https://www.example.com/');
$ownFolder = 'www.example.com-2a0b0c8e';

$client = new FakeS3Client();
$bucket = new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'shop-backups', 'access_key' => 'k', 'secret_key' => 's', 'client' => $client));
$store  = new BackupStore(sys_get_temp_dir() . '/osc_s3_keys_' . getmypid());

harness_section('Keys');

$name = BackupStore::newName('everything');
pin('a backup name is date, time, what and 16 random base32 characters', 1, preg_match('/^\d{4}-\d{2}-\d{2}-\d{6}-everything-[a-z2-7]{16}\.zip$/', $name));
check('...so two made in the same second differ', BackupStore::newName('database') !== BackupStore::newName('database'));
pin('its key is under backups/ in the site folder', 'backups/' . $ownFolder . '/' . $name, BackupBucket::key($name));
pin('...with the manifest beside it', 'backups/' . $ownFolder . '/' . substr($name, 0, -4) . '.json', BackupBucket::sidecarKey($name));

harness_section('The site folder comes from the site address');

foreach (array(
    'https://www.example.com/'              => 'www.example.com',
    'https://WWW.Example.com/Classifieds/'  => 'www.example.com-classifieds',
    'http://localhost:8000/'                => 'localhost-8000',
    'https://example.com/a/b_c d/'          => 'example.com-a-b-c-d',
    'https://example.com/../x/'             => 'example.com-x',
    'example.com'                           => 'example.com',
    ''                                      => 'site',
) as $url => $readable) {
    check("'$url' saves under $readable plus a hash", (bool) preg_match('/^' . preg_quote($readable, '/') . '-[0-9a-f]{8}$/', BackupBucket::siteFolder($url)), BackupBucket::siteFolder($url));
}
pin('the same address always gives the same folder', BackupBucket::siteFolder('https://www.example.com/'), BackupBucket::siteFolder('https://www.example.com'));
pin('...host case does not matter', BackupBucket::siteFolder('https://www.example.com/'), BackupBucket::siteFolder('https://WWW.EXAMPLE.COM/'));
foreach (array(
    array('https://a.com/b/', 'https://a.com-b/'),
    array('https://a.com:8080/', 'https://a.com-8080/'),
    array('https://a.com/shop_x/', 'https://a.com/shop-x/'),
    array('https://a.com/Shop/', 'https://a.com/shop/'),
) as $pair) {
    check("$pair[0] and $pair[1] get different folders", BackupBucket::siteFolder($pair[0]) !== BackupBucket::siteFolder($pair[1]), BackupBucket::siteFolder($pair[0]));
}
$long = BackupBucket::siteFolder('https://example.com/' . str_repeat('very-long-path/', 10));
pin('a long address is cut to 60 characters', 60, strlen($long));
check('...ending in a hash of the whole', (bool) preg_match('/^example\.com-very-long-path-.*-[0-9a-f]{8}$/', $long), $long);
check('...so two long addresses that differ at the end differ', $long !== BackupBucket::siteFolder('https://example.com/' . str_repeat('very-long-path/', 10) . 'x/'));
check('every folder is a-z 0-9 . and - only', (bool) preg_match('/^[a-z0-9.-]+$/', $long));

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
$client->objects['backups/www.example.com/notes.zip'] = 'x';
$client->objects['backups/' . $older]            = 'x';
$client->objects['backups/' . substr($older, 0, -4) . '.json'] = '{}';
$client->objects['backups/sub/' . $newest]       = 'x';
$client->objects['photos/' . $older]             = 'x';
$client->objects['backups/upload-aaaaaaaaaaaaaaaa.zip'] = 'x';

$rows = $store->bucketAll($bucket, true);
pin('only named backups with a manifest beside them are listed, newest first', array($newest, $older), array_column($rows, 'name'));
pin('...each said to be in the bucket', array('bucket', 'bucket'), array_column($rows, 'where'));
pin('...with its size from the listing', 150, $rows[1]['size']);
pin('...and what it holds from its name', 'everything', $rows[1]['what']);
pin('...and its date from its name', date('c', strtotime(substr($older, 0, 10) . ' ' . implode(':', str_split(substr($older, 11, 6), 2)))), $rows[1]['created']);
pin('...from one request', 1, count($client->calls('listObjectsV2')));
pin('...under the site folder', 'backups/' . $ownFolder . '/', $client->calls('listObjectsV2')[0]['params']['Prefix']);

$broken = new FakeS3Client();
$broken->before = static function (): void {
    throw new RuntimeException('Access denied');
};
pin('a bucket that cannot be read lists nothing, and says so', null, $store->bucketAll(new S3Storage(array('bucket' => 'b', 'client' => $broken)), true));
pin('...as an error, not a timeout', 'error', BackupBucket::listFailure());

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
$other = 'backups/www.example.com/notes.zip';
$client->objects[$other] = 'keep me';
$client->calls = array();
pin('the bucket prune deletes the two past 5', 2, $store->bucketPrune($bucket, 5));
pin('...in one request', 1, count($client->calls('deleteObjects')));
pin('...zip and manifest of each', array(
    BackupBucket::key($names[5]), BackupBucket::sidecarKey($names[5]),
    BackupBucket::key($names[6]), BackupBucket::sidecarKey($names[6]),
), array_column($client->calls('deleteObjects')[0]['params']['Delete']['Objects'], 'Key'));
pin('...leaving the newest 5', array_slice($names, 0, 5), array_column($store->bucketAll($bucket, true), 'name'));
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

harness_section('Two sites sharing one bucket');

$shared = new FakeS3Client();
$shop   = new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'shared', 'client' => $shared));
$sites  = array('prod' => 'https://shop.example.com/', 'staging' => 'https://staging.shop.example.com/');
$made   = array();
foreach ($sites as $site => $url) {
    BackupBucket::useBase($url);
    for ($i = 0; $i < 4; $i++) {
        $n = $seed('everything', ($site === 'prod' ? 10 : 15) + $i * 10);
        $shared->objects[BackupBucket::key($n)]        = 'zip';
        $shared->objects[BackupBucket::sidecarKey($n)] = '{}';
        $made[$site][] = $n;
    }
}
$storeFor = static function (string $site) use ($sites, $shop) {
    BackupBucket::useBase($sites[$site]);

    return new BackupStore(sys_get_temp_dir() . '/osc_s3_keys_' . $site . '_' . getmypid());
};
pin('production lists only its own backups', $made['prod'], array_column((array) $storeFor('prod')->bucketAll($shop, true), 'name'));
pin('...and staging only its own', $made['staging'], array_column((array) $storeFor('staging')->bucketAll($shop, true), 'name'));
pin('staging keeping 1 prunes 3 of its own', 3, $storeFor('staging')->bucketPrune($shop, 1));
foreach ($made['prod'] as $n) {
    BackupBucket::useBase($sites['prod']);
    check('...and never production\'s ' . substr($n, 0, 17), isset($shared->objects[BackupBucket::key($n)], $shared->objects[BackupBucket::sidecarKey($n)]));
}
pin('production still lists all 4', $made['prod'], array_column((array) $storeFor('prod')->bucketAll($shop, true), 'name'));
$prodStore = $storeFor('staging');
pin('staging cannot delete a production backup by name', true, $prodStore->bucketDelete($shop, $made['prod'][0]));
BackupBucket::useBase($sites['prod']);
check('...it only asks for its own folder, so production keeps it', isset($shared->objects[BackupBucket::key($made['prod'][0])]));
BackupBucket::useBase($sites['staging']);
pin('...nor link to one', '', $prodStore->bucketLink($shop, $made['prod'][1]));
BackupBucket::useBase('https://www.example.com/');

harness_section('The page lists with a short timeout and keeps the list a minute');

$pageDir   = sys_get_temp_dir() . '/osc_s3_page_' . getmypid();
@mkdir($pageDir, 0700, true);
register_shutdown_function(static function () use ($pageDir) {
    exec('rm -rf ' . escapeshellarg($pageDir));
});
$pageStore = new BackupStore($pageDir);
$slow      = new FakeS3Client();
$slowS3    = new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'b', 'client' => $slow));
$first     = $seed('database', 3);
$slow->objects[BackupBucket::key($first)]        = 'zip';
$slow->objects[BackupBucket::sidecarKey($first)] = '{}';
pin('the page lists the bucket', array($first), array_column((array) $pageStore->bucketAll($slowS3), 'name'));
$second = $seed('files', 2);
$slow->objects[BackupBucket::key($second)]        = 'zip';
$slow->objects[BackupBucket::sidecarKey($second)] = '{}';
$slow->calls = array();
pin('...and the next view within a minute uses that list', array($first), array_column((array) $pageStore->bucketAll($slowS3), 'name'));
pin('...without asking the bucket', 0, count($slow->calls('listObjectsV2')));
$pageStore->bucketDelete($slowS3, 'nothing-here.zip');
pin('...a refused delete keeps it', 0, count($slow->calls('listObjectsV2')) + (int) !is_file($pageDir . '/.bucket-list.json'));
$pageStore->bucketDelete($slowS3, $first);
pin('a delete forgets it', array($second), array_column((array) $pageStore->bucketAll($slowS3), 'name'));
pin('a prune forgets it too', 0, $pageStore->bucketPrune($slowS3, 5) + (int) is_file($pageDir . '/.bucket-list.json'));

$slow->before = static function (string $op): void {
    if ($op === 'listObjectsV2') {
        throw new \AsyncAws\Core\Exception\Http\NetworkException('Could not contact remote server.', 0, new TimeoutException('Idle timeout reached for "https://s3.example/b".'));
    }
};
pin('a bucket that does not answer lists nothing', null, $pageStore->bucketAll($slowS3));
pin('...and the page is told it timed out', 'timeout', BackupBucket::listFailure());
$slow->before = null;
pin('...which is kept a minute too, so the page stays quick', null, $pageStore->bucketAll($slowS3));
pin('...still as a timeout', 'timeout', BackupBucket::listFailure());
$pageStore->forgetBucketList();
pin('once forgotten, the bucket is asked again', array($second), array_column((array) $pageStore->bucketAll($slowS3), 'name'));
pin('...and the failure is cleared', '', BackupBucket::listFailure());
file_put_contents($pageDir . '/.bucket-list.json', json_encode(array('id' => BackupBucket::label() . '|' . BackupBucket::prefix(), 'at' => time() - 61, 'rows' => array(), 'failure' => '')));
pin('a list older than a minute is not used', array($second), array_column((array) $pageStore->bucketAll($slowS3), 'name'));

$timed = (new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'b')))->withTimeout(BackupBucket::PAGE_TIMEOUT);
$prop  = new ReflectionProperty(S3Storage::class, 'timeout');
$prop->setAccessible(true);
pin('the page listing gives up after 5 seconds', 5.0, $prop->getValue($timed));
$spy = new class (array('endpoint' => 'https://s3.example', 'bucket' => 'b', 'client' => $slow)) extends S3Storage {
    /** @var float[] */
    public $asked = array();

    public function withTimeout(float $seconds): static
    {
        $this->asked[] = $seconds;

        return parent::withTimeout($seconds);
    }
};
$pageStore->forgetBucketList();
$pageStore->bucketAll($spy);
pin('...which the page listing asks for', array(5.0), $spy->asked);
$pageStore->bucketPrune($spy, 5);
pin('...while a prune waits as long as the bucket needs', array(5.0), $spy->asked);
pin('...and the list is kept 60 seconds', 60, BackupBucket::LIST_TTL);

// A server that takes the connection and never answers: the real client gives up in time.
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server !== false) {
    $port    = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);
    $mute    = (new S3Storage(array('endpoint' => 'http://127.0.0.1:' . $port, 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's', 'path_style' => true)))->withTimeout(1.0);
    $started = microtime(true);
    $result  = $mute->list('backups/');
    $took    = microtime(true) - $started;
    pin('a bucket that never answers fails the listing', false, $result);
    check('...within the timeout, with no retries', $took < 3.0, round($took, 2) . 's');
    pin('...and says it timed out', true, $mute->timedOut());
    fclose($server);
}

harness_section('An upload readable without signing is flagged');

$heads = array();
$open  = 200;
$http  = new MockHttpClient(static function (string $method, string $url, array $options) use (&$heads, &$open): MockResponse {
    $heads[] = array('method' => $method, 'url' => $url, 'headers' => implode("\n", (array) ($options['headers'] ?? array())), 'timeout' => $options['max_duration'] ?? null);

    return new MockResponse('', array('http_code' => $open));
});
$vhost = new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'shop-backups', 'client' => $client, 'http' => $http));
pin('an object that answers 200 unsigned is public', true, $vhost->publiclyReadable('backups/www.example.com/a.zip'));
pin('...asked with HEAD', 'HEAD', $heads[0]['method']);
pin('...at its plain virtual-host URL', 'https://shop-backups.s3.example/backups/www.example.com/a.zip', $heads[0]['url']);
check('...with no signature', stripos($heads[0]['headers'], 'authorization') === false && strpos($heads[0]['url'], 'X-Amz') === false);
pin('...and a short timeout', 3.0, $heads[0]['timeout']);
$path = new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'shop-backups', 'path_style' => true, 'client' => $client, 'http' => $http));
$path->publiclyReadable('k.zip');
pin('a path-style endpoint is asked in path style', 'https://s3.example/shop-backups/k.zip', $heads[1]['url']);
$cdn = (new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'photos', 'public_url_base' => 'https://cdn.example', 'client' => $client, 'http' => $http)));
$cdn->publiclyReadable('k.zip');
pin('...and the endpoint is asked even behind a public URL', 'https://photos.s3.example/k.zip', $heads[2]['url']);
foreach (array(403, 404, 301) as $code) {
    $open = $code;
    pin("an answer of $code is private", false, $vhost->publiclyReadable('k.zip'));
}
$open = 200;

$GLOBALS['prefs']['storage_s3_bucket'] = 'shop-backups';
pin('the check flags this bucket when it answers', true, BackupBucket::checkPublic($vhost, 'k.zip'));
pin('...by its label', BackupBucket::label(), $GLOBALS['prefs'][BackupBucket::PUBLIC_FLAG]);
pin('...so the page warns', true, BackupBucket::flaggedPublic());
$GLOBALS['prefs']['storage_s3_bucket'] = 'other-bucket';
pin('...but not once backups go to another bucket', false, BackupBucket::flaggedPublic());
$GLOBALS['prefs']['storage_s3_bucket'] = 'shop-backups';
$open = 403;
pin('a later private answer clears the flag', false, BackupBucket::checkPublic($vhost, 'k.zip'));
pin('...so the page does not warn', false, BackupBucket::flaggedPublic());
$http2 = new MockHttpClient(static function (): MockResponse {
    throw new TimeoutException('Idle timeout reached');
});
pin('a check that times out counts as private', false, (new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'b', 'client' => $client, 'http' => $http2)))->publiclyReadable('k'));
pin('an adapter without the check is never flagged', false, BackupBucket::checkPublic(new stdClass(), 'k'));

harness_section('A plain http endpoint on a public address is refused');

foreach (array(
    'http://s3.example.com'        => true,
    'http://203.0.113.9:9000'      => true,
    'http://8.8.8.8'               => true,
    'https://s3.example.com'       => false,
    's3.example.com'               => false,
    'http://localhost:9000'        => false,
    'http://minio.localhost'       => false,
    'http://127.0.0.1:9000'        => false,
    'http://10.0.0.5'              => false,
    'http://192.168.1.20:9000'     => false,
    'http://172.20.0.3'            => false,
    'http://[::1]:9000'            => false,
    'http://minio:9000'            => false,
) as $endpoint => $plain) {
    pin(($plain ? 'refused: ' : 'allowed: ') . $endpoint, $plain, (new S3Storage(array('endpoint' => $endpoint, 'bucket' => 'b', 'client' => $client)))->plainHttp());
}

harness_section('A separate bucket is private');

$photos = new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'photos', 'public_url_base' => 'https://cdn.example', 'client' => $client));
$copy   = $photos->withBucket('shop-backups');
pin('the photo adapter is public', true, $photos->isPublic());
pin('...its backups copy is not', false, $copy->isPublic());
check('...and has no public URL', strpos($copy->url('backups/x.zip'), 'cdn.example') === false);
pin('...while the photo adapter is left as it was', 'https://cdn.example/x.jpg', $photos->url('x.jpg'));

harness_section('The bucket needs a site address that is set, not taken from the request');

$fake = new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'b', 'client' => new FakeS3Client()));
BackupBucket::use($fake);
BackupBucket::useBase(null);
pin('with no WEB_PATH there is no bucket', null, BackupBucket::adapter());
pin('...and the page and the command line say why', 'Set WEB_PATH in config.php or the environment to use the bucket.', BackupBucket::addressProblem());
pin('...as does a backup started for the bucket', BackupBucket::addressMessage(), \mindstellar\backup\BackupService::startBackup('database', 'bucket'));
define('WEB_PATH', 'https://shop.example.com/');
pin('a WEB_PATH from config.php gives the bucket', $fake, BackupBucket::adapter());
pin('...with nothing to complain about', '', BackupBucket::addressProblem());
define('OSC_WEB_PATH_FROM_REQUEST', true);
pin('a WEB_PATH taken from the Host header gives none', null, BackupBucket::adapter());
BackupBucket::use(false);
BackupBucket::useBase('https://www.example.com/');

exit(harness_result());

/* file end: ./tests/backup-s3-keys.php */
