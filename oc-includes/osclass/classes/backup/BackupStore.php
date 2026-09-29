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

use mindstellar\utility\FileSystem;

/**
 * The backup folder on the server: its protection, the saved backups in it with their
 * manifests beside them, the state of the running backup or restore, and clean-up.
 *
 * The folder is inside the site, so it is closed three ways: an .htaccess, a web-server
 * rule, and file names nobody can guess. Files in it are readable by their owner only.
 */
final class BackupStore
{
    /** The one server folder, relative to the site. */
    public const FOLDER = 'oc-content/downloads/backups/';

    /** A saved backup: date, time, what, and 16 random characters. */
    public const NAME = '/^(\d{4}-\d{2}-\d{2}-\d{6})-(database|files|everything)-([a-z2-7]{16})\.zip$/';

    /** A file uploaded for a restore, kept until it runs or for an hour. */
    public const UPLOAD = '/^upload-[a-z2-7]{16}\.(zip|sql)$/';

    /** The file the web-reachability probe asks for. */
    public const PROBE = 'reachability-probe.txt';

    /** Downloads and uploads left behind are removed after this many seconds. */
    public const TTL = 3600;

    /** @var string with a trailing slash */
    private $dir;

    /**
     * @param string $dir the folder, with or without a trailing slash
     */
    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\') . '/';
    }

    /**
     * The store in this site's backup folder.
     *
     * @return self
     */
    public static function site(): self
    {
        return new self(ABS_PATH . self::FOLDER);
    }

    /**
     * @return string with a trailing slash
     */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Make the folder if needed and close it to the web: an .htaccess, an empty
     * index.php, and the probe file.
     *
     * @return bool whether the folder exists and can be written
     */
    public function protect(): bool
    {
        if (!is_dir($this->dir)) {
            $umask = umask(0027);
            @mkdir($this->dir, 0750, true);
            umask($umask);
        }
        if (!is_dir($this->dir) || !is_writable($this->dir)) {
            return false;
        }
        FileSystem::protectFolder($this->dir);
        if (!is_file($this->dir . self::PROBE)) {
            @file_put_contents($this->dir . self::PROBE, "closed\n");
        }

        return true;
    }

    /**
     * A new backup name.
     *
     * @param string $what database|files|everything
     *
     * @return string
     */
    public static function newName(string $what): string
    {
        return date('Y-m-d-His') . '-' . $what . '-' . self::random(16) . '.zip';
    }

    /**
     * A new name for an uploaded file.
     *
     * @param string $ext zip|sql
     *
     * @return string
     */
    public static function uploadName(string $ext): string
    {
        return 'upload-' . self::random(16) . '.' . $ext;
    }

    /**
     * $n random base32 characters.
     *
     * @param int $n
     *
     * @return string
     */
    public static function random(int $n): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $out      = '';
        foreach (str_split(random_bytes($n)) as $byte) {
            $out .= $alphabet[ord($byte) & 31];
        }

        return $out;
    }

    /**
     * The path of a saved backup or an upload, by name only. Null for anything that is
     * not one, including a name carrying a path.
     *
     * @param string $name
     *
     * @return string|null
     */
    public function path(string $name): ?string
    {
        if ($name !== basename($name) || (!preg_match(self::NAME, $name) && !preg_match(self::UPLOAD, $name))) {
            return null;
        }
        $path = $this->dir . $name;
        if (!is_file($path) || is_link($path)) {
            return null;
        }

        return $path;
    }

    /**
     * The saved backups with their manifests, newest first. Downloads waiting to be
     * fetched are not listed.
     *
     * @return array<int,array{name:string,size:int,created:string,what:string,kind:string,where:string,manifest:array<string,mixed>}>
     */
    public function all(): array
    {
        $rows = array();
        foreach (glob($this->dir . '*.zip') ?: array() as $file) {
            $name = basename($file);
            if (!preg_match(self::NAME, $name, $m) || is_link($file)) {
                continue;
            }
            $manifest = $this->manifest($name);
            if (!self::isSaved($manifest)) {
                continue;
            }
            $rows[] = array(
                'name'     => $name,
                'size'     => (int) filesize($file),
                'created'  => (string) $manifest['created'],
                'what'     => (string) $manifest['what'],
                'kind'     => (string) ($manifest['kind'] ?? 'backup'),
                'where'    => 'server',
                'manifest' => $manifest,
            );
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp($b['name'], $a['name']);
        });

        return $rows;
    }

    /**
     * The manifest saved beside a backup, or null.
     *
     * @param string $name
     *
     * @return array<string,mixed>|null
     */
    public function manifest(string $name): ?array
    {
        if (!preg_match(self::NAME, $name)) {
            return null;
        }
        $json = @file_get_contents($this->dir . substr($name, 0, -4) . '.json');

        return is_string($json) ? Manifest::parse($json) : null;
    }

    /**
     * Save the manifest beside a backup.
     *
     * @param string               $name
     * @param array<string,mixed> $manifest
     *
     * @return void
     */
    public function saveManifest(string $name, array $manifest): void
    {
        $this->writePrivate($this->dir . substr($name, 0, -4) . '.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Delete a saved backup and its manifest.
     *
     * @param string $name
     *
     * @return bool
     */
    public function delete(string $name): bool
    {
        $path = $this->path($name);
        if ($path === null || !preg_match(self::NAME, $name)) {
            return false;
        }
        @unlink($this->dir . substr($name, 0, -4) . '.json');

        return @unlink($path);
    }

    /**
     * Whether a manifest belongs to a finished backup or safety copy, as all() lists them.
     *
     * @param array<string,mixed>|null $manifest
     *
     * @return bool
     */
    private static function isSaved(?array $manifest): bool
    {
        return $manifest !== null && !in_array($manifest['kind'] ?? '', array('download', 'upload'), true);
    }

    /**
     * Remove every file a backup in progress left behind. A finished backup or safety
     * copy is never touched; delete() is the only way to remove one.
     *
     * @param string $name
     *
     * @return void
     */
    public function discard(string $name): void
    {
        if (!preg_match(self::NAME, $name) || self::isSaved($this->manifest($name))) {
            return;
        }
        foreach (array('.part', '.part.cdir', '.sql', '.upart') as $suffix) {
            @unlink($this->dir . $name . $suffix);
        }
        @unlink($this->dir . substr($name, 0, -4) . '.json');
        @unlink($this->dir . $name);
    }

    /**
     * Keep the newest $keep backups of one kind and delete the rest.
     *
     * @param string $kind backup|safety
     * @param int    $keep
     *
     * @return int how many were deleted
     */
    public function prune(string $kind, int $keep): int
    {
        $deleted = 0;
        foreach (self::pruneNames($this->all(), $kind, $keep) as $name) {
            if ($this->delete($name)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * The backups of one kind past the newest $keep, from rows listed newest first.
     *
     * @param array<int,array{name:string,kind:string}> $rows
     * @param string                                    $kind
     * @param int                                       $keep at least 1
     *
     * @return string[]
     */
    public static function pruneNames(array $rows, string $kind, int $keep): array
    {
        $names = array();
        $seen  = 0;
        foreach ($rows as $row) {
            if ($row['kind'] === $kind && ++$seen > max(1, $keep)) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }

    /**
     * The backups in this site's folder of the bucket, newest first: a backup counts only
     * with its manifest beside it. Null when the bucket cannot be read.
     *
     * The Backup page lists with a short timeout and keeps the result for LIST_TTL
     * seconds; $fresh asks the bucket now, with the adapter's own timeout.
     *
     * @param object $bucket a BackupBucket adapter
     * @param bool   $fresh
     *
     * @return array<int,array{name:string,size:int,created:string,what:string,kind:string,where:string,manifest:null}>|null
     */
    public function bucketAll(object $bucket, bool $fresh = false): ?array
    {
        $prefix = BackupBucket::prefix();
        $cache  = $this->dir . '.bucket-list.json';
        $id     = BackupBucket::label() . '|' . $prefix;
        if (!$fresh) {
            $json = @file_get_contents($cache);
            $held = is_string($json) ? json_decode($json, true) : null;
            if (is_array($held) && ($held['id'] ?? '') === $id && (int) ($held['at'] ?? 0) > time() - BackupBucket::LIST_TTL) {
                BackupBucket::listed((string) ($held['failure'] ?? ''));

                return is_array($held['rows'] ?? null) ? $held['rows'] : null;
            }
        }
        $lister  = !$fresh && method_exists($bucket, 'withTimeout') ? $bucket->withTimeout(BackupBucket::PAGE_TIMEOUT) : $bucket;
        $objects = $lister->list($prefix);
        $rows    = is_array($objects) ? self::bucketRows($objects, $prefix) : null;
        $failure = '';
        if ($rows === null) {
            $failure = method_exists($lister, 'timedOut') && $lister->timedOut() ? 'timeout' : 'error';
        }
        BackupBucket::listed($failure);
        if (!$fresh && is_dir($this->dir)) {
            $this->writePrivate($cache, (string) json_encode(array('id' => $id, 'at' => time(), 'rows' => $rows, 'failure' => $failure)));
        }

        return $rows;
    }

    /**
     * Forget the Backup page's copy of the bucket listing.
     *
     * @return void
     */
    public function forgetBucketList(): void
    {
        @unlink($this->dir . '.bucket-list.json');
    }

    /**
     * The backup rows in one listing of a site folder.
     *
     * @param array<int,array{key:string,size:int}> $objects
     * @param string                                $prefix
     *
     * @return array<int,array{name:string,size:int,created:string,what:string,kind:string,where:string,manifest:null}>
     */
    private static function bucketRows(array $objects, string $prefix): array
    {
        $keys = array();
        foreach ($objects as $object) {
            $keys[(string) $object['key']] = (int) $object['size'];
        }
        $rows = array();
        foreach ($keys as $key => $size) {
            $name = substr($key, strlen($prefix));
            if (strpos($key, $prefix) !== 0 || !preg_match(self::NAME, $name, $m)
                || !isset($keys[BackupBucket::sidecarKey($name)])
            ) {
                continue;
            }
            $ts     = \DateTime::createFromFormat('Y-m-d-His', $m[1]);
            $rows[] = array(
                'name'     => $name,
                'size'     => $size,
                'created'  => $ts !== false ? $ts->format('c') : '',
                'what'     => $m[2],
                'kind'     => 'backup',
                'where'    => 'bucket',
                'manifest' => null,
            );
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp($b['name'], $a['name']);
        });

        return $rows;
    }

    /**
     * Delete a backup and its manifest from the bucket.
     *
     * @param object $bucket
     * @param string $name
     *
     * @return bool
     */
    public function bucketDelete(object $bucket, string $name): bool
    {
        if ($name !== basename($name) || !preg_match(self::NAME, $name)) {
            return false;
        }
        $this->forgetBucketList();

        return (bool) $bucket->deleteMany(array(BackupBucket::key($name), BackupBucket::sidecarKey($name)));
    }

    /**
     * Keep the newest $keep backups in the bucket and delete the rest.
     *
     * @param object $bucket
     * @param int    $keep
     *
     * @return int how many were deleted; 0 when the bucket cannot be read
     */
    public function bucketPrune(object $bucket, int $keep): int
    {
        $rows = $this->bucketAll($bucket, true);
        if ($rows === null) {
            return 0;
        }
        $this->forgetBucketList();
        $keys  = array();
        $names = self::pruneNames($rows, 'backup', $keep);
        foreach ($names as $name) {
            $keys[] = BackupBucket::key($name);
            $keys[] = BackupBucket::sidecarKey($name);
        }
        if ($keys === array() || !$bucket->deleteMany($keys)) {
            return 0;
        }

        return count($names);
    }

    /**
     * A short-lived download link for a backup in the bucket, for a redirect only; '' when
     * the name is not a backup that is there.
     *
     * @param object $bucket
     * @param string $name
     *
     * @return string
     */
    public function bucketLink(object $bucket, string $name): string
    {
        if ($name !== basename($name) || !preg_match(self::NAME, $name) || !$bucket->exists(BackupBucket::key($name))) {
            return '';
        }

        return (string) $bucket->downloadUrl(BackupBucket::key($name), BackupBucket::LINK_TTL, $name);
    }

    /**
     * Remove downloads and uploads older than an hour, and the leftovers of a backup that
     * is no longer running.
     *
     * @param bool   $running whether a backup or restore job is waiting or running
     * @param string $keep    a name to leave alone
     *
     * @return void
     */
    public function sweep(bool $running, string $keep = ''): void
    {
        $old = time() - self::TTL;
        foreach (glob($this->dir . '*') ?: array() as $file) {
            $name = basename($file);
            if ($name === $keep || is_link($file) || !is_file($file)) {
                continue;
            }
            if (preg_match(self::UPLOAD, $name)) {
                if (!$running && filemtime($file) < $old) {
                    @unlink($file);
                }
                continue;
            }
            if (preg_match(self::NAME, $name)) {
                $kind = (string) ($this->manifest($name)['kind'] ?? '');
                if (($kind === 'download' || ($kind === 'upload' && !$running)) && filemtime($file) < $old) {
                    $this->delete($name);
                }
                continue;
            }
            if (!$running && preg_match('/^\d{4}-\d{2}-\d{2}-\d{6}-[a-z]+-[a-z2-7]{16}\.zip\.(part|part\.cdir|sql|upart)$/', $name)) {
                @unlink($file);
            }
        }
    }

    /**
     * Free bytes on the folder's disk, or null when the server does not say.
     *
     * @return int|null
     */
    public function freeSpace(): ?int
    {
        $free = @disk_free_space(is_dir($this->dir) ? $this->dir : dirname($this->dir));

        return $free === false ? null : (int) $free;
    }

    /**
     * The state of the running or last backup or restore.
     *
     * @return array<string,mixed>
     */
    public function state(): array
    {
        $json  = @file_get_contents($this->dir . '.state.json');
        $state = is_string($json) ? json_decode($json, true) : null;

        return is_array($state) ? $state : array();
    }

    /**
     * Save the state, stamped with the time.
     *
     * @param array<string,mixed> $state
     *
     * @return void
     */
    public function saveState(array $state): void
    {
        $state['updated'] = time();
        $this->writePrivate($this->dir . '.state.json', (string) json_encode($state));
    }

    /**
     * Forget the state.
     *
     * @return void
     */
    public function clearState(): void
    {
        @unlink($this->dir . '.state.json');
        @unlink($this->dir . '.cancel');
    }

    /**
     * Ask the running backup to stop at its next batch.
     *
     * @param string $run
     *
     * @return void
     */
    public function requestCancel(string $run): void
    {
        $this->writePrivate($this->dir . '.cancel', $run);
    }

    /**
     * Whether the run was asked to stop.
     *
     * @param string $run
     *
     * @return bool
     */
    public function cancelRequested(string $run): bool
    {
        clearstatcache(true, $this->dir . '.cancel');

        return $run !== '' && @file_get_contents($this->dir . '.cancel') === $run;
    }

    /**
     * Write a file readable by its owner only, replacing it whole.
     *
     * @param string $path
     * @param string $content
     *
     * @return void
     */
    public function writePrivate(string $path, string $content): void
    {
        $tmp   = $path . '.' . self::random(6) . '.tmp';
        $umask = umask(0077);
        $ok    = @file_put_contents($tmp, $content) !== false;
        umask($umask);
        if ($ok) {
            @chmod($tmp, 0600);
            @rename($tmp, $path);
        }
        @unlink($tmp);
    }

    /**
     * Check a folder given for backups by path, as the command line may. It must exist,
     * be writable, and be this site's backup folder or sit outside the site.
     *
     * @param string $dir
     * @param string $webRoot
     *
     * @return array{dir:string,error:string} the real path with a trailing separator, or why it is refused
     */
    public static function checkFolder(string $dir, string $webRoot): array
    {
        $dir = trim($dir);
        if ($dir === '') {
            return array('dir' => '', 'error' => __('Enter a server folder for the backup.'));
        }
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            return array('dir' => '', 'error' => __('The backup folder does not exist.'));
        }
        $root  = realpath($webRoot);
        $fixed = $root === false ? false : realpath($root . '/' . self::FOLDER);
        if ($root === false || ($real !== $fixed && self::isInside($real, $root))) {
            return array(
                'dir'   => '',
                'error' => __('This folder is inside the site, so anyone could download the backup. Pick a folder outside it.'),
            );
        }
        if (!is_writable($real)) {
            return array('dir' => '', 'error' => __('The backup folder is not writable.'));
        }

        return array('dir' => rtrim($real, '/\\') . DIRECTORY_SEPARATOR, 'error' => '');
    }

    /**
     * Whether a real path is the folder $root or anything below it.
     *
     * @param string $path
     * @param string $root
     *
     * @return bool
     */
    private static function isInside(string $path, string $root): bool
    {
        $root = rtrim($root, '/\\');

        return $root === '' || $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
    }
}
