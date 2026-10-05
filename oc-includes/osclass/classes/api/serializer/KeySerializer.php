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

use mindstellar\api\auth\ApiKeys;
use mindstellar\api\auth\ApiKeyService;
use mindstellar\api\auth\StoredKey;

/**
 * An API key as the admin's and the user's key lists show it. Never the secret or its hash;
 * the token only in the answer that makes the key.
 */
final class KeySerializer
{
    /**
     * A key as `/admin/keys` lists it.
     *
     * @param array<string,mixed> $row   one of ApiKeyService::rows()
     * @param string|null         $token the key's token, right after it was made
     *
     * @return array<string,mixed>
     */
    public function admin(array $row, ?string $token = null): array
    {
        $out = [
            'id'           => (int) $row['id'],
            'name'         => (string) $row['name'],
            'kind'         => (string) $row['kind'],
            'prefix'       => (string) $row['prefix'],
            'scopes'       => array_values((array) $row['scopes']),
            'owner'        => Format::text($row['owner'] ?? null),
            'status'       => (string) $row['status'],
            'created_at'   => Format::timestamp($row['created'] ?? null),
            'last_used_at' => Format::timestamp($row['last_used'] ?? null),
            'expires_at'   => Format::timestamp($row['expires'] ?? null),
        ];
        if ($token !== null) {
            $out['token'] = $token;
        }

        return $out;
    }

    /**
     * A user's personal key as `/account/keys` shows it.
     *
     * @param int         $now   Unix time, for the status
     * @param string|null $token the key's token, right after it was made
     *
     * @return array<string,mixed>
     */
    public function personal(StoredKey $key, int $now, ?string $token = null): array
    {
        $out = [
            'id'           => $key->id(),
            'name'         => $key->name(),
            'prefix'       => ApiKeys::KEY_PREFIX . $key->tokenId(),
            'scopes'       => $key->scopes(),
            'status'       => ApiKeyService::status($key, $now),
            'created_at'   => Format::timestamp($key->createdAt()),
            'last_used_at' => Format::timestamp($key->lastUsedAt()),
            'expires_at'   => Format::timestamp($key->expiresAt()),
        ];
        if ($token !== null) {
            $out['token'] = $token;
        }

        return $out;
    }
}
