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

/**
 * What a listing write saved.
 */
final class SavedListing
{
    /** @var string[] */
    private array $notices = array();

    /**
     * @param int|false|null $rows rows the edit updated; null for a new listing
     */
    public function __construct(private int $id, private bool $needsValidation, private int|false|null $rows = null)
    {
    }

    /**
     * The same result with what went wrong with a photo, for the form to show.
     *
     * @param string[] $notices
     */
    public function withNotices(array $notices): self
    {
        $copy          = clone $this;
        $copy->notices = $notices;

        return $copy;
    }

    /**
     * @return string[]
     */
    public function notices(): array
    {
        return $this->notices;
    }

    public function id(): int
    {
        return $this->id;
    }

    /**
     * The listing waits: a new one for its owner's activation link, an edit for an admin or for that same link.
     */
    public function needsValidation(): bool
    {
        return $this->needsValidation;
    }

    /**
     * @return int|false|null rows the edit updated, false when the update failed; null for a new listing
     */
    public function rows(): int|false|null
    {
        return $this->rows;
    }
}
