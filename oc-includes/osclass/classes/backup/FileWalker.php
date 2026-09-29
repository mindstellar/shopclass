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

use Generator;

/**
 * Walks oc-content in a fixed order, so a backup can stop after any file and carry on
 * after it in a later run.
 *
 * A linked folder or file is followed only when it points inside the site; one that
 * points outside is skipped and named in skipped(). Links are never stored as links.
 */
final class FileWalker
{
    /** Folders under oc-content that are never backed up. */
    public const EXCLUDED = array('downloads/backups', 'downloads/oc-temp', 'uploads/temp');

    /** @var string real path of the folder walked */
    private $root;

    /** @var string real path of the site */
    private $site;

    /** @var array<string,true> links skipped because they point outside the site */
    private $skipped = array();

    /**
     * @param string $root the folder to walk, normally oc-content
     * @param string $site the site folder; links may not leave it
     */
    public function __construct(string $root, string $site)
    {
        $this->root = rtrim((string) realpath($root), '/\\');
        $this->site = rtrim((string) realpath($site), '/\\');
    }

    /**
     * Every file after $after, in order, as relative path => [absolute path, bytes].
     *
     * @param string $after a relative path from an earlier walk; '' for the start
     *
     * @return Generator<string,array{0:string,1:int}>
     */
    public function files(string $after = ''): Generator
    {
        if ($this->root === '') {
            return;
        }
        yield from $this->walk($this->root, '', $after, array($this->root => true));
    }

    /**
     * Count the files and bytes, stopping at $deadline.
     *
     * @param float $deadline microtime(true) to stop at
     *
     * @return array{count:int,bytes:int,complete:bool}
     */
    public function count(float $deadline): array
    {
        $count = 0;
        $bytes = 0;
        foreach ($this->files() as $file) {
            $count++;
            $bytes += $file[1];
            if (($count & 255) === 0 && microtime(true) > $deadline) {
                return array('count' => $count, 'bytes' => $bytes, 'complete' => false);
            }
        }

        return array('count' => $count, 'bytes' => $bytes, 'complete' => true);
    }

    /**
     * Links that were skipped because they point outside the site, relative to the root.
     *
     * @return string[]
     */
    public function skipped(): array
    {
        return array_keys($this->skipped);
    }

    /**
     * Whether a relative path is never backed up.
     *
     * @param string $rel
     * @param bool   $isDir
     *
     * @return bool
     */
    public static function isExcluded(string $rel, bool $isDir): bool
    {
        foreach (self::EXCLUDED as $folder) {
            if ($rel === $folder || strpos($rel, $folder . '/') === 0) {
                return true;
            }
        }
        if (preg_match('#^uploads/purifier-[^/]*(/|$)#', $rel)) {
            return true;
        }
        if (in_array('.git', explode('/', $rel), true)) {
            return true;
        }

        return !$isDir && substr($rel, -4) === '.log';
    }

    /**
     * Compare two relative paths in walk order: folder by folder, names by byte value.
     *
     * @param string $a
     * @param string $b
     *
     * @return int
     */
    public static function compare(string $a, string $b): int
    {
        $x = explode('/', $a);
        $y = explode('/', $b);
        $n = min(count($x), count($y));
        for ($i = 0; $i < $n; $i++) {
            $c = strcmp($x[$i], $y[$i]);
            if ($c !== 0) {
                return $c < 0 ? -1 : 1;
            }
        }

        return count($x) <=> count($y);
    }

    /**
     * @param string             $dir     absolute folder
     * @param string             $rel     its path relative to the root, '' for the root
     * @param string             $after
     * @param array<string,true> $visited real paths of the folders being walked, against loops
     *
     * @return Generator<string,array{0:string,1:int}>
     */
    private function walk(string $dir, string $rel, string $after, array $visited): Generator
    {
        $names = @scandir($dir);
        if ($names === false) {
            return;
        }
        usort($names, 'strcmp');
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $abs  = $dir . '/' . $name;
            $path = $rel === '' ? $name : $rel . '/' . $name;
            // is_link() on the bare name: with a trailing slash it follows the link.
            $isLink = is_link($abs);
            $real   = $isLink ? realpath($abs) : $abs;
            if ($real === false) {
                continue;
            }
            if ($isLink && !$this->inside($real)) {
                $this->skipped[$path] = true;
                continue;
            }
            $isDir = is_dir($real);
            if (self::isExcluded($path, $isDir)) {
                continue;
            }
            if ($isDir) {
                $key = (string) realpath($real);
                if (isset($visited[$key])) {
                    continue;
                }
                // Everything under a folder that sorts before $after was done in an earlier run.
                if ($after !== '' && self::compare($path, $after) < 0 && strpos($after, $path . '/') !== 0) {
                    continue;
                }
                yield from $this->walk($abs, $path, $after, $visited + array($key => true));
                continue;
            }
            if (!is_file($real) || ($after !== '' && self::compare($path, $after) <= 0)) {
                continue;
            }
            yield $path => array($abs, (int) @filesize($real));
        }
    }

    /**
     * Whether a real path is the site folder or below it.
     *
     * @param string $real
     *
     * @return bool
     */
    private function inside(string $real): bool
    {
        return $this->site !== '' && ($real === $this->site || strpos($real, $this->site . '/') === 0);
    }
}
