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
 * A user's live sign-ins and personal keys, as the API and the account page list and end
 * them.
 */
final class AccessEntries
{
    public function __construct(private SignInStore $store)
    {
    }

    /**
     * Sign-ins first (one per refresh family), then keys; each newest first.
     *
     * @return AccessEntry[]
     */
    public function list(int $userId): array
    {
        $out = [];
        foreach ($this->store->listBy(CredentialKind::REFRESH, $userId, null, true) as $row) {
            $family = $row->family();
            if ($family !== null && !isset($out[$family])) {
                $out[$family] = AccessEntry::signIn($row);
            }
        }
        foreach ($this->store->listBy(CredentialKind::KEY, $userId, null, true) as $row) {
            $session             = AccessEntry::key($row);
            $out[$session->id()] = $session;
        }

        return array_values($out);
    }

    /**
     * Whether the user has any live sign-in or key, in one short query.
     */
    public function hasAny(int $userId): bool
    {
        return $this->store->hasLiveFor($userId);
    }

    /**
     * End one of the user's sign-ins or revoke one of their keys.
     *
     * @param string $id an AccessEntry id
     *
     * @return bool false when the user has no such live session
     */
    public function end(int $userId, string $id): bool
    {
        foreach ($this->list($userId) as $session) {
            if ($session->id() !== $id) {
                continue;
            }
            if ($session->isKey()) {
                $this->store->revoke($session->row()->id());
            } else {
                $this->store->revokeFamily($id);
            }

            return true;
        }

        return false;
    }

    /**
     * Revoke every sign-in and key of the user; runs on `user_signout_all_after`, after
     * SignOut raised the stamp that ends their access and page tokens.
     *
     * @throws \mindstellar\database\DbException
     */
    public function endAll(int $userId): void
    {
        $this->store->revokeRefreshFor($userId);
        foreach ($this->store->listBy(CredentialKind::KEY, $userId, null, true) as $key) {
            $this->store->revoke($key->id());
        }
    }
}
