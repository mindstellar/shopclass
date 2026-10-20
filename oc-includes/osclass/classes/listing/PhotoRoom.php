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
 * How many photos a listing holds and may still take, as an object a caller can be given.
 * The rules are PhotoService's.
 */
class PhotoRoom
{
    /**
     * @param int|null $ownerId null for a guest listing
     *
     * @return int|null null when there is no cap
     */
    public function cap(?int $ownerId): ?int
    {
        return PhotoService::cap($ownerId);
    }

    /**
     * @return int|null null when there is no cap
     */
    public function room(int $itemId, ?int $ownerId): ?int
    {
        $cap = $this->cap($ownerId);

        return $cap === null ? null : max(0, $cap - $this->count($itemId));
    }

    public function count(int $itemId): int
    {
        return PhotoService::count($itemId);
    }
}
