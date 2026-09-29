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
 * Only entries under files/oc-content/ are ever written, never through a link that
 * leaves the site, and never over a link. Nothing on disk is deleted.
 */
final class BackupArchive
{
    public const FILES = 'files/oc-content/';

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
     * Put back files from entry $from on, until $max entries are done or $deadline passes.
     *
     * @param string $content  real path of oc-content
     * @param string $site     real path of the site
     * @param int    $from     the entry index to start at
     * @param int    $max
     * @param float  $deadline microtime(true) to stop at
     *
     * @return array{next:int,done:bool,written:int,refused:int}
     */
    public function extract(string $content, string $site, int $from, int $max, float $deadline): array
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
            $target = self::target($name, $content, $site);
            if ($target === null || $this->isLink($i)) {
                $refused++;
                continue;
            }
            $this->write($i, $target);
            $written++;
        }

        return array('next' => $i, 'done' => $i >= $n, 'written' => $written, 'refused' => $refused);
    }

    /**
     * Where an entry is written, or null when it must not be: anything not under
     * files/oc-content/, a `..` segment, an absolute or drive path, or a path that
     * passes through a link leaving the site or ends on a link or a folder.
     *
     * @param string $name    the entry name
     * @param string $content real path of oc-content
     * @param string $site    real path of the site
     *
     * @return string|null
     */
    public static function target(string $name, string $content, string $site): ?string
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
        $site    = rtrim($site, '/');
        $target  = Zip::resolveEntryTarget($rel, $content);
        if ($target === false) {
            return null;
        }

        $path = $content;
        foreach (explode('/', substr($target, strlen($content) + 1)) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                $real = realpath($path);
                if ($path === $target || $real === false || ($real !== $site && strpos($real, $site . '/') !== 0)) {
                    return null;
                }
            } elseif (!file_exists($path)) {
                break;
            }
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
     * Whether an entry is stored as a symlink.
     *
     * @param int $index
     *
     * @return bool
     */
    private function isLink(int $index): bool
    {
        if (!$this->zip->getExternalAttributesIndex($index, $opsys, $attr) || $opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return ((($attr >> 16) & 0xF000) === 0xA000);
    }

    /**
     * Stream one entry to its target, keeping the mode of a file it replaces.
     *
     * @param int    $index
     * @param string $target
     *
     * @return void
     */
    private function write(int $index, string $target): void
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
        $ok = stream_copy_to_stream($in, $out) !== false;
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
