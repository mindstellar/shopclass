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

namespace mindstellar\comment;

/**
 * A comment CommentService::post() stored, and whether it shows now.
 */
final class SavedComment
{
    /** Waits for approval. */
    public const PENDING = 1;

    /** Shows now. */
    public const LIVE = 2;

    /** Held as spam. */
    public const SPAM = 5;

    /**
     * @param int $status PENDING, LIVE or SPAM, the codes CommentService returns
     */
    public function __construct(private int $id, private int $status)
    {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function isLive(): bool
    {
        return $this->status === self::LIVE;
    }
}
