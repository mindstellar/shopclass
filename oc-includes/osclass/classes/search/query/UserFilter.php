<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\search\query;

/**
 * The seller filter: listings of one user or of any of several, by id or username.
 */
final class UserFilter
{
    private bool $active = false;
    /** @var array<int,int>|int|null */
    private $ids = null;
    /** @var array<int,string>|int|string|null the value toJson() records */
    private $recorded = null;

    /**
     * An unknown username in a list is skipped; on its own it leaves the filter as it was.
     *
     * @param mixed $id
     *
     * @return void
     */
    public function from($id): void
    {
        $this->active = true;
        $column       = DB_TABLE_PREFIX . 't_item.fk_i_user_id = %d ';
        if (is_array($id)) {
            $ids  = array();
            $text = array();
            foreach ($id as $one) {
                if (!is_numeric($one)) {
                    $user = \User::getInstance()->findByUsername($one);
                    if (!isset($user['pk_i_id'])) {
                        continue;
                    }
                    $one = SqlValue::intOf($user['pk_i_id']);
                } else {
                    $one = (int)$one;
                }
                $ids[]  = $one;
                $text[] = sprintf($column, $one);
            }
            $this->ids      = $ids;
            $this->recorded = $text;

            return;
        }
        if (!is_numeric($id)) {
            $user = \User::getInstance()->findByUsername($id);
            if (!isset($user['pk_i_id'])) {
                return;
            }
            $id = $user['pk_i_id'];
        }
        $this->recorded = SqlValue::literal($id);
        $this->ids      = SqlValue::intOf($id);
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->active   = false;
        $this->ids      = null;
        $this->recorded = null;
    }

    /**
     * @return array<int,string>|int|string|null
     */
    public function recorded()
    {
        return $this->recorded;
    }

    /**
     * Join t_user and add the user condition.
     *
     * @param Statement $statement
     *
     * @return void
     */
    public function apply(Statement $statement): void
    {
        if (!$this->active) {
            return;
        }
        $p = DB_TABLE_PREFIX;
        $statement->join($p . 't_user', $p . 't_user.pk_i_id = ' . $p . 't_item.fk_i_user_id', 'LEFT');
        if (is_array($this->ids)) {
            $statement->where(
                $this->ids === array()
                    ? '1 = 0'
                    : ' ( ' . implode(' || ', array_fill(0, count($this->ids), $p . 't_item.fk_i_user_id = ? ')) . ' ) ',
                $this->ids
            );
        } else {
            $statement->where($p . 't_item.fk_i_user_id = ? ', array((int)$this->ids));
        }
    }
}
