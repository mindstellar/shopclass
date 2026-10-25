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
 * The sample listings, custom fields and groups the installer can add. Their ids are recorded
 * in the key-value store so they can be removed later. Categories and the example page stay.
 *
 * @package mindstellar\install
 */
final class SampleData
{
    /** The key-value group that records what was added. */
    public const GROUP = 'sample';

    /** Every sample listing has this contact e-mail. */
    public const SELLER_EMAIL = 'seller@example.com';

    /**
     * Whether anything the installer added is still recorded. One query.
     *
     * @return bool
     */
    public function present(): bool
    {
        return osc_kv_list(self::GROUP, 1) !== array();
    }

    /**
     * Ids of the recorded sample listings that still exist and are still samples.
     *
     * @return int[]
     */
    public function installed(): array
    {
        $found = array();
        foreach ($this->recorded('items') as $id) {
            $item = \Item::getInstance()->findByPrimaryKey($id);
            if ($item && ($item['s_contact_email'] ?? '') === self::SELLER_EMAIL) {
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
        if (osc_kv_get(self::GROUP, 'items') !== null) {
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
                    'contactEmail' => self::SELLER_EMAIL,
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

        osc_kv_set(self::GROUP, 'items', $itemIds);
        osc_kv_set(self::GROUP, 'fields', $fieldIds);
        osc_kv_set(self::GROUP, 'groups', $groupIds);

        return array('listings' => count($itemIds), 'fields' => count($fieldIds), 'errors' => $errors);
    }

    /**
     * Delete the sample listings (with their photos), fields and groups, then forget them.
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
        osc_kv_delete_group(self::GROUP);

        return $removed;
    }

    /**
     * @return int[]
     */
    private function recorded(string $key): array
    {
        $ids = osc_kv_get(self::GROUP, $key, array());

        return is_array($ids) ? array_values(array_map('intval', $ids)) : array();
    }
}
