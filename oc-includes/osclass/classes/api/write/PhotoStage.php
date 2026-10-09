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

use mindstellar\listing\UploadTmpStore;
use mindstellar\utility\Clock;

/**
 * Photos uploaded before their listing exists, kept where the listing form keeps its own:
 * a file in uploads/temp/ and a t_item_upload_tmp row. The row's token is the user's, so a
 * photo token only works for the user who uploaded it. The hourly cron removes both after
 * two hours, as it does for the form's uploads.
 */
final class PhotoStage
{
    /** Seconds a staged photo is kept. */
    public const TTL = UploadTmpStore::TTL;

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
        return new self(UploadTmpStore::dir(), $clock);
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
    public function stage(int $userId, PhotoFile $photo): PhotoFile
    {
        $token = bin2hex(random_bytes(16));
        $file  = self::PREFIX . $token . '.' . $photo->extension();
        if ((!is_dir($this->dir) && !@mkdir($this->dir, 0755, true)) || !@rename($photo->path(), $this->dir . $file)) {
            throw new \RuntimeException('The photo could not be stored.');
        }
        $now   = $this->clock->now();
        $owner = UploadTmpStore::userOwner($userId);
        if (UploadTmpStore::stage($owner, $token, $file, $now) > self::MAX_PENDING) {
            UploadTmpStore::discard($owner, $file, $this->dir);

            throw new \OverflowException('Too many photos are waiting for a listing.');
        }

        return new PhotoFile($this->dir . $file, $photo->extension(), $token, $now + self::TTL);
    }

    /**
     * The user's staged photos for these tokens. A token that is unknown, another user's,
     * expired or whose file is gone is left out.
     *
     * @param string[] $tokens
     *
     * @return array<string,PhotoFile> token => photo
     */
    public function staged(int $userId, array $tokens): array
    {
        $tokens = self::wellFormed($tokens);
        if ($tokens === []) {
            return [];
        }
        $out = [];
        foreach (UploadTmpStore::staged(UploadTmpStore::userOwner($userId), $tokens, $this->clock->now(), $this->dir) as $token => $row) {
            $out[$token] = new PhotoFile($this->dir . $row['file'], strtolower(pathinfo($row['file'], PATHINFO_EXTENSION)), $token, $row['expires']);
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
        UploadTmpStore::remove(UploadTmpStore::userOwner($userId), $tokens);
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
}
