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

use RuntimeException;

/**
 * An append-only zip writer that can stop after any entry and carry on in a later
 * request. Entries stream through in 1 MB chunks, so memory stays flat; the central
 * directory waits in a file beside the archive until finish().
 *
 * Every entry is written with zip64 fields, so an archive or an entry may pass 4 GB.
 */
final class ZipWriter
{
    private const CHUNK = 1048576;

    /** Already compressed: stored, not deflated. */
    private const STORED = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'zip', 'gz', 'mp4', 'webm', 'pdf', 'woff', 'woff2');

    /** @var string */
    private $path;

    /** @var resource */
    private $archive;

    /** @var resource */
    private $cdir;

    /** @var int */
    private $entries;

    /**
     * @param string                                          $path  the archive being written
     * @param array{bytes?:int,cdir_bytes?:int,entries?:int} $state what state() returned last time; empty for a new archive
     *
     * @throws RuntimeException when a file cannot be opened
     */
    public function __construct(string $path, array $state = array())
    {
        $this->path    = $path;
        $this->entries = (int) ($state['entries'] ?? 0);
        $this->archive = self::openPrivate($path);
        $this->cdir    = self::openPrivate($path . '.cdir');

        // Anything past the last recorded entry is a run that stopped halfway: drop it.
        ftruncate($this->archive, (int) ($state['bytes'] ?? 0));
        ftruncate($this->cdir, (int) ($state['cdir_bytes'] ?? 0));
        fseek($this->archive, 0, SEEK_END);
        fseek($this->cdir, 0, SEEK_END);
    }

    /**
     * Where the archive stands, for the next run to reopen it.
     *
     * @return array{bytes:int,cdir_bytes:int,entries:int}
     */
    public function state(): array
    {
        fflush($this->archive);
        fflush($this->cdir);

        return array(
            'bytes'      => (int) ftell($this->archive),
            'cdir_bytes' => (int) ftell($this->cdir),
            'entries'    => $this->entries,
        );
    }

    /**
     * Add a file from disk.
     *
     * @param string $name   the entry name
     * @param string $source the file on disk
     * @param bool   $sha256 also return the SHA-256 of the content
     *
     * @return array{crc:int,size:int,compressed:int,sha256:string}
     * @throws RuntimeException when the file cannot be read
     */
    public function addFile(string $name, string $source, bool $sha256 = false): array
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('Could not read ' . basename($source));
        }
        try {
            return $this->addStream($name, $in, self::shouldDeflate($name), (int) @filemtime($source), $sha256);
        } finally {
            fclose($in);
        }
    }

    /**
     * Add an entry from a string.
     *
     * @param string $name
     * @param string $data
     *
     * @return array{crc:int,size:int,compressed:int,sha256:string}
     */
    public function addString(string $name, string $data): array
    {
        $in = fopen('php://memory', 'w+b');
        fwrite($in, $data);
        rewind($in);
        try {
            return $this->addStream($name, $in, true, time(), false);
        } finally {
            fclose($in);
        }
    }

    /**
     * Add an entry read from a stream to its end.
     *
     * @param string   $name
     * @param resource $in
     * @param bool     $deflate
     * @param int      $mtime
     * @param bool     $sha256
     *
     * @return array{crc:int,size:int,compressed:int,sha256:string}
     */
    public function addStream(string $name, $in, bool $deflate, int $mtime, bool $sha256 = false): array
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || strlen($name) > 0xFFFF) {
            throw new RuntimeException('An entry name must be 1 to 65535 bytes');
        }
        $method = $deflate ? 8 : 0;
        list($time, $date) = self::dosTime($mtime > 0 ? $mtime : time());
        $offset = (int) ftell($this->archive);

        $this->write($this->archive, pack(
            'VvvvvvVVVvv',
            0x04034b50,
            45,
            0x0800,
            $method,
            $time,
            $date,
            0,
            0xFFFFFFFF,
            0xFFFFFFFF,
            strlen($name),
            20
        ) . $name . pack('vvPP', 0x0001, 16, 0, 0));

        $crc     = hash_init('crc32b');
        $sha     = $sha256 ? hash_init('sha256') : null;
        $deflator = $deflate ? deflate_init(ZLIB_ENCODING_RAW, array('level' => 6)) : null;
        $size    = 0;
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, self::CHUNK);
            if ($chunk === false) {
                throw new RuntimeException('Could not read the entry ' . $name);
            }
            if ($chunk === '') {
                continue;
            }
            hash_update($crc, $chunk);
            if ($sha !== null) {
                hash_update($sha, $chunk);
            }
            $size += strlen($chunk);
            $out = $deflator !== null ? deflate_add($deflator, $chunk, ZLIB_NO_FLUSH) : $chunk;
            if ($out !== '') {
                $this->write($this->archive, $out);
                $written += strlen($out);
            }
        }
        if ($deflator !== null) {
            $out = deflate_add($deflator, '', ZLIB_FINISH);
            $this->write($this->archive, $out);
            $written += strlen($out);
        }
        $crc32 = (int) hexdec(hash_final($crc));

        // Patch the CRC and the zip64 sizes into the header now that they are known.
        $end = (int) ftell($this->archive);
        fseek($this->archive, $offset + 14);
        $this->write($this->archive, pack('V', $crc32));
        fseek($this->archive, $offset + 30 + strlen($name) + 4);
        $this->write($this->archive, pack('PP', $size, $written));
        fseek($this->archive, $end);

        $this->write($this->cdir, self::centralRecord($name, $method, $time, $date, $crc32, $size, $written, $offset));
        $this->entries++;

        return array(
            'crc'        => $crc32,
            'size'       => $size,
            'compressed' => $written,
            'sha256'     => $sha !== null ? hash_final($sha) : '',
        );
    }

    /**
     * Write the central directory and the end records, and close both files. The
     * central-directory file is removed.
     *
     * @return void
     */
    public function finish(): void
    {
        $offset = (int) ftell($this->archive);
        rewind($this->cdir);
        $size = (int) stream_copy_to_stream($this->cdir, $this->archive);
        $this->write($this->archive, self::endRecords($this->entries, $size, $offset));
        $this->close();
        @unlink($this->path . '.cdir');
    }

    /**
     * Close both files, leaving the archive unfinished for a later run.
     *
     * @return void
     */
    public function close(): void
    {
        if (is_resource($this->archive)) {
            fclose($this->archive);
        }
        if (is_resource($this->cdir)) {
            fclose($this->cdir);
        }
    }

    /**
     * Whether an entry is deflated: everything but formats that are compressed already.
     *
     * @param string $name
     *
     * @return bool
     */
    public static function shouldDeflate(string $name): bool
    {
        return !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::STORED, true);
    }

    /**
     * One central-directory record, sizes and offset always in the zip64 field.
     *
     * @param string $name
     * @param int    $method
     * @param int    $time
     * @param int    $date
     * @param int    $crc
     * @param int    $size
     * @param int    $compressed
     * @param int    $offset
     *
     * @return string
     */
    public static function centralRecord(string $name, int $method, int $time, int $date, int $crc, int $size, int $compressed, int $offset): string
    {
        return pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            (3 << 8) | 45,
            45,
            0x0800,
            $method,
            $time,
            $date,
            $crc,
            0xFFFFFFFF,
            0xFFFFFFFF,
            strlen($name),
            28,
            0,
            0,
            0,
            (0100644 << 16),
            0xFFFFFFFF
        ) . $name . pack('vvPPP', 0x0001, 24, $size, $compressed, $offset);
    }

    /**
     * The zip64 end record, its locator, and the classic end record.
     *
     * @param int $entries
     * @param int $cdirSize
     * @param int $cdirOffset
     *
     * @return string
     */
    public static function endRecords(int $entries, int $cdirSize, int $cdirOffset): string
    {
        $zip64 = $cdirOffset + $cdirSize;

        return pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $entries, $entries, $cdirSize, $cdirOffset)
            . pack('VVPV', 0x07064b50, 0, $zip64, 1)
            . pack(
                'VvvvvVVv',
                0x06054b50,
                0,
                0,
                min($entries, 0xFFFF),
                min($entries, 0xFFFF),
                min($cdirSize, 0xFFFFFFFF),
                min($cdirOffset, 0xFFFFFFFF),
                0
            );
    }

    /**
     * A Unix time as DOS time and date.
     *
     * @param int $ts
     *
     * @return array{0:int,1:int}
     */
    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        if ($d['year'] < 1980) {
            return array(0, (1 << 5) | 1);
        }

        return array(
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        );
    }

    /**
     * Open a file for writing without truncating it, readable by its owner only.
     *
     * @param string $path
     *
     * @return resource
     */
    private static function openPrivate(string $path)
    {
        $umask  = umask(0077);
        $handle = @fopen($path, 'c+b');
        umask($umask);
        if ($handle === false) {
            throw new RuntimeException('Could not write ' . basename($path));
        }
        @chmod($path, 0600);

        return $handle;
    }

    /**
     * Write all of $data or fail.
     *
     * @param resource $handle
     * @param string   $data
     *
     * @return void
     */
    private function write($handle, string $data): void
    {
        $length = strlen($data);
        $done   = 0;
        while ($done < $length) {
            $n = fwrite($handle, $done === 0 ? $data : substr($data, $done));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Could not write the backup. The disk may be full.');
            }
            $done += $n;
        }
    }
}
