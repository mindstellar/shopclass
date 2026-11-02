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
 * What a page of listings refers to, loaded in one batch (ListingReader): photos, sellers,
 * custom field values, categories and currencies. Serializers only look things up here.
 */
final class ListingRelations
{
    /**
     * @param array<int,array<int,array<string,mixed>>> $photos     item id => listing photo rows
     * @param array<int,array<string,mixed>>            $users      user id => t_user row
     * @param array<int,array<int,array<string,mixed>>> $fields     item id => field value rows
     * @param array<string,array<string,mixed>>         $currencies code => t_currency row
     * @param array<int,array<int,array<string,mixed>>> $variants   item id => t_item_variant rows
     * @param array<int,array{count:int,average:?float}> $ratings    item id => its approved ratings
     * @param array<int,array<int,int>>                 $stars       item id => stars 5 to 1 => count, for a single listing
     */
    public function __construct(
        private CategoryCatalog $categories,
        private array $photos = [],
        private array $users = [],
        private array $fields = [],
        private array $currencies = [],
        private array $variants = [],
        private array $ratings = [],
        private array $stars = []
    ) {
    }

    public function categories(): CategoryCatalog
    {
        return $this->categories;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function photos(int $itemId): array
    {
        return $this->photos[$itemId] ?? [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function user(int $userId): ?array
    {
        return $this->users[$userId] ?? null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fields(int $itemId): array
    {
        return $this->fields[$itemId] ?? [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function variants(int $itemId): array
    {
        return $this->variants[$itemId] ?? [];
    }

    /**
     * @return array{count:int,average:?float}
     */
    public function rating(int $itemId): array
    {
        return $this->ratings[$itemId] ?? ['count' => 0, 'average' => null];
    }

    /**
     * @return array<int,int>|null stars 5 to 1 => count, or null when the page did not load it
     */
    public function stars(int $itemId): ?array
    {
        return $this->stars[$itemId] ?? null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function currency(string $code): ?array
    {
        return $this->currencies[strtoupper($code)] ?? null;
    }
}
