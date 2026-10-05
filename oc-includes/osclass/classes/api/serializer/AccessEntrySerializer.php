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

use mindstellar\api\auth\AccessEntry;
use mindstellar\api\auth\Credential;

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
        $row = $session->row();

        return [
            'id'           => $session->id(),
            'type'         => $session->type(),
            'label'        => $row->name(),
            'prefix'       => $session->prefix(),
            'scopes'       => $row->scopes(),
            'last_used_at' => Format::timestamp($row->lastUsedAt() ?? $row->createdAt()),
            'last_ip'      => $row->lastIp() === '' ? null : $row->lastIp(),
            'expires_at'   => Format::timestamp($row->expiresAt()),
            'current'      => $session->isCurrent($credential),
        ];
    }
}
