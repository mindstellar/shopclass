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

/**
 * The categories each plugin is limited to: one list of category ids per plugin, in the
 * `plugin_categories` group of t_key_value. No list means the admin chose none.
 *
 * @package    Shopclass
 * @subpackage Model
 */
class PluginCategory
{
    public const KV_GROUP = 'plugin_categories';

    /** @var PluginCategory|null */
    private static $instance;

    /** @var array<string,int[]> lists read this request, by plugin */
    private static array $lists = array();

    /**
     * @return \PluginCategory
     * @deprecated 7.0.0 Use new PluginCategory().
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * The plugins limited to a category, as the old table rows.
     *
     * @param int $categoryId
     *
     * @return array<int,array<string,string>> s_plugin_name and fk_i_category_id per plugin
     */
    public function findByCategoryId($categoryId)
    {
        $rows = array();
        foreach ($this->all() as $plugin => $ids) {
            if (in_array((int) $categoryId, $ids, true)) {
                $rows[] = array('s_plugin_name' => $plugin, 'fk_i_category_id' => (string) (int) $categoryId);
            }
        }

        return $rows;
    }

    /**
     * The categories a plugin is limited to.
     *
     * @param string $plugin
     *
     * @return array<int,string> category ids
     */
    public function listSelected($plugin)
    {
        return array_map('strval', $this->ids((string) $plugin));
    }

    /**
     * Whether a plugin is on for a category.
     *
     * @param string $pluginName
     * @param int    $categoryId
     *
     * @return bool
     */
    public function isThisCategory($pluginName, $categoryId)
    {
        return in_array((int) $categoryId, $this->ids((string) $pluginName), true);
    }

    /**
     * Add categories to a plugin's list.
     *
     * @param string $plugin
     * @param int[]  $categoryIds
     */
    public function add($plugin, array $categoryIds): void
    {
        $plugin = (string) $plugin;
        $this->save($plugin, array_merge($this->ids($plugin), array_map('intval', $categoryIds)));
    }

    /**
     * Forget a plugin's list, as when it is uninstalled or its categories are reset.
     *
     * @param string $plugin
     */
    public function clear($plugin): void
    {
        unset(self::$lists[(string) $plugin]);
        osc_kv_delete(self::KV_GROUP, (string) $plugin);
    }

    /**
     * Take a deleted category out of every plugin's list.
     *
     * @param int $categoryId
     */
    public function removeCategory($categoryId): void
    {
        foreach ($this->all() as $plugin => $ids) {
            if (in_array((int) $categoryId, $ids, true)) {
                $this->save($plugin, array_diff($ids, array((int) $categoryId)));
            }
        }
    }

    /**
     * The old table insert: one plugin and category pair.
     *
     * @param array<string,mixed> $values s_plugin_name and fk_i_category_id
     *
     * @return bool
     * @deprecated 7.0.0 Use add().
     */
    public function insert($values)
    {
        if (!isset($values['s_plugin_name'], $values['fk_i_category_id'])) {
            return false;
        }
        $this->add((string) $values['s_plugin_name'], array((int) $values['fk_i_category_id']));

        return true;
    }

    /**
     * The old table delete, by plugin or by category.
     *
     * @param array<string,mixed> $where s_plugin_name or fk_i_category_id
     *
     * @return bool
     * @deprecated 7.0.0 Use clear() or removeCategory().
     */
    public function delete($where)
    {
        if (isset($where['s_plugin_name'], $where['fk_i_category_id'])) {
            $plugin = (string) $where['s_plugin_name'];
            $this->save($plugin, array_diff($this->ids($plugin), array((int) $where['fk_i_category_id'])));

            return true;
        }
        if (isset($where['s_plugin_name'])) {
            $this->clear((string) $where['s_plugin_name']);

            return true;
        }
        if (isset($where['fk_i_category_id'])) {
            $this->removeCategory((int) $where['fk_i_category_id']);

            return true;
        }

        return false;
    }

    /**
     * Every plugin and category pair, as the old table rows.
     *
     * @return array<int,array<string,string>>
     * @deprecated 7.0.0 Use listSelected() or findByCategoryId().
     */
    public function listAll()
    {
        $rows = array();
        foreach ($this->all() as $plugin => $ids) {
            foreach ($ids as $id) {
                $rows[] = array('s_plugin_name' => (string) $plugin, 'fk_i_category_id' => (string) $id);
            }
        }

        return $rows;
    }

    /**
     * @return int[]
     */
    private function ids(string $plugin): array
    {
        if ($plugin === '') {
            return array();
        }
        if (!isset(self::$lists[$plugin])) {
            self::$lists[$plugin] = self::decode(osc_kv_get(self::KV_GROUP, $plugin, array()));
        }

        return self::$lists[$plugin];
    }

    /**
     * @return array<string,int[]> every plugin's list
     */
    private function all(): array
    {
        $all = array();
        foreach ((new \mindstellar\model\KeyValue())->group(self::KV_GROUP) as $key => $row) {
            $all[$key] = self::decode(json_decode((string) ($row['value'] ?? ''), true));
        }

        return $all;
    }

    /**
     * @param mixed $stored a decoded list
     *
     * @return int[]
     */
    private static function decode($stored): array
    {
        return is_array($stored) ? array_map('intval', $stored) : array();
    }

    /**
     * @param int[] $ids
     */
    private function save(string $plugin, array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        sort($ids);
        self::$lists[$plugin] = $ids;
        if ($ids === array()) {
            osc_kv_delete(self::KV_GROUP, $plugin);

            return;
        }
        osc_kv_set(self::KV_GROUP, $plugin, $ids);
    }
}
