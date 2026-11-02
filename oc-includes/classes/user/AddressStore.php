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

namespace mindstellar\user;

use mindstellar\model\KeyValue;

/**
 * Address book rows. The default address is the account's own address columns on t_user; the
 * others are one t_key_value row per user (group `addresses`, key the user id, no expiry) holding
 * `{"default": {id, label, name} | null, "list": [address, ...]}`. AddressBook holds the rules.
 */
final class AddressStore
{
    public const GROUP = 'addresses';

    private KeyValue $kv;

    public function __construct(?KeyValue $kv = null)
    {
        $this->kv = $kv ?? new KeyValue();
    }

    /**
     * The stored row as written: the default's id, label and name, and the other addresses.
     *
     * @return array{default:array<string,mixed>|null,list:array<int,mixed>}
     * @throws \mindstellar\database\DbException
     */
    public function read(int $userId): array
    {
        $row  = $userId > 0 ? $this->kv->get(self::GROUP, (string) $userId) : null;
        $data = $row === null || $row['value'] === null ? null : json_decode($row['value'], true);
        $data = is_array($data) ? $data : array();

        return array(
            'default' => is_array($data['default'] ?? null) ? $data['default'] : null,
            'list'    => is_array($data['list'] ?? null) ? array_values($data['list']) : array(),
        );
    }

    /**
     * Write the row; nothing to keep removes it.
     *
     * @param array<string,string>|null       $default
     * @param array<int,array<string,string>> $list
     *
     * @throws \mindstellar\database\DbException
     */
    public function save(int $userId, ?array $default, array $list): void
    {
        if ($userId <= 0) {
            return;
        }
        if ($default === null && $list === array()) {
            $this->delete($userId);

            return;
        }
        $this->kv->set(self::GROUP, (string) $userId, (string) json_encode(array('default' => $default, 'list' => array_values($list)), JSON_UNESCAPED_UNICODE));
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function delete(int $userId): void
    {
        if ($userId > 0) {
            $this->kv->delete(self::GROUP, (string) $userId);
        }
    }
}
