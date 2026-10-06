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

namespace mindstellar\apiaccess;

/**
 * Where API credentials are kept: t_api_credential in production, an array in tests.
 */
interface CredentialStore
{
    /**
     * The credential with this public token half, its owner joined in.
     */
    public function findByTokenId(string $tokenId): ?StoredKey;

    public function find(int $id): ?StoredKey;

    /**
     * Store a new credential; its id is ignored.
     *
     * @return int the new id
     */
    public function insert(StoredKey $key): int;

    /**
     * Record a use: when, and from where.
     */
    public function touch(int $id, string $ip, int $time): void;

    /**
     * Stop a credential working. The row stays, so its last use can still be shown.
     *
     * @return bool false when it was already revoked or does not exist
     */
    public function revoke(int $id): bool;
}
