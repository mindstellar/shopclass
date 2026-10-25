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
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\site\SiteMode;

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
     * Add the sample custom fields and listings for a site type, and record their ids so they can be removed.
     * Each step is non-fatal; what went wrong comes back in 'errors'.
     *
     * @param string      $type     'classifieds' or 'shop'
     * @param string|null $imageDir folder of photos named like the sample's 'image' keys; no photos when null
     *
     * @return array{listings: int, fields: int, errors: string[]}
     * @throws \InvalidArgumentException when the type is unknown
     * @throws \RuntimeException when sample data is already installed
     */
    public function install(string $type, ?string $imageDir = null): array
    {
        if (!SiteMode::isValid($type)) {
            throw new \InvalidArgumentException('Unknown site type: ' . $type);
        }
        if (osc_kv_get('sample', 'items') !== null) {
            throw new \RuntimeException('Sample data is already installed.');
        }

        $locale    = osc_current_admin_locale();
        $errors    = array();
        $fieldId   = array();
        $fieldType = array();
        $fieldIds  = array();
        $groupIds  = array();
        $itemIds   = array();

        $groupModel = \FieldGroup::getInstance();
        $fields     = FieldService::make($locale);
        foreach (require LIB_PATH . 'osclass/installer/data/fields-' . $type . '.php' as $position => $group) {
            try {
                $groupId = $groupModel->insertGroup($group['name'], '', $position);
                if (!$groupId) {
                    throw new \RuntimeException('group');
                }
                $groupIds[] = (int) $groupId;
                $groupModel->insertCategories($groupId, $group['categories']);
                foreach ($group['fields'] as $field) {
                    $id = $fields->create(array(
                        'name'       => $field['name'],
                        'type'       => $field['type'],
                        'searchable' => $field['searchable'],
                        'options'    => $field['options'] ?? array(),
                    ));
                    $groupModel->setFieldSingleGroup($id, $groupId);
                    $fieldId[$field['key']]   = $id;
                    $fieldIds[]               = $id;
                    $fieldType[$field['key']] = $field['type'];
                }
            } catch (\Throwable $e) {
                $errors[] = 'fields: ' . $e->getMessage();
            }
        }

        $service = new ListingService();
        $actor   = Actor::admin(1, '127.0.0.1');
        foreach (require LIB_PATH . 'osclass/installer/data/sample-' . $type . '.php' as $row) {
            $copy = null;
            try {
                $meta = array();
                foreach ($row['fields'] as $key => $value) {
                    if (isset($fieldId[$key])) {
                        $meta[$fieldId[$key]] = $fieldType[$key] === 'DATE' ? strtotime((string) $value) : $value;
                    }
                }
                $form = array(
                    'catId'        => $row['category'],
                    'title'        => array($locale => $row['title']),
                    'description'  => array($locale => $row['description']),
                    'price'        => $row['price'],
                    'contactName'  => __('Example seller'),
                    'contactEmail' => 'seller@example.com',
                    'meta'         => $meta,
                );
                if (isset($row['variants'])) {
                    $form['product'] = array('sell' => 1, 'hasOptions' => 1, 'variants' => $row['variants']);
                } elseif (isset($row['stock'])) {
                    $form['product'] = array('sell' => 1, 'stock' => $row['stock']);
                }
                // A photo is moved into the listing and deleted from where it was, so give it a copy.
                $source = $imageDir . $row['image'] . '.webp';
                if ($imageDir !== null && is_file($source)) {
                    $copy = tempnam(sys_get_temp_dir(), 'sample');
                    copy($source, $copy);
                    $form['photos'] = array($copy);
                }
                $save = static fn (array $form) => $service->create(ListingInput::fromArray($form, $actor, true), $actor, true);
                try {
                    $saved = $save($form);
                } catch (\Throwable $e) {
                    // A photo the server refuses must not cost the listing.
                    if (!isset($form['photos'])) {
                        throw $e;
                    }
                    $errors[] = $row['image'] . ': ' . $e->getMessage();
                    unset($form['photos']);
                    $saved = $save($form);
                }
                $itemIds[] = $saved->id();
            } catch (\Throwable $e) {
                $errors[] = $row['category'] . ': ' . $e->getMessage();
            }
            if ($copy !== null && is_file($copy)) {
                unlink($copy);
            }
        }

        osc_kv_set('sample', 'items', $itemIds);
        osc_kv_set('sample', 'fields', $fieldIds);
        osc_kv_set('sample', 'groups', $groupIds);

        return array('listings' => count($itemIds), 'fields' => count($fieldIds), 'errors' => $errors);
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
