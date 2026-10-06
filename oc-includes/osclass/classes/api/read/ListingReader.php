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

use mindstellar\api\serializer\ListingSerializer;
use mindstellar\api\serializer\ViewContext;

/**
 * Listings as the API answers with them: the rows read, what they link to looked up once
 * per page, and each serialized in the caller's view. A page costs a fixed number of
 * queries, whatever its size: one each for photos, sellers and custom field values, each
 * only when the response includes it.
 */
final class ListingReader
{
    public function __construct(private CategoryCatalog $categories, private ListingSerializer $serializer)
    {
    }

    /**
     * One listing in the context's view, or null when there is no such listing.
     *
     * @return array<string,mixed>|null
     */
    public function one(int $id, ViewContext $context): ?array
    {
        $item = $this->row($id);

        return $item === null ? null : $this->view($item, $context);
    }

    /**
     * The listing's extended row (Item::extendData()), or null.
     *
     * @return array<string,mixed>|null
     */
    public function row(int $id): ?array
    {
        $item = \Item::getInstance()->findByPrimaryKey($id);

        return is_array($item) && $item !== [] ? $item : null;
    }

    /**
     * @param array<string,mixed> $item an extended listing row
     *
     * @return array<string,mixed>
     */
    public function view(array $item, ViewContext $context): array
    {
        return $this->serializer->one($item, $this->load([$item], ListingSerializer::lookups($context)), $context);
    }

    /**
     * @param array<int,array<string,mixed>> $items extended listing rows
     *
     * @return array<int,array<string,mixed>>
     */
    public function many(array $items, ViewContext $context): array
    {
        return $this->serializer->many($items, $this->load($items, ListingSerializer::lookups($context)), $context);
    }

    /**
     * A listing's photos, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function photos(int $id): array
    {
        return $this->serializer->photos($this->photoRows([$id])[$id] ?? []);
    }

    /**
     * The site's categories, as listing filters read them.
     */
    public function categories(): CategoryCatalog
    {
        return $this->categories;
    }

    /**
     * @param array<int,array<string,mixed>> $items   extended listing rows
     * @param array<string,bool>             $lookups which of seller, photos, fields to load
     */
    private function load(array $items, array $lookups = ['seller' => true, 'photos' => true, 'fields' => false]): ListingRelations
    {
        $ids     = [];
        $userIds = [];
        $codes   = [];
        foreach ($items as $item) {
            $ids[] = (int) $item['pk_i_id'];
            if (!empty($item['fk_i_user_id'])) {
                $userIds[] = (int) $item['fk_i_user_id'];
            }
            if (!empty($item['fk_c_currency_code'])) {
                $codes[strtoupper((string) $item['fk_c_currency_code'])] = true;
            }
        }
        if ($ids === []) {
            return new ListingRelations($this->categories);
        }
        $currencies = [];
        foreach (array_keys($codes) as $code) {
            $row = \Currency::getInstance()->findByPrimaryKey($code);
            if (is_array($row)) {
                $currencies[$code] = $row;
            }
        }

        return new ListingRelations(
            $this->categories,
            ($lookups['photos'] ?? false) ? $this->photoRows($ids) : [],
            ($lookups['seller'] ?? false) ? $this->users(array_values(array_unique($userIds))) : [],
            ($lookups['fields'] ?? false) ? $this->fieldValues($ids) : [],
            $currencies
        );
    }

    /**
     * Every listing's photos, in upload order.
     *
     * @param int[] $ids
     *
     * @return array<int,array<int,array<string,mixed>>> item id => t_item_resource rows
     */
    private function photoRows(array $ids): array
    {
        try {
            $rows = osc_db_stringify_rows(\mindstellar\listing\PhotoStore::ofItems($ids));
        } catch (\mindstellar\database\DbException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_item_id']][] = $row;
        }

        return $out;
    }

    /**
     * Enabled, active users by id: the sellers a page links to.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    private function users(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $rows = \mindstellar\user\UserStore::byIds($ids, ['pk_i_id', 's_name', 's_username'], true);
        } catch (\mindstellar\database\DbException $e) {
            return [];
        }

        return array_column(osc_db_stringify_rows($rows), null, 'pk_i_id');
    }

    /**
     * Custom field values of every listing, joined to their field, in form order.
     *
     * @param int[] $ids
     *
     * @return array<int,array<int,array<string,mixed>>> item id => rows
     */
    private function fieldValues(array $ids): array
    {
        try {
            $rows = osc_db_stringify_rows(\mindstellar\fields\FieldQuery::valuesOf($ids));
        } catch (\mindstellar\database\DbException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_item_id']][] = $row;
        }

        return $out;
    }
}
