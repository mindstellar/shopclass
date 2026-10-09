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

namespace mindstellar\api\http;

use mindstellar\apikey\Credential;

/**
 * The stored version of the resource a GET path names, for ETags and If-Match. A version
 * changes whenever the stored rows do, whatever `fields`, `include` or locale a GET asks for.
 */
interface ResourceVersions
{
    /**
     * Whether a GET route's path (e.g. `listings/{id}`) keeps a version.
     */
    public function supports(string $path): bool;

    /**
     * The resource's current version, or null when it does not exist.
     *
     * @param array<string,string> $args      the path's arguments
     * @param bool                 $lock      lock the rows until the transaction around it ends
     * @param bool                 $ownerOnly null for a user-owned resource the credential's user does not own
     */
    public function version(string $path, array $args, Credential $credential, bool $lock = false, bool $ownerOnly = false): ?string;

    /**
     * Run $fn in one transaction: committed when it returns, rolled back when it throws.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function atomically(callable $fn): mixed;
}
