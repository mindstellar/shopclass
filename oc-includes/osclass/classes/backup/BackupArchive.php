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

use mindstellar\utility\Zip;
use RuntimeException;
use ZipArchive;

/**
 * Reads a backup zip: its manifest, its database, and its files, which it puts back
 * into oc-content a batch at a time.
 *
 * Only entries under files/oc-content/ are ever written, never into a folder a backup
 * leaves out, never through a link that leaves oc-content, and never over a link.
 * Nothing on disk is deleted.
 */
final class BackupArchive
{
    public const FILES = 'files/oc-content/';

    /** Most entries a backup may hold. */
    public const MAX_ENTRIES = 2000000;

    /** Largest single entry, uncompressed (64 GiB). */
    public const MAX_ENTRY_BYTES = 68719476736;

    /** Largest backup, uncompressed (1 TiB); free space is the real limit. */
    public const MAX_TOTAL_BYTES = 1099511627776;

    /** Deflate cannot pass about 1,030 to 1, so anything above this is not a real backup. */
    public const MAX_RATIO = 1100;

    /** @var ZipArchive */
    private $zip;

    /**
     * @param string $path
     *
     * @throws RuntimeException when it is not a zip that can be read
     */
    public function __construct(string $path)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('This file is not a backup that can be read');
        }
    }

    /**
     * The manifest inside, or null when there is none.
     *
     * @return array<string,mixed>|null
     */
    public function manifest(): ?array
    {
        $json = $this->zip->getFromName('manifest.json');

        return is_string($json) ? Manifest::parse($json) : null;
    }

    /**
     * Whether it holds a database.
     *
     * @return bool
     */
    public function hasDatabase(): bool
    {
        return $this->zip->locateName('database.sql') !== false;
    }

    /**
     * The database as a stream.
     *
     * @return resource
     * @throws RuntimeException when there is none
     */
    public function database()
    {
        $stream = $this->zip->getStream('database.sql');
        if ($stream === false) {
            throw new RuntimeException('The backup holds no database');
        }

        return $stream;
    }

    /**
     * How many file entries it holds.
     *
     * @return int
     */
    public function fileCount(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            if (strpos((string) $this->zip->getNameIndex($i), self::FILES) === 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Total entries, for walking them by index.
     *
     * @return int
     */
    public function entries(): int
    {
        return $this->zip->numFiles;
    }

    /**
     * The real sizes of what it holds, from the zip's own records: the database, the
     * files, and the bytes putting the files back adds to oc-content. 'safe' is false
     * when there are too many entries or one is too large or too compressed to be real.
     *
     * @param string|null $content real path of oc-content, to take off the files an entry replaces
     *
     * @return array{safe:bool,database:int,files:int,need:int}
     */
    public function measure(?string $content = null): array
    {
        $out = array('safe' => false, 'database' => 0, 'files' => 0, 'need' => 0);
        $n   = $this->zip->numFiles;
        if ($n > self::MAX_ENTRIES) {
            return $out;
        }
        $total   = 0;
        $largest = 0;
        for ($i = 0; $i < $n; $i++) {
            $stat = $this->zip->statIndex($i);
            if ($stat === false) {
                return $out;
            }
            $size = (int) $stat['size'];
            if (!Zip::entryWithinLimits($size, (int) $stat['comp_size'], $total, self::MAX_ENTRY_BYTES, self::MAX_TOTAL_BYTES, self::MAX_RATIO)) {
                return $out;
            }
            $total += $size;
            $name   = (string) $stat['name'];
            if ($name === 'database.sql') {
                $out['database'] = $size;
            } elseif (strpos($name, self::FILES) === 0 && substr($name, -1) !== '/') {
                $out['files'] += $size;
                $largest       = max($largest, $size);
                $old           = $content !== null ? @filesize(rtrim($content, '/') . '/' . substr($name, strlen(self::FILES))) : false;
                $out['need']  += max(0, $size - (is_int($old) ? $old : 0));
            }
        }
        // Each file is written beside the one it replaces before it takes its place.
        $out['need'] += $largest;
        $out['safe']  = true;

        return $out;
    }

    /**
     * Put back files from entry $from on, until $max entries are done or $deadline passes.
     *
     * @param string $content  real path of oc-content
     * @param int    $from     the entry index to start at
     * @param int    $max
     * @param float  $deadline microtime(true) to stop at
     *
     * @return array{next:int,done:bool,written:int,refused:int}
     */
    public function extract(string $content, int $from, int $max, float $deadline): array
    {
        $written = 0;
        $refused = 0;
        $i       = max(0, $from);
        $n       = $this->zip->numFiles;
        for ($count = 0; $i < $n && $count < $max; $i++, $count++) {
            if ($count > 0 && microtime(true) > $deadline) {
                break;
            }
            $stat = $this->zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if ($name === 'manifest.json' || $name === 'database.sql' || substr($name, -1) === '/') {
                continue;
            }
            $target = self::target($name, $content);
            if ($target === null || Zip::isZipArchiveEntrySymlink($this->zip, $i)) {
                $refused++;
                continue;
            }
            $this->write($i, $target, (int) ($stat['size'] ?? 0));
            $written++;
        }

        return array('next' => $i, 'done' => $i >= $n, 'written' => $written, 'refused' => $refused);
    }

    /**
     * Where an entry is written, or null when it must not be: anything not under
     * files/oc-content/, a `..` segment, an absolute or drive path, a folder a backup
     * leaves out (the backups folder above all), a path through a link leaving
     * oc-content, or a path that ends on a link or a folder.
     *
     * @param string $name    the entry name
     * @param string $content real path of oc-content
     *
     * @return string|null
     */
    public static function target(string $name, string $content): ?string
    {
        $name = str_replace('\\', '/', $name);
        if (strpos($name, self::FILES) !== 0 || preg_match('#(^|/)\.\.(/|$)#', $name)) {
            return null;
        }
        $rel = substr($name, strlen(self::FILES));
        if ($rel === '' || substr($rel, -1) === '/') {
            return null;
        }
        $content = rtrim($content, '/');
        $target  = Zip::resolveEntryTarget($rel, $content);
        if ($target === false) {
            return null;
        }

        // Follow the path as the disk will, so a link cannot lead into oc-content's own
        // left-out folders or out of it.
        $path = $content;
        $real = $content;
        foreach (explode('/', substr($target, strlen($content) + 1)) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                $real = realpath($path);
                if ($path === $target || $real === false || !FileWalker::within($real, $content)) {
                    return null;
                }
            } elseif (file_exists($path)) {
                $real .= '/' . $segment;
            } else {
                $real .= substr($target, strlen($path) - strlen($segment) - 1);
                break;
            }
        }
        if (FileWalker::isExcluded(substr($real, strlen($content) + 1), false)) {
            return null;
        }

        return is_dir($target) ? null : $target;
    }

    /**
     * @return void
     */
    public function close(): void
    {
        $this->zip->close();
    }

    /**
     * Stream one entry to its target, keeping the mode of a file it replaces. It stops
     * at the size the zip records, so an entry cannot write more than it declared.
     *
     * @param int    $index
     * @param string $target
     * @param int    $size the entry's recorded size
     *
     * @return void
     */
    private function write(int $index, string $target, int $size): void
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not make a folder in oc-content');
        }
        $in = $this->zip->getStream((string) $this->zip->getNameIndex($index));
        if ($in === false) {
            throw new RuntimeException('Could not read a file in the backup');
        }
        $mode = is_file($target) ? (fileperms($target) & 0777) : null;
        $tmp  = $dir . '/.' . basename($target) . '.' . BackupStore::random(6) . '.restore';
        $out  = @fopen($tmp, 'xb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Could not write in oc-content');
        }
        $copied = stream_copy_to_stream($in, $out, $size + 1);
        $ok     = $copied !== false && $copied <= $size;
        fclose($in);
        $ok = fclose($out) && $ok;
        if (!$ok || !@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Could not write in oc-content. The disk may be full.');
        }
        if ($mode !== null) {
            @chmod($target, $mode);
        }
    }
}
