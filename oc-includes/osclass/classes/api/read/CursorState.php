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

namespace mindstellar\api\read;

/**
 * Where a list stopped: keyset (the last row's sort values) or offset, for one sort and
 * one set of filters.
 */
final class CursorState
{
    public const KEYSET = 'keyset';
    public const OFFSET = 'offset';

    /**
     * @param array<int,int|string|null> $after
     */
    private function __construct(
        private string $kind,
        private string $sort,
        private string $direction,
        private string $filterHash,
        private array $after,
        private int $offset
    ) {
    }

    /**
     * @param array<int,int|string|null> $after the last row's sort values
     */
    public static function keyset(string $sort, string $direction, string $filterHash, array $after): self
    {
        return new self(self::KEYSET, $sort, $direction, $filterHash, array_values($after), 0);
    }

    public static function offset(string $sort, string $direction, string $filterHash, int $offset): self
    {
        return new self(self::OFFSET, $sort, $direction, $filterHash, [], max(0, $offset));
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function sort(): string
    {
        return $this->sort;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    public function filterHash(): string
    {
        return $this->filterHash;
    }

    /**
     * @return array<int,int|string|null>
     */
    public function after(): array
    {
        return $this->after;
    }

    public function offsetValue(): int
    {
        return $this->offset;
    }
}
