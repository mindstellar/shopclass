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
 * The bucket methods are optional: the StorageAdapter interface is unchanged, so a plugin
 * adapter written against it still loads, and one without list() (or any other bucket
 * method) makes the bucket option disappear instead of failing. The separate backups
 * bucket is used when set, and a shared public photo bucket is flagged.
 *
 * No database. Usage:  php tests/storage-adapter-optional-methods.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once __DIR__ . '/lib/fake-s3.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\backup\BackupBucket;
use mindstellar\backup\BackupService;
use mindstellar\storage\S3Storage;
use mindstellar\storage\StorageAdapter;
use mindstellar\storage\StorageManager;

$GLOBALS['prefs'] = array();
BackupBucket::useBase('https://www.example.com/');
$ownFolder = 'www.example.com-2a0b0c8e';
function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['prefs'][$key] ?? '';
}
function osc_job_stats($type = null)
{
    return array('pending' => 0, 'running' => 0);
}

/** A plugin adapter written against the interface only. */
class PluginAdapter implements StorageAdapter
{
    public function getId(): string
    {
        return 'plugin';
    }

    public function put(string $localPath, string $key, string $contentType): bool
    {
        return true;
    }

    public function get(string $key): string|false
    {
        return false;
    }

    public function exists(string $key): bool
    {
        return false;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function url(string $key): string
    {
        return '';
    }

    public function isRemote(): bool
    {
        return true;
    }

    public function isPublic(): bool
    {
        return true;
    }
}

/** A plugin adapter with every bucket method but list(). */
class AlmostAdapter extends PluginAdapter
{
    public function getId(): string
    {
        return 'almost';
    }

    public function putLarge(string $localPath, string $key, callable $progress, array &$state): bool
    {
        return true;
    }

    public function abortLarge(string $key, array &$state): void
    {
    }

    public function getLarge(string $key, string $localPath, callable $progress, array &$state): bool
    {
        return true;
    }

    public function deleteMany(array $keys): bool
    {
        return true;
    }

    public function downloadUrl(string $key, int $ttl, string $filename): string
    {
        return '';
    }
}

/** The same with list(), but no withBucket(): backups share its bucket. */
class FullAdapter extends AlmostAdapter
{
    public function getId(): string
    {
        return 'full';
    }

