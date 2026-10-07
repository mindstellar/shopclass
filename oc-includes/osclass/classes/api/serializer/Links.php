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

namespace mindstellar\api\serializer;

/**
 * The site's absolute URLs and display formats a serializer needs: the theme helpers in
 * production, plain strings in tests. Every method but avatar() works from the row it is
 * given and runs no query.
 */
interface Links
{
    /**
     * @param array<string,mixed> $item a listing row with s_title, s_city, fk_i_category_id
     *
     * @api
     */
    public function listing(array $item): string;

    /**
     * @param array<string,mixed> $resource a t_item_resource row
     * @param string              $variant  '' (normal), 'thumbnail', 'preview' or 'original'
     *
     * @api
     */
    public function photo(array $resource, string $variant): string;

    /** @api */
    public function user(int $id, string $username): string;

    /**
     * The user's avatar, or the site's placeholder.
     *
     * @api
     */
    public function avatar(int $userId): string;

    /**
     * An absolute API URL; $path is below /api/{version}/ and may carry a query string. The pinned version when $version is null.
     *
     * @api
     */
    public function api(string $path, ?string $version = null): string;

    /**
     * A price as the site shows it, e.g. "12.50 €"; "Free" for 0 and the theme's text for null.
     *
     * @api
     */
    public function price(?int $micros, string $symbol): string;
}
