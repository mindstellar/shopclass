<?php

declare(strict_types=1);

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\install;

use mindstellar\auth\Actor;
use mindstellar\fields\FieldService;
use mindstellar\listing\ListingService;

/**
 * The sample listings, custom fields and groups the installer added. It reads the ids the
 * installer recorded and removes them. Categories stay.
 *
 * @package mindstellar\install
 */
final class SampleData
{
    /**
     * Ids of the recorded sample listings that still exist.
     *
     * @return int[]
     */
    public function installed(): array
    {
        $items = \Item::getInstance();
        $found = array();
        foreach ($this->recorded('items') as $id) {
            if ($items->findByPrimaryKey($id)) {
                $found[] = $id;
            }
        }

        return $found;
    }

    /**
     * Delete the sample listings (with their photos), fields, groups and example page, then forget them.
     *
     * @return array{listings: int, fields: int, groups: int}
     */
    public function remove(Actor $actor): array
    {
        $listings = new ListingService();
        $removed  = array('listings' => 0, 'fields' => 0, 'groups' => 0);
        foreach ($this->installed() as $id) {
            $item = \Item::getInstance()->findByPrimaryKey($id);
            if ($listings->delete($id, (string) $item['s_secret'], $actor) !== false) {
                $removed['listings']++;
            }
        }
        $fields = FieldService::make(osc_current_admin_locale());
        foreach ($this->recorded('fields') as $id) {
            try {
                $fields->delete($id);
                $removed['fields']++;
            } catch (\Throwable $e) {
                // Already gone, or removed by hand.
                continue;
            }
        }
        foreach ($this->recorded('groups') as $id) {
            if (\FieldGroup::getInstance()->deleteByPrimaryKey($id)) {
                $removed['groups']++;
            }
        }
        foreach ($this->recorded('page') as $id) {
            \Page::getInstance()->deleteByPrimaryKey($id);
        }
        osc_kv_delete_group('sample');

        return $removed;
    }

    /**
     * @return int[]
     */
    private function recorded(string $key): array
    {
        $ids = osc_kv_get('sample', $key, array());

        return is_array($ids) ? array_values(array_map('intval', $ids)) : array();
    }
}
