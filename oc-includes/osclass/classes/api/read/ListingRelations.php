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
     */
    public function __construct(
        private CategoryCatalog $categories,
        private array $photos = [],
        private array $users = [],
        private array $fields = [],
        private array $currencies = []
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
     * @return array<string,mixed>|null
     */
    public function currency(string $code): ?array
    {
        return $this->currencies[strtoupper($code)] ?? null;
    }
}
