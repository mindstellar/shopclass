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
 * Links through the site's own helpers, so storage offload, CDN and URL filters apply to
 * the API exactly as to the theme.
 */
final class SiteLinks implements Links
{
    public function listing(array $item): string
    {
        return (string) osc_item_url_from_item($item);
    }

    public function photo(array $resource, string $variant): string
    {
        return osc_get_resource_url($resource, $variant);
    }

    public function user(int $id, string $username): string
    {
        return osc_rewrite_enabled() && $username !== ''
            ? (string) osc_core_url('user_pub_profile', ['username' => $username])
            : (string) osc_core_url('user_pub_profile_id', ['id' => $id]);
    }

    public function avatar(int $userId): string
    {
        return osc_user_avatar_url($userId, 'thumbnail');
    }

    public function api(string $path, ?string $version = null): string
    {
        return osc_api_url($path, $version);
    }

    public function price(?int $micros, string $symbol): string
    {
        return (string) osc_format_price($micros, $symbol);
    }
}
