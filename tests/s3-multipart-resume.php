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
 * S3Storage's large upload and download: a file under one part is one PutObject; above,
 * a multipart upload that stops after part 3 carries on at part 4 and completes with every
 * part in order; a failure aborts the upload; the object's size is checked before the file
 * counts as uploaded; no request ever carries an ACL, checked on the wire of the real
 * client too; a download in ranges stops and carries on the same way.
 *
 * No network: a fake client records calls, and the real client talks to a mock HTTP client.
 * Usage:  php tests/s3-multipart-resume.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/fake-s3.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use AsyncAws\S3\S3Client;
use mindstellar\storage\S3Storage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$dir = sys_get_temp_dir() . '/osc_s3_multipart_' . getmypid();
@mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir) {
    exec('rm -rf ' . escapeshellarg($dir));
});

$storage = static function (FakeS3Client $client): S3Storage {
    return new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'shop-backups', 'access_key' => 'k', 'secret_key' => 's', 'client' => $client));
};
$always = static function (): bool {
    return true;
};
/** No call carried an ACL of any kind. */
$noAcl = static function (FakeS3Client $client): bool {
    foreach ($client->calls as $call) {
        foreach (array_keys($call['params']) as $key) {
            if (stripos((string) $key, 'acl') !== false || stripos((string) $key, 'grant') !== false) {
                return false;
            }
        }
    }

    return true;
};

harness_section('Under one part: one PutObject');

$small = $dir . '/small.zip';
file_put_contents($small, str_repeat('s', 3000));
$client = new FakeS3Client();
$state  = array('part_size' => 1024 * 4);
pin('the upload succeeds', true, $storage($client)->putLarge($small, 'backups/small.zip', $always, $state));
pin('...and is done', true, $state['done']);
pin('...as a single PutObject, checked by size', array('putObject', 'headObject'), array_column($client->calls, 'op'));
pin('...of the whole file', str_repeat('s', 3000), $client->objects['backups/small.zip']);
pin('...as a zip', 'application/zip', $client->calls[0]['params']['ContentType']);
check('...with no ACL', $noAcl($client));
check('...and no long public cache header', !isset($client->calls[0]['params']['CacheControl']));
$state = array();
$json  = $dir . '/small.json';
file_put_contents($json, '{}');
$storage($client)->putLarge($json, 'backups/small.json', $always, $state);
pin('a manifest goes up as JSON', 'application/json', $client->calls[2]['params']['ContentType']);

harness_section('A stop after part 3 carries on at part 4');

$big   = $dir . '/big.zip';
$bytes = '';
for ($i = 0; $i < 4500; $i++) {
    $bytes .= chr(65 + $i % 26);
}
file_put_contents($big, $bytes);
$client = new FakeS3Client();
$s3     = $storage($client);
$state  = array('part_size' => 1000);
$seen   = array();
$stop   = static function (int $done, int $total) use (&$seen): bool {
    $seen[] = array($done, $total);

    return count($seen) < 3;
};
pin('the first call returns without an error', true, $s3->putLarge($big, 'backups/big.zip', $stop, $state));
pin('...not done yet', false, $state['done']);
pin('...after starting one upload and three parts', array('createMultipartUpload', 'uploadPart', 'uploadPart', 'uploadPart'), array_column($client->calls, 'op'));
pin('...with the upload id and three ETags kept', array('up0', 3), array($state['upload_id'], count($state['parts'])));
pin('...and progress told after each part', array(array(1000, 4500), array(2000, 4500), array(3000, 4500)), $seen);
check('...leaving no part copy behind', !is_file($big . '.upart'));

