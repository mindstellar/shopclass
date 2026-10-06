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
 * per page, and each serialized in the caller's view.
 */
final class ListingReader
{
    public function __construct(private ListingLoader $loader, private ListingSerializer $serializer)
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
        return $this->serializer->one($item, $this->loader->load([$item], ListingSerializer::lookups($context)), $context);
    }

    /**
     * @param array<int,array<string,mixed>> $items extended listing rows
     *
     * @return array<int,array<string,mixed>>
     */
    public function many(array $items, ViewContext $context): array
    {
        return $this->serializer->many($items, $this->loader->load($items, ListingSerializer::lookups($context)), $context);
    }

    /**
     * A listing's photos, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function photos(int $id): array
    {
        return $this->serializer->photos($this->loader->photos([$id])[$id] ?? []);
    }

    /**
     * The site's categories, as listing filters read them.
     */
    public function categories(): CategoryCatalog
    {
        return $this->loader->categories();
    }
}
