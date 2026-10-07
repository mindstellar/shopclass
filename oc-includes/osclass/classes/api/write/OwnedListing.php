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
 * A listing as a write needs it: the t_item columns with the location's, and when asked for,
 * its title and description in each language. OwnedListings reads one.
 */
final class OwnedListing
{
    /**
     * @param array<string,mixed>                   $row
     * @param array<string,array{0:string,1:string}> $texts locale => [title, description]
     */
    public function __construct(private array $row, private array $texts = [])
    {
    }

    public function id(): int
    {
        return (int) $this->row['pk_i_id'];
    }

    public function userId(): int
    {
        return (int) ($this->row['fk_i_user_id'] ?? 0);
    }

    /**
     * The edit secret. It is never sent back to the caller.
     */
    public function secret(): string
    {
        return (string) ($this->row['s_secret'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    public function row(): array
    {
        return $this->row;
    }

    /**
     * @return array<string,array{0:string,1:string}> locale => [title, description]
     */
    public function texts(): array
    {
        return $this->texts;
    }
}
