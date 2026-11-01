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
use mindstellar\boot\Boot;
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

    /** Sample listings added per installer request. */
    public const BATCH = 5;

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
     * How many batches the sample listings of a site type are added in.
     *
     * @param string $type 'classifieds' or 'shop'
     *
     * @return int
     */
    public function batches(string $type): int
    {
        return (int) ceil(count(self::rows($type)) / self::BATCH);
    }

    /**
     * Add all the sample data in one go, batch after batch. Already recorded parts are skipped.
     *
     * @param string      $type     'classifieds' or 'shop'
     * @param string|null $imageDir folder of photos named like the sample's 'image' keys; no photos when null
     *
     * @return array{listings: int, failed: int, errors: string[]}
     * @throws \InvalidArgumentException when the type is unknown
     */
    public function install(string $type, ?string $imageDir = null): array
    {
        $total = array('listings' => 0, 'failed' => 0, 'errors' => array());
        for ($batch = 0, $count = $this->batches($type); $batch < $count; $batch++) {
            $result             = $this->installBatch($type, $batch, $imageDir);
            $total['listings'] += $result['listings'];
            $total['failed']   += $result['failed'];
            $total['errors']    = array_merge($total['errors'], $result['errors']);
        }

        return $total;
    }

    /**
     * Add the sample fields and groups not yet recorded, then one batch of listings. Each id is
     * recorded as soon as it is made, so a request cut short resumes where it stopped.
     * Each step is non-fatal; what went wrong comes back in 'errors', and 'failed' counts unsaved listings.
     *
     * @param string      $type     'classifieds' or 'shop'
     * @param int         $batch    from 0 to batches() - 1
     * @param string|null $imageDir folder of photos named like the sample's 'image' keys; no photos when null
     *
     * @return array{listings: int, failed: int, errors: string[]}
     * @throws \InvalidArgumentException when the type is unknown
     */
    public function installBatch(string $type, int $batch, ?string $imageDir = null): array
    {
        // The installer does not boot the app, but saving a listing needs the core services.
        Boot::services();
        $rows    = self::rows($type);
        $errors  = $this->installFields($type);
        $locale  = osc_current_admin_locale();
        $fieldId = (array) osc_kv_get(self::GROUP, 'fields', array());
        $types   = array();
        foreach (require LIB_PATH . 'osclass/installer/data/fields-' . $type . '.php' as $group) {
            foreach ($group['fields'] as $field) {
                $types[$field['key']] = $field['type'];
            }
        }
        $done    = (array) osc_kv_get(self::GROUP, 'items', array());
        $made    = 0;
        $failed  = 0;
        $service = new ListingService();
        $actor   = Actor::admin(1, '127.0.0.1');
        foreach (array_slice($rows, $batch * self::BATCH, self::BATCH, true) as $index => $row) {
            if (isset($done[$index])) {
                continue;
            }
            $copy = null;
            try {
                $meta = array();
                foreach ($row['fields'] as $key => $value) {
                    if (isset($fieldId[$key], $types[$key])) {
                        $meta[(int) $fieldId[$key]] = $types[$key] === 'DATE' ? strtotime((string) $value) : $value;
                    }
                }
                $form = array(
                    'catId'        => $row['category'],
                    'title'        => array($locale => $row['title']),
                    'description'  => array($locale => $row['description']),
                    'price'        => $row['price'],
                    'currency'     => osc_currency(),
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
                $done[$index] = $saved->id();
                osc_kv_set(self::GROUP, 'items', $done);
                $made++;
            } catch (\Throwable $e) {
                $errors[] = $row['category'] . ': ' . $e->getMessage();
                $failed++;
            }
            if ($copy !== null && is_file($copy)) {
                unlink($copy);
            }
        }

        return array('listings' => $made, 'failed' => $failed, 'errors' => $errors);
    }

    /**
     * Add the sample field groups and fields that are not recorded yet, recording each id as it is made.
     *
     * @return string[] errors
     */
    private function installFields(string $type): array
    {
        $groups     = (array) osc_kv_get(self::GROUP, 'groups', array());
        $fieldIds   = (array) osc_kv_get(self::GROUP, 'fields', array());
        $errors     = array();
        $groupModel = \FieldGroup::getInstance();
        $service    = FieldService::make(osc_current_admin_locale());
        foreach (require LIB_PATH . 'osclass/installer/data/fields-' . $type . '.php' as $position => $group) {
            try {
                if (!isset($groups[$position])) {
                    $groupId = $groupModel->insertGroup($group['name'], '', $position);
                    if (!$groupId) {
                        throw new \RuntimeException('group');
                    }
                    $groups[$position] = (int) $groupId;
                    osc_kv_set(self::GROUP, 'groups', $groups);
                    $groupModel->insertCategories($groupId, $group['categories']);
                }
                foreach ($group['fields'] as $field) {
                    if (isset($fieldIds[$field['key']])) {
                        continue;
                    }
                    $id = $service->create(array(
                        'name'       => $field['name'],
                        'type'       => $field['type'],
                        'searchable' => $field['searchable'],
                        'options'    => $field['options'] ?? array(),
                    ));
                    $fieldIds[$field['key']] = $id;
                    osc_kv_set(self::GROUP, 'fields', $fieldIds);
                    $groupModel->setFieldSingleGroup($id, $groups[$position]);
                }
            } catch (\Throwable $e) {
                $errors[] = 'fields: ' . $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * The sample listings of a site type.
     *
     * @return array<int, array<string, mixed>>
     * @throws \InvalidArgumentException when the type is unknown
     */
    private static function rows(string $type): array
    {
        if (!SiteMode::isValid($type)) {
            throw new \InvalidArgumentException('Unknown site type: ' . $type);
        }

        return require LIB_PATH . 'osclass/installer/data/sample-' . $type . '.php';
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
