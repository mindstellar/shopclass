<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\storage;

use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\S3\S3Client;
use DateTimeImmutable;
use Throwable;

/**
 * Class S3Storage
 *
 * S3-compatible storage adapter (AWS S3, MinIO, R2, ...), built on
 * async-aws/s3. Configured from a plain array so both the bundled
 * hStorage.php wiring and third-party plugins can construct one without
 * depending on Osclass's preference storage.
 *
 * @package mindstellar\storage
 */
class S3Storage implements StorageAdapter
{
    /** Bytes per multipart part, and the size under which one PutObject is used. */
    public const PART_SIZE = 67108864;

    /** S3's limit on parts per upload. */
    public const MAX_PARTS = 10000;

    private string $endpoint;

    private string $region;

    private string $bucket;

    private string $accessKey;

    private string $secretKey;

    private bool $pathStyle;

    private string $publicUrlBase;

    private bool $signedUrls;

    private int $signedTtl;

    /** @var S3Client|object|null an S3Client, or a stand-in with the same methods */
    private ?object $client = null;

    /**
     * @param array<string,mixed> $config connection settings:
     *     endpoint: string,
     *     region: string,
     *     bucket: string,
     *     access_key: string,
     *     secret_key: string,
     *     path_style?: bool,
     *     public_url_base?: string,
     *     signed_urls?: bool,
     *     signed_ttl?: int (clamped to 60..604800 seconds),
     *     client?: object (a ready client, for tests)
     */
    public function __construct(array $config)
    {
        $this->endpoint = $config['endpoint'] ?? '';
        $this->region = $config['region'] ?? 'us-east-1';
        $this->bucket = $config['bucket'] ?? '';
        $this->accessKey = $config['access_key'] ?? '';
        $this->secretKey = $config['secret_key'] ?? '';
        $this->pathStyle = (bool) ($config['path_style'] ?? false);
        $this->publicUrlBase = $config['public_url_base'] ?? '';
        $this->signedUrls = (bool) ($config['signed_urls'] ?? false);
        $this->signedTtl = max(60, min(604800, (int) ($config['signed_ttl'] ?? 900)));
        $this->client = isset($config['client']) && is_object($config['client']) ? $config['client'] : null;
    }

    /**
     * Adapter id stored in t_item_resource.s_storage.
     *
     * @return string
     */
    public function getId(): string
    {
        return 's3';
    }

    /**
     * Uploads the local file to $key, adding a long CacheControl when the bucket is public.
     *
     * @param string $localPath
     * @param string $key
     * @param string $contentType
     *
     * @return bool false when the file cannot be read or the upload failed
     */
    public function put(string $localPath, string $key, string $contentType): bool
    {
        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $handle,
                'ContentType' => $contentType,
            ];
            if ($this->isPublic()) {
                $params['CacheControl'] = 'public, max-age=31536000';
            }

            $this->client()->putObject($params)->resolve();

