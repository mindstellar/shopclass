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

use mindstellar\base\Model;

/**
 * Photos uploaded before their listing exists: a file in uploads/temp/ and a t_item_upload_tmp
 * row tying it to an owner token. The listing form and the API both stage through here.
 */
final class UploadTmpStore extends Model
{
    protected const TABLE = 't_item_upload_tmp';

    /** Seconds a staged photo is kept; the hourly cron sweeps older ones. */
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
     */
    public static function stage(string $owner, string $uuid, string $file, int $now): int
    {
        self::add($owner, $uuid, $file, date('Y-m-d H:i:s', $now));

        return self::countSince($owner, self::cutoff($now));
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
        foreach (self::find($owner, self::cutoff($now), $uuids) as $row) {
            $file = (string) $row['s_file'];
            if (basename($file) === $file && is_file($dir . $file)) {
                $out[(string) $row['s_uuid']] = ['file' => $file, 'expires' => (int) strtotime((string) $row['dt_date']) + self::TTL];
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
     * @throws \mindstellar\database\DbException
     */
    public static function add(string $owner, string $uuid, string $file, string $date): void
    {
        self::table()->insert(['s_token' => $owner, 's_uuid' => $uuid, 's_file' => $file, 'dt_date' => $date]);
    }

    /**
     * Rows the owner added after the cutoff.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function countSince(string $owner, string $cutoff): int
    {
        return self::table()->where('s_token', $owner)->where('dt_date', '>', $cutoff)->count();
    }

    /**
     * The owner's rows for these uuids, added after the cutoff.
     *
     * @param string[] $uuids
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function find(string $owner, string $cutoff, array $uuids): array
    {
        return self::table()->select('s_uuid', 's_file', 'dt_date')
            ->where('s_token', $owner)->where('dt_date', '>', $cutoff)->whereIn('s_uuid', $uuids)->get();
    }

    /**
     * @param string[] $uuids
     *
     * @throws \mindstellar\database\DbException
     */
    public static function remove(string $owner, array $uuids): void
    {
        self::table()->where('s_token', $owner)->whereIn('s_uuid', $uuids)->delete();
    }

    /**
     * Whether the owner staged this file.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function owns(string $owner, string $file): bool
    {
        return $owner !== '' && $file !== '' && self::table()->select('pk_i_id')->where('s_token', $owner)->where('s_file', $file)->first() !== null;
    }

    /**
     * Delete one file's row when the owner staged it; a count of 0 means it did not.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function removeFile(string $owner, string $file): int
    {
        return self::table()->where('s_token', $owner)->where('s_file', $file)->delete();
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function removeOwner(string $owner): int
    {
        return self::table()->where('s_token', $owner)->delete();
    }

    /**
     * Drop rows dated at or before $before ('Y-m-d H:i:s').
     *
     * @throws \mindstellar\database\DbException
     */
    public static function pruneBefore(string $before): int
    {
        return self::table()->where('dt_date', '<=', $before)->delete();
    }

    private static function cutoff(int $now): string
    {
        return date('Y-m-d H:i:s', $now - self::TTL);
    }
}
