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

/**
 * The photos one listing write brings: staged photos named by token, then photos fetched
 * from their URLs, in that order. The listing takes copies of the staged ones.
 */
final class PhotoBatch
{
    /**
     * @param PhotoFile[]  $staged
     * @param PhotoFile[] $fetched
     * @param int            $skipped URLs not fetched because the listing had no room left
     * @param PhotoFile[] $copies  a copy of each staged photo, in the same order
     */
    public function __construct(private array $staged = [], private array $fetched = [], private int $skipped = 0, private array $copies = [])
    {
    }

    /**
     * Every file the listing takes, copies of the staged ones first.
     *
     * @return string[]
     */
    public function paths(): array
    {
        return array_map(static fn (PhotoFile $p): string => $p->path(), array_merge($this->copies, $this->fetched));
    }

    /**
     * @return string[]
     */
    public function tokens(): array
    {
        return array_map(static fn (PhotoFile $p): string => $p->token(), $this->staged);
    }

    /**
     * Photos sent, including those not fetched for want of room.
     */
    public function sent(): int
    {
        return count($this->staged) + count($this->fetched) + $this->skipped;
    }

    public function isEmpty(): bool
    {
        return $this->sent() === 0;
    }

    /**
     * Remove the downloaded files and the copies, and with $saved the staged photos too. Unsaved,
     * the staged photos stay so their tokens can be sent again.
     */
    public function discard(bool $saved): void
    {
        foreach (array_merge($this->copies, $this->fetched) as $photo) {
            $photo->discard();
        }
        if ($saved) {
            foreach ($this->staged as $photo) {
                @unlink($photo->path());
            }
        }
    }
}