            return true;
        } catch (Throwable $e) {
            return false;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Downloads the object body.
     *
     * @param string $key
     *
     * @return string|false false on any transport, auth or not-found error
     */
    public function get(string $key): string|false
    {
        try {
            return $this->client()->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ])->getBody()->getContentAsString();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the object exists in the bucket.
     *
     * @param string $key
     *
     * @return bool false on any transport or auth error too
     */
    public function exists(string $key): bool
    {
        try {
            return $this->client()->objectExists([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ])->isSuccess();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Deletes the object; idempotent, since S3 returns 204 for a missing key.
     *
     * @param string $key
     *
     * @return bool false only on a transport or auth error
     */
    public function delete(string $key): bool
    {
        try {
            // A DeleteObject on a key that doesn't exist still returns 204,
            // so this is naturally idempotent; only a transport/auth error
            // makes it here as an exception.
            $this->client()->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ])->resolve();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Unsigned object URL: the configured public base if set, otherwise the endpoint in
     * path or virtual-host style.
     *
     * @param string $key
     *
     * @return string
     */
    public function url(string $key): string
    {
        if ($this->publicUrlBase !== '') {
            return rtrim($this->publicUrlBase, '/') . '/' . ltrim($key, '/');
        }

        $scheme = $this->endpointScheme();
        $host = $this->endpointHost();

        if ($this->pathStyle) {
            return $scheme . '://' . $host . '/' . $this->bucket . '/' . ltrim($key, '/');
        }

        return $scheme . '://' . $this->bucket . '.' . $host . '/' . ltrim($key, '/');
    }

    /**
     * Time-limited GET URL, valid for the configured signed TTL.
     *
     * @param string $key
     *
     * @return string '' when the URL could not be signed
     */
    public function presignedUrl(string $key): string
    {
        try {
            $input = new GetObjectRequest([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return $this->client()->presign($input, new DateTimeImmutable('+' . $this->signedTtl . ' seconds'));
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * A copy of this adapter for another bucket with the same endpoint and keys. The copy
     * treats its bucket as private: no public URL, signed URLs only.
     *
     * @param string $bucket
     *
     * @return static
     */
    public function withBucket(string $bucket): static
    {
        $copy = clone $this;
        $copy->bucket = $bucket;
        $copy->publicUrlBase = '';
        $copy->signedUrls = true;

        return $copy;
    }

    /**
     * Uploads a large file in parts that can resume in a later request, or in one PutObject
     * under one part. No ACL is sent, so the object is as private as its bucket.
     *
     * @param string                                  $localPath
     * @param string                                  $key
     * @param callable(int $done, int $total): bool $progress called after each part; false stops
     * @param array<string,mixed>                     $state    carried between calls; 'done' is
     *                                                           true once the object is in the bucket
     *                                                           and its size checked, 'error' says why
     *                                                           a call failed
     *
     * @return bool false when the upload failed; the multipart upload is then aborted
     */
    public function putLarge(string $localPath, string $key, callable $progress, array &$state): bool
    {
        $size = @filesize($localPath);
        if ($size === false) {
            $state['error'] = 'The file cannot be read.';

            return false;
        }
        $state += array('part_size' => self::PART_SIZE, 'upload_id' => '', 'parts' => array(), 'done' => false);
        $state['size'] = $size;
        $partSize = max((int) $state['part_size'], (int) ceil($size / self::MAX_PARTS));
        $state['part_size'] = $partSize;
        $type = substr($key, -5) === '.json' ? 'application/json' : 'application/zip';

        try {
            if ($size <= $partSize) {
                $handle = @fopen($localPath, 'rb');
                if ($handle === false) {
                    throw new \RuntimeException('The file cannot be read.');
                }
                try {
                    $this->client()->putObject(array(
                        'Bucket' => $this->bucket,
                        'Key' => $key,
                        'Body' => $handle,
                        'ContentLength' => $size,
                        'ContentType' => $type,
                    ))->resolve();
                } finally {
                    fclose($handle);
                }
                $progress($size, $size);

                return $this->verified($key, $size, $state);
            }

            if ($state['upload_id'] === '') {
                $state['upload_id'] = (string) $this->client()->createMultipartUpload(array(
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'ContentType' => $type,
                ))->getUploadId();
                $state['parts'] = array();
            }

            $count = (int) ceil($size / $partSize);
            for ($n = count($state['parts']) + 1; $n <= $count; $n++) {
                $offset = ($n - 1) * $partSize;
                $length = min($partSize, $size - $offset);
                $etag = $this->uploadPart($localPath, $key, (string) $state['upload_id'], $n, $offset, $length);
                $state['parts'][] = array('n' => $n, 'etag' => $etag);
                if ($n < $count && !$progress($offset + $length, $size)) {
                    return true;
                }
            }

            $parts = array();
            foreach ($state['parts'] as $part) {
                $parts[] = array('ETag' => $part['etag'], 'PartNumber' => (int) $part['n']);
            }
            $this->client()->completeMultipartUpload(array(
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $state['upload_id'],
                'MultipartUpload' => array('Parts' => $parts),
            ))->resolve();
            $progress($size, $size);

            return $this->verified($key, $size, $state);
        } catch (Throwable $e) {
            $state['error'] = self::cleanError($e);
            $this->abortLarge($key, $state);

            return false;
        }
    }

    /**
     * Drops an unfinished multipart upload, so its parts stop costing storage.
     *
     * @param string              $key
     * @param array<string,mixed> $state a putLarge() state
     *
     * @return void
     */
    public function abortLarge(string $key, array &$state): void
    {
        if (($state['upload_id'] ?? '') === '') {
            return;
        }
        try {
            $this->client()->abortMultipartUpload(array(
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $state['upload_id'],
            ))->resolve();
        } catch (Throwable $e) {
            // The bucket's own lifecycle rule is the fallback for a part left behind.
        }
        $state['upload_id'] = '';
        $state['parts'] = array();
    }

    /**
     * Downloads a large object to a local file in ranges, so it can stop and carry on in a
     * later request. The first call records the size and ETag; later ranges must match it.
     *
     * @param string                                  $key
     * @param string                                  $localPath written readable by its owner only
     * @param callable(int $done, int $total): bool $progress  called after each range; false stops
     * @param array<string,mixed>                     $state     carried between calls; 'done', 'error'
     *
     * @return bool false when the download failed
     */
    public function getLarge(string $key, string $localPath, callable $progress, array &$state): bool
    {
        $state += array('size' => -1, 'etag' => '', 'bytes_done' => 0, 'done' => false);
        try {
            if ($state['size'] < 0) {
                $head = $this->client()->headObject(array('Bucket' => $this->bucket, 'Key' => $key));
                $state['size'] = (int) $head->getContentLength();
                $state['etag'] = (string) $head->getEtag();
                $state['bytes_done'] = 0;
            }
            $umask = umask(0077);
            $out = @fopen($localPath, 'c');
            umask($umask);
            if ($out === false) {
                throw new \RuntimeException('The download cannot be written.');
            }
            try {
                ftruncate($out, (int) $state['bytes_done']);
                fseek($out, (int) $state['bytes_done']);
                while ($state['bytes_done'] < $state['size']) {
                    $end = min($state['size'], $state['bytes_done'] + self::PART_SIZE) - 1;
                    $body = $this->client()->getObject(array(
                        'Bucket' => $this->bucket,
                        'Key' => $key,
                        'Range' => 'bytes=' . $state['bytes_done'] . '-' . $end,
                        'IfMatch' => $state['etag'] !== '' ? $state['etag'] : null,
                    ))->getBody();
                    $written = 0;
                    foreach ($body->getChunks() as $chunk) {
                        if (fwrite($out, $chunk) !== strlen($chunk)) {
                            throw new \RuntimeException('The download cannot be written.');
                        }
                        $written += strlen($chunk);
                    }
                    if ($written !== $end - $state['bytes_done'] + 1) {
                        throw new \RuntimeException('The download stopped early.');
                    }
                    fflush($out);
                    $state['bytes_done'] = $end + 1;
                    if ($state['bytes_done'] < $state['size'] && !$progress($state['bytes_done'], $state['size'])) {
                        return true;
                    }
                }
            } finally {
                fclose($out);
            }
            @chmod($localPath, 0600);
            clearstatcache(true, $localPath);
            if ((int) filesize($localPath) !== $state['size']) {
                throw new \RuntimeException('The downloaded file has the wrong size.');
            }
            $state['done'] = true;
            $progress($state['size'], $state['size']);

            return true;
        } catch (Throwable $e) {
            $state['error'] = self::cleanError($e);

            return false;
        }
    }

    /**
     * The objects under a prefix, every page of them.
     *
     * @param string $prefix
     *
     * @return array<int,array{key:string,size:int,modified:int}>|false false when the bucket cannot be read
     */
    public function list(string $prefix): array|false
    {
        try {
            $rows = array();
            $result = $this->client()->listObjectsV2(array('Bucket' => $this->bucket, 'Prefix' => $prefix));
            foreach ($result->getContents() as $object) {
                $modified = $object->getLastModified();
                $rows[] = array(
                    'key' => (string) $object->getKey(),
                    'size' => (int) $object->getSize(),
                    'modified' => $modified !== null ? $modified->getTimestamp() : 0,
                );
            }

            return $rows;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Deletes objects in batches of 1,000; a key that is not there counts as deleted.
     *
     * @param string[] $keys
     *
     * @return bool false when any key could not be deleted
     */
    public function deleteMany(array $keys): bool
    {
        $ok = true;
        foreach (array_chunk(array_values($keys), 1000) as $batch) {
            try {
                $objects = array();
                foreach ($batch as $key) {
                    $objects[] = array('Key' => (string) $key);
                }
                $result = $this->client()->deleteObjects(array(
                    'Bucket' => $this->bucket,
                    'Delete' => array('Objects' => $objects, 'Quiet' => true),
                ));
                if (count($result->getErrors()) > 0) {
                    $ok = false;
                }
            } catch (Throwable $e) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * A short-lived link that downloads the object under a file name. It is a secret for
     * as long as it lives, so it is for a redirect, never for a page.
     *
     * @param string $key
     * @param int    $ttl      seconds, 60 to 3600
     * @param string $filename the name the browser saves it under
     *
     * @return string '' when the URL could not be signed
     */
    public function downloadUrl(string $key, int $ttl, string $filename): string
    {
        try {
            $input = new GetObjectRequest(array(
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ResponseContentDisposition' => 'attachment; filename="' . str_replace(array('"', '\\', "\r", "\n"), '', $filename) . '"',
            ));

            return $this->client()->presign($input, new DateTimeImmutable('+' . max(60, min(3600, $ttl)) . ' seconds'));
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Uploads one part from a copy of its bytes and returns its ETag.
     *
     * @param string $localPath
     * @param string $key
     * @param string $uploadId
     * @param int    $n
     * @param int    $offset
     * @param int    $length
     *
     * @return string
     */
    private function uploadPart(string $localPath, string $key, string $uploadId, int $n, int $offset, int $length): string
    {
        $partPath = $localPath . '.upart';
        $src = @fopen($localPath, 'rb');
        $umask = umask(0077);
        $dst = @fopen($partPath, 'w+b');
        umask($umask);
        try {
            if ($src === false || $dst === false || stream_copy_to_stream($src, $dst, $length, $offset) !== $length) {
                throw new \RuntimeException('The file cannot be read.');
            }
            rewind($dst);
            $etag = (string) $this->client()->uploadPart(array(
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $n,
                'Body' => $dst,
                'ContentLength' => $length,
            ))->getEtag();
            if ($etag === '') {
                throw new \RuntimeException('The bucket did not confirm a part.');
            }

            return $etag;
        } finally {
            if (is_resource($src)) {
                fclose($src);
            }
            if (is_resource($dst)) {
                fclose($dst);
            }
            @unlink($partPath);
        }
    }

    /**
     * Whether the object now in the bucket has the local file's size; sets 'done' when it does.
     *
     * @param string              $key
     * @param int                 $size
     * @param array<string,mixed> $state
     *
     * @return bool
     */
    private function verified(string $key, int $size, array &$state): bool
    {
        $stored = (int) $this->client()->headObject(array('Bucket' => $this->bucket, 'Key' => $key))->getContentLength();
        if ($stored !== $size) {
            $state['error'] = 'The bucket holds a different size than was sent.';

            return false;
        }
        $state['done'] = true;
        $state['upload_id'] = '';
        $state['parts'] = array();

        return true;
    }

    /**
     * An error fit for a log or a page: the first line, no query strings, 200 characters.
     *
     * @param Throwable $e
     *
     * @return string
     */
    private static function cleanError(Throwable $e): string
    {
        $line = trim((string) strtok($e->getMessage(), "\r\n"));
        $line = (string) preg_replace('#(https?://[^\s?]+)\?\S*#', '$1', $line);

        return mb_substr($line, 0, 200);
    }

    /**
     * Always true: objects live in the bucket, not on this filesystem.
     *
     * @return bool
     */
    public function isRemote(): bool
    {
        return true;
    }

    /**
     * False when the adapter is configured to hand out signed URLs.
     *
     * @return bool
     */
    public function isPublic(): bool
    {
        return !$this->signedUrls;
    }

    /**
     * Lazily builds the shared S3 client from the constructor config.
     *
     * @return S3Client
     */
    private function client(): object
    {
        if ($this->client === null) {
            $this->client = new S3Client([
                'endpoint' => $this->endpointScheme() . '://' . $this->endpointHost(),
                'region' => $this->region,
                'accessKeyId' => $this->accessKey,
                'accessKeySecret' => $this->secretKey,
                'pathStyleEndpoint' => $this->pathStyle,
            ]);
        }

        return $this->client;
    }

    /**
     * Scheme of the configured endpoint, defaulting to https when it carries none.
     *
     * @return string
     */
    private function endpointScheme(): string
    {
        if (preg_match('#^(https?)://#i', $this->endpoint, $matches)) {
            return strtolower($matches[1]);
        }

        return 'https';
    }

    /**
     * Configured endpoint with the scheme and any trailing slash stripped.
     *
     * @return string
     */
    private function endpointHost(): string
    {
        return rtrim(preg_replace('#^https?://#i', '', $this->endpoint), '/');
    }
}
