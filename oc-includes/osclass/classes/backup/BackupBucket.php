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
 *
 * Each site keeps its backups in its own folder, backups/<site>/, named from the site
 * address in config.php. Nothing in the database names it, so a restored copy of another
 * site's database cannot point this site at that site's backups.
 */
final class BackupBucket
{
    /** The folder the per-site folders sit in. */
    public const PREFIX = 'backups/';

    /** The longest per-site folder name. */
    public const SITE_MAX = 60;

    /** Seconds the Backup page waits for the bucket listing. */
    public const PAGE_TIMEOUT = 5.0;

    /** Seconds the page's copy of the bucket listing is used. */
    public const LIST_TTL = 60;

    /** Seconds the unsigned check for a public backup waits. */
    public const PUBLIC_TIMEOUT = 3.0;

    /** The preference that holds the label of a bucket found readable by anyone. */
    public const PUBLIC_FLAG = 'backup_bucket_public';

    /** How long a download link lives, in seconds. */
    public const LINK_TTL = 900;

    /** Optional adapter methods a bucket needs; the StorageAdapter interface has none of them. */
    public const METHODS = array('putLarge', 'abortLarge', 'getLarge', 'list', 'deleteMany', 'downloadUrl');

    /** @var object|null|false false: resolve from the settings */
    private static $adapter = false;

    /** @var string|null a site address in place of WEB_PATH, for tests */
    private static $base;

    /** @var string why the last bucket listing failed: '', 'timeout' or 'error' */
    private static $failure = '';

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
     * Use this site address instead of WEB_PATH, for tests. Null puts WEB_PATH back.
     *
     * @param string|null $base
     *
     * @return void
     */
    public static function useBase(?string $base): void
    {
        self::$base = $base;
    }

    /**
     * The per-site folder name for a site address: host, port and path in a-z 0-9 . and -
     * for people to read, then a hash of the exact address so two sites never share one.
     *
     * @param string $baseUrl
     *
     * @return string
     */
    public static function siteFolder(string $baseUrl): string
    {
        $url   = trim($baseUrl);
        $url   = preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'http://' . $url;
        $part  = parse_url($url);
        $part  = is_array($part) ? $part : array();
        $path  = (string) preg_replace('#(?<=/)\.+(?=/|$)#', '', '/' . ($part['path'] ?? ''));
        $path  = rtrim((string) preg_replace('#/+#', '/', $path), '/') . '/';
        $exact = strtolower($part['host'] ?? '') . (isset($part['port']) ? ':' . $part['port'] : '') . $path;
        $name  = (string) preg_replace('/[^a-z0-9.]+/', '-', strtolower($exact));
        $name  = trim((string) preg_replace('/\.{2,}/', '.', $name), '.-');
        $name  = rtrim(substr($name !== '' ? $name : 'site', 0, self::SITE_MAX - 9), '.-');

        return $name . '-' . substr(sha1($exact), 0, 8);
    }

    /**
     * Whether WEB_PATH is set in config.php or the environment. One taken from the
     * request's Host header is not, as a visitor could pick another site's folder, and
     * neither is OSC_CLI_URL, which the web side never sees.
     *
     * @return bool
     */
    public static function addressKnown(): bool
    {
        if (self::$base !== null) {
            return self::$base !== '';
        }

        return defined('WEB_PATH') && (string) WEB_PATH !== ''
            && !(defined('OSC_WEB_PATH_FROM_REQUEST') && OSC_WEB_PATH_FROM_REQUEST)
            && !(defined('OSC_WEB_PATH_FROM_CLI_URL') && OSC_WEB_PATH_FROM_CLI_URL);
    }

    /**
     * Why the bucket cannot be used though storage offers one: the site address is not
     * set. '' when that is not the problem.
     *
     * @return string
     */
    public static function addressProblem(): string
    {
        if (self::addressKnown()) {
            return '';
        }
        $remote = self::$adapter !== false ? self::$adapter : self::remote();

        return $remote !== null && self::supports($remote) ? self::addressMessage() : '';
    }

    /**
     * @return string
     */
    public static function addressMessage(): string
    {
        return __('Set WEB_PATH in config.php or the environment to use the bucket.');
    }

    /**
     * This site's folder in the bucket, with a trailing slash.
     *
     * @return string
     */
    public static function prefix(): string
    {
        $base = self::$base ?? (defined('WEB_PATH') ? (string) WEB_PATH : '');

        return self::PREFIX . self::siteFolder($base) . '/';
    }

    /**
     * Record why the last bucket listing failed, '' when it did not.
     *
     * @param string $failure
     *
     * @return void
     */
    public static function listed(string $failure): void
    {
        self::$failure = $failure;
    }

    /**
     * Why the last bucket listing failed: '', 'timeout' or 'error'.
     *
     * @return string
     */
    public static function listFailure(): string
    {
        return self::$failure;
    }

    /**
     * Whether the bucket is reached over plain http on a public address, where a backup
     * and its download link would travel unencrypted.
     *
     * @return bool
     */
    public static function insecure(): bool
    {
        $adapter = self::adapter();

        return $adapter !== null && method_exists($adapter, 'plainHttp') && $adapter->plainHttp();
    }

    /**
     * After an upload, ask for the object without signing. Remember the bucket as open
     * when it answers, and forget it when it does not.
     *
     * @param object $bucket
     * @param string $key
     *
     * @return bool whether anyone can read it
     */
    public static function checkPublic(object $bucket, string $key): bool
    {
        if (!method_exists($bucket, 'publiclyReadable')) {
            return false;
        }
        $open = (bool) $bucket->publiclyReadable($key, self::PUBLIC_TIMEOUT);
        osc_set_preference(self::PUBLIC_FLAG, $open ? self::label() : '');

        return $open;
    }

    /**
     * Whether the last upload to this bucket could be read without signing.
     *
     * @return bool
     */
    public static function flaggedPublic(): bool
    {
        $flag = (string) osc_get_preference(self::PUBLIC_FLAG);

        return $flag !== '' && $flag === self::label();
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
     * The adapter backups go to, or null when saving to a bucket is not on offer or the
     * site address is not set.
     *
     * @return object|null
     */
    public static function adapter(): ?object
    {
        if (!self::addressKnown()) {
            return null;
        }
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

        return ($bucket !== '' ? $bucket . '/' : '') . self::prefix();
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
        return self::prefix() . $name;
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
        return self::prefix() . BackupStore::sidecar($name);
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

        return StorageManager::getInstance()->remote();
    }
}
