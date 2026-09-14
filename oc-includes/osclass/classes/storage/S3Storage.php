<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
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
    private string $endpoint;

    private string $region;

    private string $bucket;

    private string $accessKey;

    private string $secretKey;

    private bool $pathStyle;

    private string $publicUrlBase;

    private bool $signedUrls;

    private int $signedTtl;

    private ?S3Client $client = null;

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
     *     signed_ttl?: int (clamped to 60..604800 seconds)
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
    private function client(): S3Client
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
