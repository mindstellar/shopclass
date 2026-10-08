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
 * Reads and writes of t_item_upload_tmp: photos uploaded before their listing exists, each
 * row tying a temp file to an owner token. The legacy ItemTmpUpload model keeps its own methods.
 */
final class UploadTmpStore extends Model
{
    protected const TABLE = 't_item_upload_tmp';

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
}
