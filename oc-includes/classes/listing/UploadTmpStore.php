<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\listing;

use mindstellar\model\KeyValue;

/**
 * Photos uploaded before their listing exists: a file in uploads/temp/ and a t_key_value row
 * tying it to an owner token. Each owner has its own group, keyed by file name, holding the
 * upload's uuid. The listing form and the API both stage through here.
 */
final class UploadTmpStore
{
    /** Seconds a staged photo is kept; expired rows read as absent and the daily cron removes them. */
    public const TTL = 7200;

    /**
     * The temp folder staged files live in, with a trailing slash.
     */
    public static function dir(): string
    {
        return osc_content_path() . 'uploads/temp/';
    }

    /**
     * The owner token of this browser's listing form.
     */
    public static function formOwner(): string
    {
        return (string) osc_upload_token();
    }

    /**
     * The owner token of an API user.
     */
    public static function userOwner(int $userId): string
    {
        return 'api:' . $userId;
    }

    /**
     * Record a file already in the temp folder under an owner.
     *
     * @return int how many unexpired files the owner holds, this one included
     * @throws \mindstellar\database\DbException
     * @throws \InvalidArgumentException on a file name the store cannot hold
     */
    public static function stage(string $owner, string $uuid, string $file, int $now): int
    {
        (new KeyValue())->set(self::group($owner), $file, $uuid, $now + self::TTL, null, $now);

        return count(self::rows($owner, $now));
    }

    /**
     * The owner's unexpired files for these uuids that are still in $dir.
     *
     * @param string[] $uuids
     *
     * @return array<string,array{file:string,expires:int}> uuid => file name and expiry
     * @throws \mindstellar\database\DbException
     */
    public static function staged(string $owner, array $uuids, int $now, string $dir): array
    {
        $out = [];
        foreach (self::rows($owner, $now) as $file => $row) {
            $file = (string) $file;
            if (in_array($row['value'], $uuids, true) && basename($file) === $file && is_file($dir . $file)) {
                $out[(string) $row['value']] = ['file' => $file, 'expires' => (int) $row['expires']];
            }
        }

        return $out;
    }

    /**
     * Delete one staged file, row and file, when the owner staged it.
     *
     * @return bool false when the owner did not stage it or the file could not be removed
     * @throws \mindstellar\database\DbException
     */
    public static function discard(string $owner, string $file, string $dir): bool
    {
        return basename($file) === $file && self::removeFile($owner, $file) > 0 && @unlink($dir . $file);
    }

    /**
     * Forget the owner's files for these uuids.
     *
     * @param string[] $uuids
     *
     * @throws \mindstellar\database\DbException
     */
    public static function remove(string $owner, array $uuids): void
    {
        $kv = new KeyValue();
        foreach (self::rows($owner, time()) as $file => $row) {
            if (in_array($row['value'], $uuids, true)) {
                $kv->delete(self::group($owner), (string) $file);
            }
        }
    }

    /**
     * Whether the owner staged this file.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function owns(string $owner, string $file): bool
    {
        return $owner !== '' && self::validFile($file) && (new KeyValue())->get(self::group($owner), $file) !== null;
    }

    /**
     * Delete one file's row when the owner staged it; a count of 0 means it did not.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function removeFile(string $owner, string $file): int
    {
        return $owner !== '' && self::validFile($file) ? (new KeyValue())->delete(self::group($owner), $file) : 0;
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function removeOwner(string $owner): int
    {
        return $owner !== '' ? (new KeyValue())->deleteGroup(self::group($owner)) : 0;
    }

    /**
     * @return array<string,array{value:?string,expires:?int}> the owner's live rows, by file name
     * @throws \mindstellar\database\DbException
     */
    private static function rows(string $owner, int $now): array
    {
        return $owner !== '' ? (new KeyValue())->group(self::group($owner), 1000, $now) : [];
    }

    private static function group(string $owner): string
    {
        return 'upload.' . sha1($owner);
    }

    private static function validFile(string $file): bool
    {
        try {
            KeyValue::check('upload', $file);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        return true;
    }
}
