<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\backup;

use mindstellar\storage\StorageManager;

/**
 * The S3 bucket backups can be saved to: the separate backups bucket when one is set,
 * else the photo bucket under backups/. Offered only when photo offload is on and the
 * storage adapter has the optional methods below.
 */
final class BackupBucket
{
    /** Where backups live in the bucket. */
    public const PREFIX = 'backups/';

    /** How long a download link lives, in seconds. */
    public const LINK_TTL = 900;

    /** Optional adapter methods a bucket needs; the StorageAdapter interface has none of them. */
    public const METHODS = array('putLarge', 'abortLarge', 'getLarge', 'list', 'deleteMany', 'downloadUrl');

    /** @var object|null|false false: resolve from the settings */
    private static $adapter = false;

    /**
     * Use this adapter instead of the one the settings name, for tests. False puts the
     * settings back.
     *
     * @param object|null|false $adapter
     *
     * @return void
     */
    public static function use($adapter): void
    {
        self::$adapter = $adapter;
    }

    /**
     * Whether an adapter can hold backups.
     *
     * @param object|null $adapter
     *
     * @return bool
     */
    public static function supports($adapter): bool
    {
        if (!is_object($adapter)) {
            return false;
        }
        foreach (self::METHODS as $method) {
            if (!method_exists($adapter, $method)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The adapter backups go to, or null when saving to a bucket is not on offer.
     *
     * @return object|null
     */
    public static function adapter(): ?object
    {
        if (self::$adapter !== false) {
            return self::$adapter;
        }
        $remote = self::remote();
        if ($remote === null || !self::supports($remote)) {
            return null;
        }
        $bucket = self::separate();
        if ($bucket !== '' && method_exists($remote, 'withBucket')) {
            return $remote->withBucket($bucket);
        }

        return $remote;
    }

    /**
     * The bucket name as the page shows it, with the prefix.
     *
     * @return string
     */
    public static function label(): string
    {
        $bucket = self::shared() ? (string) osc_get_preference('storage_s3_bucket') : self::separate();

        return ($bucket !== '' ? $bucket . '/' : '') . self::PREFIX;
    }

    /**
     * Whether backups share the photo bucket.
     *
     * @return bool
     */
    public static function shared(): bool
    {
        $bucket = self::separate();
        $remote = self::remote();

        return $bucket === '' || $bucket === (string) osc_get_preference('storage_s3_bucket')
            || $remote === null || !method_exists($remote, 'withBucket');
    }

    /**
     * Whether backups would sit in a photo bucket the web can read: one behind a public
     * URL, or one serving photos without signed links.
     *
     * @return bool
     */
    public static function exposed(): bool
    {
        if (!self::shared()) {
            return false;
        }
        $remote = self::remote();

        return (string) osc_get_preference('storage_s3_public_url') !== '' || ($remote !== null && $remote->isPublic());
    }

    /**
     * @param string $name a backup name
     *
     * @return string
     */
    public static function key(string $name): string
    {
        return self::PREFIX . $name;
    }

    /**
     * The key of the manifest saved beside a backup.
     *
     * @param string $name
     *
     * @return string
     */
    public static function sidecarKey(string $name): string
    {
        return self::PREFIX . substr($name, 0, -4) . '.json';
    }

    /**
     * The separate backups bucket, '' when none is set.
     *
     * @return string
     */
    private static function separate(): string
    {
        return trim((string) osc_get_preference('storage_s3_backup_bucket'));
    }

    /**
     * The active remote storage adapter, registered first when the settings name one.
     *
     * @return \mindstellar\storage\StorageAdapter|null
     */
    private static function remote()
    {
        if (function_exists('osc_storage_register_remote')) {
            osc_storage_register_remote();
        }

        return StorageManager::instance()->remote();
    }
}
