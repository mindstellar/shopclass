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

use mindstellar\apiaccess\AccessEntry;
use mindstellar\apiaccess\Credential;

/**
 * A sign-in or key as `GET /account/sessions` lists it.
 */
final class AccessEntrySerializer
{
    /**
     * @param Credential $credential the caller, to mark the session making the request
     *
     * @return array<string,mixed>
     */
    public function one(AccessEntry $session, Credential $credential): array
    {
        return $session->toArray($credential);
    }
}
