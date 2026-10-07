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
 * How one list endpoint pages: the sorts it allows, the sort and order of this request, its
 * page sizes, and how a row becomes a keyset cursor.
 */
final class ListSpec
{
    /** The page size of a list that names none, and the most it may ask for. */
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT     = 100;

    /** @var \Closure(array<int,int|string>): bool */
    private \Closure $keysetFits;

    /** @var \Closure(array<string,mixed>): array<int,int|string> */
    private \Closure $keyset;

    /**
     * @param string[]      $sorts      the sorts the list allows
     * @param callable|null $keysetFits fn(array $after): bool, whether a cursor's values have the right shape; any do when null
     * @param callable|null $keyset     fn(array $row): array, a row's keyset values; none when null
     * @param int           $maxOffset  how deep offset paging goes
     */
    public function __construct(
        private array $sorts,
        private string $sort,
        private string $direction,
        private int $defaultLimit,
        private int $maxLimit,
        ?callable $keysetFits = null,
        ?callable $keyset = null,
        private int $maxOffset = 10000
    ) {
        $this->keysetFits = \Closure::fromCallable($keysetFits ?? static fn (array $after): bool => true);
        $this->keyset     = \Closure::fromCallable($keyset ?? static fn (array $row): array => []);
    }

    /**
     * Paged by row id, newest (`desc`) or oldest (`asc`) first.
     */
    public static function byId(string $direction = 'desc', int $defaultLimit = self::DEFAULT_LIMIT, int $maxLimit = self::MAX_LIMIT): self
    {
        return new self(
            ['id'],
            'id',
            $direction,
            $defaultLimit,
            $maxLimit,
            static fn (array $after): bool => count($after) === 1 && is_int($after[0]),
            static fn (array $row): array => [(int) $row['pk_i_id']]
        );
    }

    /**
     * @return string[]
     */
    public function sorts(): array
    {
        return $this->sorts;
    }

    public function sort(): string
    {
        return $this->sort;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    public function defaultLimit(): int
    {
        return $this->defaultLimit;
    }

    public function maxLimit(): int
    {
        return $this->maxLimit;
    }

    public function maxOffset(): int
    {
        return $this->maxOffset;
    }

    /**
     * @param array<int,int|string> $after
     */
    public function keysetFits(array $after): bool
    {
        return ($this->keysetFits)($after);
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<int,int|string>
     */
    public function keyset(array $row): array
    {
        return ($this->keyset)($row);
    }
}
