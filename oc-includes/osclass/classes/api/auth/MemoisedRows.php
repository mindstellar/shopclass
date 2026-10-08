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

namespace mindstellar\api\auth;

/**
 * Rows by id, each loaded once per request through the loader it is given.
 */
class MemoisedRows
{
    /** @var array<int,array<string,mixed>|null> */
    protected array $rows = [];

    /** @var \Closure(int): ?array<string,mixed> */
    private \Closure $load;

    /**
     * @param callable $load (id) => the row, or null
     */
    public function __construct(callable $load)
    {
        $this->load = \Closure::fromCallable($load);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        if (!array_key_exists($id, $this->rows)) {
            $this->rows[$id] = $id > 0 ? ($this->load)($id) : null;
        }

        return $this->rows[$id];
    }
}
