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

/**
 * Class ProviderPresets
 *
 * Connection-field presets for the S3-compatible providers the admin storage
 * settings page offers in its provider dropdown. Selecting a preset prefills
 * the endpoint/region/path-style fields with placeholders the admin still
 * has to fill in (e.g. {account_id}); nothing here is a working connection
 * on its own.
 *
 * @package mindstellar\storage
 */
class ProviderPresets
{
    public const PRESETS = [
        'aws' => [
            'label' => 'Amazon S3',
            'endpoint' => 'https://s3.{region}.amazonaws.com',
            'region' => 'us-east-1',
            'region_locked' => false,
            'path_style' => false,
            'public_url_hint' => 'https://{bucket}.s3.{region}.amazonaws.com or a CloudFront domain',
            'docs' => 'https://docs.aws.amazon.com/AmazonS3/latest/userguide/Welcome.html',
        ],
        'r2' => [
            'label' => 'Cloudflare R2',
            'endpoint' => 'https://{account_id}.r2.cloudflarestorage.com',
            'region' => 'auto',
            'region_locked' => true,
            'path_style' => true,
            'public_url_hint' => 'https://pub-<hash>.r2.dev or your custom domain',
            'docs' => 'https://developers.cloudflare.com/r2/',
        ],
        'spaces' => [
            'label' => 'DigitalOcean Spaces',
            'endpoint' => 'https://{region}.digitaloceanspaces.com',
            'region' => 'nyc3',
            'region_locked' => false,
            'path_style' => false,
            'public_url_hint' => 'https://{bucket}.{region}.digitaloceanspaces.com or a CDN endpoint',
            'docs' => 'https://docs.digitalocean.com/products/spaces/',
        ],
        'wasabi' => [
            'label' => 'Wasabi',
            'endpoint' => 'https://s3.{region}.wasabisys.com',
            'region' => 'us-east-1',
            'region_locked' => false,
            'path_style' => false,
            'public_url_hint' => 'https://{bucket}.s3.{region}.wasabisys.com',
            'docs' => 'https://docs.wasabi.com/',
        ],
        'b2' => [
            'label' => 'Backblaze B2',
            'endpoint' => 'https://s3.{region}.backblazeb2.com',
            'region' => 'us-west-002',
            'region_locked' => false,
            'path_style' => false,
            'public_url_hint' => 'use S3-compatible app keys',
            'docs' => 'https://www.backblaze.com/docs/cloud-storage-s3-compatible-api',
        ],
        'minio' => [
            'label' => 'MinIO / self-hosted',
            'endpoint' => '',
            'region' => 'us-east-1',
            'region_locked' => false,
            'path_style' => true,
            'public_url_hint' => 'your own public endpoint or reverse-proxy domain',
            'docs' => 'https://min.io/docs/minio/linux/index.html',
        ],
        'custom' => [
            'label' => 'Custom S3-compatible',
            'endpoint' => '',
            'region' => '',
            'region_locked' => false,
            'path_style' => false,
            'public_url_hint' => '',
            'docs' => '',
        ],
    ];

    /**
     * JSON-encoded presets, safe to embed inline inside a <script> block.
     *
     * @return string
     */
    public static function toJson(): string
    {
        return json_encode(
            self::PRESETS,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
