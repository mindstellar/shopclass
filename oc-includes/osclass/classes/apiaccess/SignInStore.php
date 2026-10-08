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
 * A credential store that also keeps sign-ins: refresh families, and the lists the account
 * and key screens show.
 */
interface SignInStore extends CredentialStore
{
    /**
     * Revoke every live row of one refresh family.
     *
     * @return int rows that were still live
     */
    public function revokeFamily(string $family): int;

    /**
     * Revoke every live refresh token of a user, except one family.
     *
     * @return int rows changed
     */
    public function revokeRefreshFor(int $userId, ?string $keepFamily = null): int;

    /**
     * Credentials, newest first, optionally only one kind and one owner.
     *
     * @return StoredKey[]
     */
    public function listBy(?string $kind = null, ?int $userId = null, ?int $adminId = null, bool $liveOnly = false): array;

    /**
     * Whether a user has any refresh token or key that is not revoked.
     */
    public function hasLiveFor(int $userId): bool;

    /**
     * Run $fn in one transaction: it commits when $fn returns and rolls back when it throws.
     *
     * @param callable(): mixed $fn
     */
    public function atomically(callable $fn): mixed;

    /**
     * Lock a refresh family's rows until the transaction ends, so its rotations and revokes
     * run one at a time. Called inside atomically().
     */
    public function lockFamily(string $family): void;
}
