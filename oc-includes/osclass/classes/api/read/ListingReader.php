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
use mindstellar\currency\CurrencyService;
use mindstellar\database\Db;
use mindstellar\fields\FieldQuery;
use mindstellar\listing\ListingQuery;
use mindstellar\user\UserQuery;

/**
 * Listings as the API answers with them, serialized in the caller's view. A page costs a fixed
 * number of queries whatever its size, and a database error reaches the kernel as a 500.
 */
final class ListingReader
{
    private ListingQuery $listings;

    private ListingRows $rows;

    private ?CurrencyService $currencies;

    public function __construct(private CategoryCatalog $categories, private ListingSerializer $serializer, ?ListingQuery $listings = null, ?CurrencyService $currencies = null)
    {
        $this->listings   = $listings ?? new ListingQuery();
        $this->rows       = new ListingRows($this->listings);
        $this->currencies = $currencies;
    }

    /**
     * One listing in the context's view, or null when there is no such listing. Hidden
     * listings are answered too; plugins use ApiKit::listing(), which checks who may see it.
     *
     * @return array<string,mixed>|null
     */
    public function one(int $id, ViewContext $context): ?array
    {
        $item = $this->row($id);

        return $item === null ? null : $this->view($item, $context);
    }

    /**
     * The listing's row with its texts in every language, its counters and its location, or null.
     *
     * @param (callable(array<string,mixed>): ?array<string,mixed>)|null $keep checks the bare row first; null drops it before its texts are read
     *
     * @return array<string,mixed>|null
     */
    public function row(int $id, ?callable $keep = null): ?array
    {
        return $this->rows->find($id, OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale(), $keep);
    }

    /**
     * Listing rows made ready for many(). Only the context's language is read, unless the
     * response carries `translations`.
     *
     * @param array<int,array<string,mixed>> $items t_item rows
     *
     * @return array<int,array<string,mixed>>
     */
    public function extend(array $items, ViewContext $context): array
    {
        return $this->rows->extend($items, $context->locale(), $context->includes('translations') && $context->wants('translations'));
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
     * Listing rows from extend(), each in the context's view.
     *
     * @param array<int,array<string,mixed>> $items extended listing rows
     *
     * @return array<int,array<string,mixed>>
     */
    public function many(array $items, ViewContext $context): array
    {
        return $this->serializer->many($items, $this->load($items, ListingSerializer::lookups($context)), $context);
    }

    /**
     * A listing's photos, oldest first. It reads no category, and does not check who may see
     * the listing, so it is not plugin API: ApiKit::listing() answers with the photos.
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
        if ($codes !== []) {
            $this->currencies ??= CurrencyService::make();
            $currencies         = $this->currencies->findMany(array_keys($codes));
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
        return self::byItem($this->listings->photos($ids));
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
        return (new UserQuery())->byIds($ids, ['pk_i_id', 's_name', 's_username'], true);
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
        return self::byItem(Db::stringifyRows(FieldQuery::valuesOf($ids)));
    }

    /**
     * @param array<int,array<string,mixed>> $rows rows with fk_i_item_id
     *
     * @return array<int,array<int,array<string,mixed>>> item id => its rows, in order
     */
    private static function byItem(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_item_id']][] = $row;
        }

        return $out;
    }
}