$client->calls = array();
pin('the next call carries on', true, $s3->putLarge($big, 'backups/big.zip', $always, $state));
$parts = $client->calls('uploadPart');
pin('...at part 4, then 5', array(4, 5), array_map(static function (array $c) {
    return $c['params']['PartNumber'];
}, $parts));
pin('...with no second upload started', 0, count($client->calls('createMultipartUpload')));
pin('...part 4 holds bytes 3000-3999', substr($bytes, 3000, 1000), $parts[0]['body']);
pin('...and the last part the remaining 500', substr($bytes, 4000), $parts[1]['body']);
$complete = $client->calls('completeMultipartUpload')[0]['params'];
pin('...completed with parts 1-5 in order', array(1, 2, 3, 4, 5), array_column($complete['MultipartUpload']['Parts'], 'PartNumber'));
pin('...each with its ETag', '"' . md5(substr($bytes, 1000, 1000)) . '"', $complete['MultipartUpload']['Parts'][1]['ETag']);
pin('the bucket holds the file byte for byte', $bytes, $client->objects['backups/big.zip']);
pin('...checked by size, then done', array('headObject', true), array(array_column($client->calls, 'op')[count($client->calls) - 1], $state['done']));
check('no call carried an ACL', $noAcl($client));

$state = array();
$s3->putLarge($small, 'backups/small.zip', $always, $state);
pin('the part size is 64 MB unless told otherwise', S3Storage::PART_SIZE, $state['part_size']);
pin('...so a 128 MB limit holds a part with room to spare', 64 * 1024 * 1024, S3Storage::PART_SIZE);
$client->calls = array();
file_put_contents($dir . '/huge.zip', str_repeat('h', 20001));
$state = array('part_size' => 1);
$s3->putLarge($dir . '/huge.zip', 'backups/huge.zip', static function (): bool {
    return false;
}, $state);
pin('a part size too small for 10,000 parts grows to fit S3\'s limit', 3, $state['part_size']);
$s3->abortLarge('backups/huge.zip', $state);

harness_section('A failure aborts the upload');

$client = new FakeS3Client();
$client->before = static function (string $op, array $p): void {
    if ($op === 'uploadPart' && $p['PartNumber'] === 2) {
        throw new RuntimeException("Upload failed at https://s3.example/shop-backups/backups/big.zip?uploadId=up0&X-Amz-Signature=abc\nsecond line");
    }
};
$state = array('part_size' => 1000);
pin('a failed part fails the call', false, $storage($client)->putLarge($big, 'backups/big.zip', $always, $state));
pin('...and aborts the upload', 1, count($client->calls('abortMultipartUpload')));
pin('...saying why in one line, without the query string', 'Upload failed at https://s3.example/shop-backups/backups/big.zip', $state['error']);
pin('...and forgets the upload id', '', $state['upload_id']);
check('...leaving no part copy behind', !is_file($big . '.upart'));

$client = new FakeS3Client();
$client->lieAboutSize = 10;
$state = array();
pin('a stored size that differs fails the upload', false, $storage($client)->putLarge($small, 'backups/small.zip', $always, $state));
check('...and it is not done', empty($state['done']));

$state = array();
pin('a file that is not there fails at once', false, $storage(new FakeS3Client())->putLarge($dir . '/nope.zip', 'backups/nope.zip', $always, $state));

harness_section('A download in ranges carries on where it stopped');

$client = new FakeS3Client();
$client->objects['backups/big.zip'] = $bytes;
$s3    = $storage($client);
$state = array();
$local = $dir . '/fetched.zip';
$ranges = 0;
$stopAfterOne = static function () use (&$ranges): bool {
    $ranges++;

    return false;
};
pin('the first call returns without an error', true, $s3->getLarge('backups/big.zip', $local, $stopAfterOne, $state));
check('...having fetched the whole object at once, since it is under one range', $state['done']);
pin('...byte for byte', $bytes, (string) file_get_contents($local));
pin('...readable by the site only', '600', substr(sprintf('%o', fileperms($local)), -3));
pin('...asking for it only while it has not changed', '"' . md5($bytes) . '"', $client->calls('getObject')[0]['params']['IfMatch']);

