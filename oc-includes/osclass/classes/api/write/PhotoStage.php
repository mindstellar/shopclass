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

namespace mindstellar\api\write;

use mindstellar\base\Model;
use mindstellar\utility\Clock;

/**
 * Photos uploaded before their listing exists, kept where the listing form keeps its own:
 * a file in uploads/temp/ and a t_item_upload_tmp row. The row's token is the user's, so a
 * photo token only works for the user who uploaded it. The hourly cron removes both after
 * two hours, as it does for the form's uploads.
 */
final class PhotoStage extends Model
{
    protected const TABLE = 't_item_upload_tmp';

    /** Seconds a staged photo is kept. */
    public const TTL = 7200;

    /** Staged photos one user may hold at once. */
    public const MAX_PENDING = 50;

    /** The cron sweeps files named like this. */
    private const PREFIX = 'qqfile_api_';

    /**
     * @param string $dir the temp folder, with a trailing slash
     */
    public function __construct(private string $dir, private Clock $clock)
    {
    }

    public static function fromSite(Clock $clock): self
    {
        return new self(osc_content_path() . 'uploads/temp/', $clock);
    }

    /**
     * Keep a checked photo under a new token.
     *
     * The row is written first and counted after, so two uploads at once cannot both slip
     * under the cap: each sees the other, and one that ends up over it takes itself out.
     *
     * @throws \OverflowException when the user already holds MAX_PENDING photos
     * @throws \RuntimeException  when the file cannot be moved
     */
    public function stage(int $userId, CheckedPhoto $photo): StagedPhoto
    {
        $token = bin2hex(random_bytes(16));
        $file  = self::PREFIX . $token . '.' . $photo->extension();
        if ((!is_dir($this->dir) && !@mkdir($this->dir, 0755, true)) || !@rename($photo->path(), $this->dir . $file)) {
            throw new \RuntimeException('The photo could not be stored.');
        }
        $now   = $this->clock->now();
        $owner = self::owner($userId);
        osc_db_execute(
            'INSERT INTO ' . self::tableName() . ' (s_token, s_uuid, s_file, dt_date) VALUES (?, ?, ?, ?)',
            [$owner, $token, $file, date('Y-m-d H:i:s', $now)]
        );
        $pending = (int) osc_db_scalar('SELECT COUNT(*) FROM ' . self::tableName() . ' WHERE s_token = ? AND dt_date > ?', [$owner, $this->cutoff()]);
        if ($pending > self::MAX_PENDING) {
            $this->forget($userId, [$token]);
            @unlink($this->dir . $file);

            throw new \OverflowException('Too many photos are waiting for a listing.');
        }

        return new StagedPhoto($token, $this->dir . $file, $now + self::TTL);
    }

    /**
     * The user's staged photos for these tokens. A token that is unknown, another user's,
     * expired or whose file is gone is left out.
     *
     * @param string[] $tokens
     *
     * @return array<string,StagedPhoto> token => photo
     */
    public function staged(int $userId, array $tokens): array
    {
        $tokens = self::wellFormed($tokens);
        if ($tokens === []) {
            return [];
        }
        $rows = osc_db_select(
            'SELECT s_uuid, s_file, dt_date FROM ' . self::tableName() . ' WHERE s_token = ? AND dt_date > ? AND s_uuid IN ('
            . implode(', ', array_fill(0, count($tokens), '?')) . ')',
            array_merge([self::owner($userId), $this->cutoff()], $tokens)
        );
        $out = [];
        foreach ($rows as $row) {
            $file = (string) $row['s_file'];
            if (basename($file) === $file && is_file($this->dir . $file)) {
                $out[(string) $row['s_uuid']] = new StagedPhoto((string) $row['s_uuid'], $this->dir . $file, (int) strtotime((string) $row['dt_date']) + self::TTL);
            }
        }

        return $out;
    }

    /**
     * Forget used tokens; their files were moved into the listing.
     *
     * @param string[] $tokens
     */
    public function forget(int $userId, array $tokens): void
    {
        $tokens = self::wellFormed($tokens);
        if ($tokens === []) {
            return;
        }
        osc_db_execute(
            'DELETE FROM ' . self::tableName() . ' WHERE s_token = ? AND s_uuid IN (' . implode(', ', array_fill(0, count($tokens), '?')) . ')',
            array_merge([self::owner($userId)], $tokens)
        );
    }

    /**
     * A free path in the temp folder for a file on its way in.
     */
    public function tempPath(string $extension): string
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        return $this->dir . self::PREFIX . bin2hex(random_bytes(16)) . '.' . $extension;
    }

    /**
     * @param mixed[] $tokens
     *
     * @return string[]
     */
    private static function wellFormed(array $tokens): array
    {
        return array_values(array_unique(array_filter($tokens, static fn ($t): bool => is_string($t) && preg_match('/^[0-9a-f]{32}$/D', $t) === 1)));
    }

    private function cutoff(): string
    {
        return date('Y-m-d H:i:s', $this->clock->now() - self::TTL);
    }

    private static function owner(int $userId): string
    {
        return 'api:' . $userId;
    }
}
