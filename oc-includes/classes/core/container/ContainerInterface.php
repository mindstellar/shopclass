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

namespace mindstellar\container;

/**
 * Finds a service by id, in the PSR-11 shape.
 */
interface ContainerInterface
{
    /**
     * The service registered under $id.
     *
     * @throws NotFoundException when nothing is registered under $id and it cannot be built
     *
     * @return mixed
     */
    public function get(string $id);

    /**
     * Whether get($id) would return a service.
     */
    public function has(string $id): bool;
}
