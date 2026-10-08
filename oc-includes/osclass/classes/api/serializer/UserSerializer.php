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

use mindstellar\user\UserQuery;
use mindstellar\user\UserStatus;

/**
 * A user's profile, ending with the `api_user` filter. The user themself and admins also get
 * contact details, address and account state.
 */
final class UserSerializer
{
    public const PUBLIC_MEMBERS = [
        'id', 'name', 'username', 'url', 'avatar', 'is_company', 'website', 'location', 'listings_count', 'registered_at',
    ];

    public const PRIVATE_MEMBERS = [
        'email', 'phone_land', 'phone_mobile', 'address', 'zip', 'lat', 'lng', 'status', 'confirmed', 'blocked', 'last_access_at',
        'last_access_ip',
    ];

    public const MEMBERS = [...self::PUBLIC_MEMBERS, ...self::PRIVATE_MEMBERS, 'ext'];

    public function __construct(private Links $links, private Extensions $extensions)
    {
    }

    /**
     * A page of users. Their avatars are read in one query, not one per user.
     *
     * @param array<int,array<string,mixed>> $users t_user rows
     *
     * @return array<int,array<string,mixed>>
     */
    public function many(array $users, ViewContext $context): array
    {
        if ($users !== [] && $context->wants('avatar')) {
            (new UserQuery())->primeAvatars(array_map(static fn (array $user): int => Format::int($user['pk_i_id'] ?? 0), $users));
        }

        return array_map(fn (array $user): array => $this->one($user, $context), array_values($users));
    }

    /**
     * @param array<string,mixed> $user a t_user row
     *
     * @return array<string,mixed>
     */
    public function one(array $user, ViewContext $context): array
    {
        $id      = Format::int($user['pk_i_id'] ?? 0);
        $context = $context->withView($context->viewFor($id, ViewContext::USERS_SCOPE));
        $data    = [
            'id'             => $id,
            'name'           => Format::plain($user['s_name'] ?? ''),
            'username'       => Format::text($user['s_username'] ?? null),
            'url'            => $this->links->user($id, (string) ($user['s_username'] ?? '')),
            'avatar'         => $context->wants('avatar') ? $this->links->avatar($id) : '',
            'is_company'     => Format::bool($user['b_company'] ?? 0),
            'website'        => Format::text($user['s_website'] ?? null),
            'location'       => [
                'country' => Format::place($user['fk_c_country_code'] ?? null, $user['s_country'] ?? null, 'code'),
                'region'  => Format::place($user['fk_i_region_id'] ?? null, $user['s_region'] ?? null, 'id'),
                'city'    => Format::place($user['fk_i_city_id'] ?? null, $user['s_city'] ?? null, 'id'),
            ],
            'listings_count' => Format::int($user['i_items'] ?? 0),
            'registered_at'  => Format::time($user['dt_reg_date'] ?? null),
        ];
        if ($context->view() !== ViewContext::PUBLIC) {
            $data += [
                'email'          => Format::text($user['s_email'] ?? null),
                'phone_land'     => Format::text($user['s_phone_land'] ?? null),
                'phone_mobile'   => Format::text($user['s_phone_mobile'] ?? null),
                'address'        => Format::text($user['s_address'] ?? null),
                'zip'            => Format::text($user['s_zip'] ?? null),
                'lat'            => Format::float($user['d_coord_lat'] ?? null),
                'lng'            => Format::float($user['d_coord_long'] ?? null),
                'status'         => UserStatus::of($user),
                'confirmed'      => Format::bool($user['b_active'] ?? 0),
                'blocked'        => !Format::bool($user['b_enabled'] ?? 0),
                'last_access_at' => Format::time($user['dt_access_date'] ?? null),
                'last_access_ip' => Format::text($user['s_access_ip'] ?? null),
            ];
        }
        $filtered = osc_apply_filter('api_user', $data, $user, $context);

        return $this->extensions->finish('api_user', 'user', self::MEMBERS, $data, $filtered, [$user, $context], $context);
    }
}