// A range stops early: the next call must truncate what it wrote and ask again from there.
$state = array('size' => strlen($bytes), 'etag' => '"' . md5($bytes) . '"', 'bytes_done' => 1500, 'done' => false);
file_put_contents($local, substr($bytes, 0, 1500) . 'garbage past the last good byte');
$client->calls = array();
pin('a resumed download returns without an error', true, $s3->getLarge('backups/big.zip', $local, $always, $state));
pin('...asking from byte 1500', 'bytes=1500-4499', $client->calls('getObject')[0]['params']['Range']);
pin('...and the file is whole', $bytes, (string) file_get_contents($local));
$client->objects['backups/big.zip'] = 'changed';
$state = array('size' => strlen($bytes), 'etag' => '"' . md5($bytes) . '"', 'bytes_done' => 1500, 'done' => false);
pin('an object changed since the first range fails the download', false, $s3->getLarge('backups/big.zip', $local, $always, $state));

harness_section('The real client: nothing on the wire names an ACL');

$requests = array();
$http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
    $body = '';
    if (isset($options['body'])) {
        if (is_string($options['body'])) {
            $body = $options['body'];
        } elseif (is_callable($options['body'])) {
            while (($chunk = ($options['body'])(65536)) !== '') {
                $body .= $chunk;
            }
        } elseif (is_iterable($options['body'])) {
            foreach ($options['body'] as $chunk) {
                $body .= $chunk;
            }
        }
    }
    $requests[] = array('method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? array(), 'size' => strlen($body));
    if ($method === 'POST' && strpos($url, '?uploads') !== false) {
        return new MockResponse('<InitiateMultipartUploadResult><UploadId>real-up</UploadId></InitiateMultipartUploadResult>');
    }
    if ($method === 'HEAD') {
        return new MockResponse('', array('response_headers' => array('content-length' => '4500', 'etag' => '"x"')));
    }
    if ($method === 'POST') {
        return new MockResponse('<CompleteMultipartUploadResult><ETag>"x-5"</ETag></CompleteMultipartUploadResult>');
    }

    return new MockResponse('', array('response_headers' => array('etag' => '"p' . count($requests) . '"')));
});
$real = new S3Client(array(
    'endpoint'          => 'https://s3.example',
    'region'            => 'us-east-1',
    'accessKeyId'       => 'AKIAFAKEFAKEFAKE',
    'accessKeySecret'   => 'not-a-real-secret',
    'pathStyleEndpoint' => true,
), null, $http);
$s3    = new S3Storage(array('endpoint' => 'https://s3.example', 'bucket' => 'shop-backups', 'access_key' => 'k', 'secret_key' => 's', 'client' => $real));
$state = array('part_size' => 1000);
pin('a multipart upload through the real client succeeds', true, $s3->putLarge($big, 'backups/big.zip', $always, $state));
pin('...in 8 requests: start, 5 parts, complete, size check', array('POST', 'PUT', 'PUT', 'PUT', 'PUT', 'PUT', 'POST', 'HEAD'), array_column($requests, 'method'));
pin('...each part carrying its own bytes only', array(1000, 1000, 1000, 1000, 500), array_column(array_slice($requests, 1, 5), 'size'));
$headers = strtolower(implode("\n", array_merge(...array_map(static function (array $r) {
    return array_map('strval', array_values($r['headers']));
}, $requests))));
check('...and no request carries x-amz-acl or a grant', strpos($headers, 'x-amz-acl') === false && strpos($headers, 'x-amz-grant') === false, $headers);
$requests = array();
$state    = array();
$s3->putLarge($small, 'backups/small.zip', $always, $state);
$headers = strtolower(implode("\n", array_map('strval', array_values($requests[0]['headers']))));
check('a single PutObject carries none either', $requests[0]['method'] === 'PUT' && strpos($headers, 'x-amz-acl') === false, $headers);

exit(harness_result());

/* file end: ./tests/s3-multipart-resume.php */