    public function list(string $prefix): array|false
    {
        return array();
    }
}

harness_section('The interface is unchanged');

$methods = array_map(static function (ReflectionMethod $m) {
    return $m->getName();
}, (new ReflectionClass(StorageAdapter::class))->getMethods());
pin('StorageAdapter still has its eight methods only', array('getId', 'put', 'get', 'exists', 'delete', 'url', 'isRemote', 'isPublic'), $methods);
foreach (BackupBucket::METHODS as $method) {
    check("$method is not on the interface", !in_array($method, $methods, true));
}
check('an adapter written against the interface alone still loads', new PluginAdapter() instanceof StorageAdapter);

harness_section('Without the bucket methods, no bucket option');

$manager = StorageManager::instance();
foreach (array(new PluginAdapter(), new AlmostAdapter(), new FullAdapter()) as $adapter) {
    $manager->register($adapter);
}
$GLOBALS['prefs']['storage_active'] = 'plugin';
pin('an interface-only adapter is not a bucket', false, BackupBucket::supports(new PluginAdapter()));
pin('...so there is no bucket to save to', null, BackupBucket::adapter());
$GLOBALS['prefs']['storage_active'] = 'almost';
pin('an adapter without list() is not a bucket', false, BackupBucket::supports(new AlmostAdapter()));
pin('...so there is no bucket to save to either', null, BackupBucket::adapter());
pin('...and a backup to the bucket is refused in words, not with an error',
    'Saving to a bucket needs S3 storage turned on in Settings > Storage.',
    BackupService::startBackup('everything', 'bucket'));
$GLOBALS['prefs']['storage_active'] = 'local';
pin('with offload off there is no bucket', null, BackupBucket::adapter());

$controller = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminTools.php');
$view       = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/tools/backup.php');
check('the page is told of a bucket only when there is one', strpos($controller, "'backup_bucket', \$bucket !== null ? array(") !== false);
check('...and offers the bucket choice only then, and not over plain http', (bool) preg_match("/if \\(\\\$bucket !== null && !\\\$plain\\) \\{\\s*\\\$places\\['bucket'\\] = array\\(/", $view));

harness_section('With them, the bucket option');

$GLOBALS['prefs']['storage_active'] = 'full';
$full = BackupBucket::adapter();
pin('an adapter with every method is the bucket', 'full', $full !== null ? $full->getId() : null);
$GLOBALS['prefs']['storage_s3_backup_bucket'] = 'private-backups';
pin('...without withBucket() a backups bucket cannot be used, so backups share its bucket', true, BackupBucket::shared());
pin('...which is public, so the page warns', true, BackupBucket::exposed());

$client = new FakeS3Client();
$manager->register(new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'photos', 'access_key' => 'k', 'secret_key' => 's', 'client' => $client)));
$GLOBALS['prefs']['storage_active']           = 's3';
$GLOBALS['prefs']['storage_s3_bucket']        = 'photos';
$GLOBALS['prefs']['storage_s3_public_url']    = 'https://cdn.example';
$GLOBALS['prefs']['storage_s3_backup_bucket'] = '';
pin('the core S3 adapter with no backups bucket uses the photo bucket', true, BackupBucket::shared());
pin('...under backups/ in the site folder', 'photos/backups/' . $ownFolder . '/', BackupBucket::label());
pin('...and warns while it has a public URL', true, BackupBucket::exposed());
$GLOBALS['prefs']['storage_s3_public_url'] = '';
pin('...and while it serves photos without signed links', true, BackupBucket::exposed());
$GLOBALS['prefs']['storage_s3_backup_bucket'] = 'private-backups';
pin('a backups bucket is used when set', false, BackupBucket::shared());
pin('...shown by name', 'private-backups/backups/' . $ownFolder . '/', BackupBucket::label());
pin('...with no warning', false, BackupBucket::exposed());
$adapter = BackupBucket::adapter();
$state   = array();
$file    = tempnam(sys_get_temp_dir(), 'osc');
file_put_contents($file, 'zip');
$adapter->putLarge($file, 'backups/x.zip', static function (): bool {
    return true;
}, $state);
@unlink($file);
pin('...and the upload goes to it', 'private-backups', $client->calls[0]['params']['Bucket']);
pin('...as a private copy of the photo adapter', false, $adapter->isPublic());
pin('...while the photo adapter keeps its bucket', 'photos', (static function () use ($manager) {
    $state = array();
    $file  = tempnam(sys_get_temp_dir(), 'osc');
    file_put_contents($file, 'x');
    $client = new FakeS3Client();
    (new S3Storage(array('bucket' => 'photos', 'client' => $client)))->putLarge($file, 'k', static function (): bool {
        return true;
    }, $state);
    @unlink($file);

    return $client->calls[0]['params']['Bucket'];
})());
harness_section('A plain http endpoint');

$manager->register(new S3Storage(array('endpoint' => 'http://s3.example.com', 'bucket' => 'photos', 'client' => $client)));
pin('a public plain http endpoint is flagged', true, BackupBucket::insecure());
pin('...and a backup to the bucket is refused',
    'Your S3 endpoint uses plain http, so a backup and its download link would travel unencrypted. Use an https endpoint.',
    BackupService::startBackup('everything', 'bucket'));
$manager->register(new S3Storage(array('endpoint' => 'http://minio:9000', 'bucket' => 'photos', 'client' => $client)));
pin('a plain http endpoint on the private network is not', false, BackupBucket::insecure());
check('the page warns and hides the bucket choice', strpos($view, '$plain   = $bucket !== null && BackupBucket::insecure();') !== false
    && strpos($view, "if (\$plain) {") !== false);
$manager->register(new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'photos', 'client' => $client)));

$GLOBALS['prefs']['storage_s3_backup_bucket'] = 'photos';
pin('naming the photo bucket as the backups bucket still counts as shared', true, BackupBucket::shared());

exit(harness_result());

/* file end: ./tests/storage-adapter-optional-methods.php */
