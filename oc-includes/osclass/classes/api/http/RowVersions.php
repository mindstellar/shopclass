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

namespace mindstellar\api\http;

use mindstellar\admin\ExposedSettings;
use mindstellar\admin\form\store\PreferenceStore;
use mindstellar\apikey\Credential;
use mindstellar\database\Db;
use mindstellar\database\RowHashQuery;
use mindstellar\security\SigningKey;
use mindstellar\settings\SettingsPageRegistry;
use mindstellar\webhook\WebhookEndpointStore;

/**
 * Versions read from the stored rows: a keyed hash of the resource's row and its own child
 * rows (a listing's descriptions, location, field values and photos, say), read in one query. Columns
 * that change without anyone editing the resource, such as a user's last access, are left out.
 * A modified stamp alone would not do: dt_mod_date moves in whole seconds, and status, premium
 * and expiry writes leave it alone.
 */
final class RowVersions implements ResourceVersions
{
    /** Listing photos among the rows of t_resource. */
    private const PHOTO = "s_owner_type = '" . \ItemResource::OWNER . "'";

    private const LISTING = ['arg' => 'id', 'owner' => 'fk_i_user_id', 'tables' => [
        ['t_item', 'pk_i_id'], ['t_item_description', 'fk_i_item_id'], ['t_item_location', 'fk_i_item_id'], ['t_item_meta', 'fk_i_item_id'],
        ['t_resource', 'i_owner_id', self::PHOTO],
    ]];

    private const COMMENT = ['arg' => 'id', 'owner' => 'fk_i_user_id', 'tables' => [['t_item_comment', 'pk_i_id']]];

    private const KEY = ['arg' => 'id', 'owner' => 'fk_i_user_id', 'tables' => [['t_api_credential', 'pk_i_id']]];

    private const USER_TABLES = [['t_user', 'pk_i_id'], ['t_user_description', 'fk_i_user_id']];

    /**
     * GET path => the argument holding the key (null: the credential's user), the tables, and
     * for a resource users own, the column in the first table naming its owner.
     */
    private const RESOURCES = [
        'listings/{id}'                 => self::LISTING,
        'admin/listings/{id}'           => self::LISTING,
        'listings/{id}/photos/{photo}'  => ['arg' => 'photo', 'tables' => [['t_resource', 'pk_i_id', self::PHOTO]]],
        'comments/{id}'                 => self::COMMENT,
        'admin/comments/{id}'           => self::COMMENT,
        'account'                       => ['arg' => null, 'tables' => self::USER_TABLES],
        'admin/users/{id}'              => ['arg' => 'id', 'tables' => self::USER_TABLES],
        'account/alerts/{id}'           => ['arg' => 'id', 'owner' => 'fk_i_user_id', 'tables' => [['t_alerts', 'pk_i_id']]],
        'account/keys/{id}'             => self::KEY,
        'admin/keys/{id}'               => self::KEY,
        'admin/categories/{id}'         => ['arg' => 'id', 'tables' => [['t_category', 'pk_i_id'], ['t_category_description', 'fk_i_category_id']]],
        'admin/custom-fields/{id}'      => ['arg' => 'id', 'tables' => [['t_meta_fields', 'pk_i_id'], ['t_meta_categories', 'fk_i_field_id']]],
        'admin/currencies/{code}'       => ['arg' => 'code', 'tables' => [['t_currency', 'pk_c_code']]],
        'admin/regions/{id}'            => ['arg' => 'id', 'tables' => [['t_region', 'pk_i_id']]],
        'admin/cities/{id}'             => ['arg' => 'id', 'tables' => [['t_city', 'pk_i_id']]],
        'admin/areas/{id}'              => ['arg' => 'id', 'tables' => [['t_city_area', 'pk_i_id']]],
    ];

    /**
     * Resources stored as rows named by a compound key: the exposed settings' preferences, and a
     * webhook endpoint's key-value row.
     */
    private const KEYED = ['admin/settings', 'admin/webhooks/{webhook}'];

    /** The columns each version hashes, and those left out; see RowHashQuery. */
    public const COLUMNS = RowHashQuery::COLUMNS;

    public const IGNORED = RowHashQuery::IGNORED;

    public function supports(string $path): bool
    {
        return isset(self::RESOURCES[$path]) || in_array($path, self::KEYED, true);
    }

    public function version(string $path, array $args, Credential $credential, bool $lock = false, bool $ownerOnly = false): ?string
    {
        if ($path === 'admin/settings') {
            // Always there: a setting never saved reads as its default.
            return self::sign([RowHashQuery::keyedHashes('t_preference', self::settingKeys(), $lock)]);
        }
        if ($path === 'admin/webhooks/{webhook}') {
            $rows = WebhookEndpointStore::rowHashes((string) ($args['webhook'] ?? ''), $lock);

            return $rows === [] ? null : self::sign([$rows]);
        }
        $resource = self::RESOURCES[$path] ?? null;
        if ($resource === null) {
            return null;
        }
        $key = $resource['arg'] === null ? $credential->userId() : ($args[$resource['arg']] ?? null);
        if ($key === null || $key === '') {
            return null;
        }
        $owner = $resource['owner'] ?? null;
        $found = RowHashQuery::hashes($resource['tables'], $key, $owner, $lock, $credential->isAdmin() ? null : (int) $credential->userId());
        $rows  = array_fill(0, count($resource['tables']), []);
        $head  = null;
        foreach ($found as $row) {
            $rows[(int) $row['t']][] = (string) $row['r'];
            if ((int) $row['t'] === 0) {
                $head = $row;
            }
        }
        if ($head === null) {
            return null;
        }
        // A locked read is for a write, so another user's row never gives a version to match.
        if (($ownerOnly || $lock) && $owner !== null && !$credential->isAdmin() && (int) ($head['o'] ?? 0) !== (int) $credential->userId()) {
            return null;
        }
        foreach ($rows as &$hashes) {
            sort($hashes);
        }
        unset($hashes);

        return self::sign($rows);
    }

    /**
     * @param array<int,string[]> $rows each table's sorted row hashes
     */
    private static function sign(array $rows): string
    {
        return substr(hash_hmac('sha256', (string) json_encode($rows), 'resource-version|' . SigningKey::get()), 0, 24);
    }

    /**
     * The preference rows behind ExposedSettings, as the settings pages store them.
     *
     * @return array<int,array{s_section:string,s_name:string}>
     */
    private static function settingKeys(): array
    {
        $keys = [];
        foreach (ExposedSettings::FIELDS as [$form, $field]) {
            $page    = $form::register();
            $section = (string) (osc_settings_page($page)['section'] ?? '');
            $spec    = SettingsPageRegistry::getInstance()->fields($page)[$field] ?? [];
            $name    = PreferenceStore::key($field, $spec);
            foreach (osc_settings_field_locales($spec) ?: ['' => ''] as $code => $unused) {
                $keys[] = ['s_section' => $section, 's_name' => $name . $code];
            }
        }

        return $keys;
    }

    public function atomically(callable $fn): mixed
    {
        return Db::transaction($fn);
    }
}
