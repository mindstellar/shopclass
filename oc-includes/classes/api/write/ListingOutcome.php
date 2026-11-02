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
 * What a listing save did that the seller did not ask for: the listing is not live yet or waits
 * for payment, or some photos did not fit.
 */
final class ListingOutcome
{
    public function __construct(private int $id, private bool $pending, private int $photosSkipped, private bool $unpaid = false)
    {
    }

    public function id(): int
    {
        return $this->id;
    }

    /**
     * The listing waits for activation or approval.
     */
    public function pending(): bool
    {
        return $this->pending;
    }

    /**
     * The listing is saved hidden and waits for payment.
     */
    public function unpaid(): bool
    {
        return $this->unpaid;
    }

    /**
     * Photos sent that the listing had no room for.
     */
    public function photosSkipped(): int
    {
        return $this->photosSkipped;
    }
}
