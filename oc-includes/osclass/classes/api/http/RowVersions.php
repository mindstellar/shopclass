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

use mindstellar\apiaccess\Credential;
use mindstellar\database\Connection;
use mindstellar\database\Db;
use mindstellar\security\SigningKey;

/**
 * Versions read from the stored rows: a keyed hash of the resource's row and its own child
 * rows (a listing's descriptions, location and field values, say), read in one query. Columns
 * that change without anyone editing the resource, such as a user's last access, are left out.
 */
final class RowVersions implements ResourceVersions
{
    private const LISTING = ['arg' => 'id', 'owner' => 'fk_i_user_id', 'tables' => [
        ['t_item', 'pk_i_id'], ['t_item_description', 'fk_i_item_id'], ['t_item_location', 'fk_i_item_id'], ['t_item_meta', 'fk_i_item_id'],
    ]];

    private const COMMENT = ['arg' => 'id', 'owner' => 'fk_i_user_id', 'tables' => [['t_item_comment', 'pk_i_id']]];

    private const KEY = ['arg' => 'id', 'tables' => [['t_api_credential', 'pk_i_id']]];

    private const USER_TABLES = [['t_user', 'pk_i_id'], ['t_user_description', 'fk_i_user_id']];

    /**
     * GET path => the argument holding the key (null: the credential's user), the tables, and
     * for a resource users own, the column in the first table naming its owner.
     */
    private const RESOURCES = [
        'listings/{id}'                 => self::LISTING,
        'admin/listings/{id}'           => self::LISTING,
        'listings/{id}/photos/{photo}'  => ['arg' => 'photo', 'tables' => [['t_item_resource', 'pk_i_id']]],
        'comments/{id}'                 => self::COMMENT,
        'admin/comments/{id}'           => self::COMMENT,
        'account'                       => ['arg' => null, 'tables' => self::USER_TABLES],
        'admin/users/{id}'              => ['arg' => 'id', 'tables' => self::USER_TABLES],
        'account/alerts/{id}'           => ['arg' => 'id', 'tables' => [['t_alerts', 'pk_i_id']]],
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
     * The columns each version hashes: every column of the table except IGNORED. A model test
     * pins this against the live tables, so a new column cannot slip out of the version.
     */
    public const COLUMNS = [
        't_item'                 => ['pk_i_id', 'fk_i_user_id', 'fk_i_category_id', 'dt_pub_date', 'dt_first_pub_date', 'dt_mod_date', 'f_price', 'i_price',
            'fk_c_currency_code', 's_contact_name', 's_contact_email', 's_contact_phone', 's_ip', 'b_premium', 'dt_premium_expiration', 'b_enabled',
            'b_active', 'b_spam', 's_secret', 'b_show_email', 'dt_expiration'],
        't_item_description'     => ['fk_i_item_id', 'fk_c_locale_code', 's_title', 's_description'],
        't_item_location'        => ['fk_i_item_id', 'fk_c_country_code', 's_country', 's_address', 's_zip', 'fk_i_region_id', 's_region', 'fk_i_city_id',
            's_city', 'fk_i_city_area_id', 's_city_area', 'd_coord_lat', 'd_coord_long'],
        't_item_meta'            => ['fk_i_item_id', 'fk_i_field_id', 's_value', 's_multi'],
        't_item_resource'        => ['pk_i_id', 'fk_i_item_id', 's_name', 's_extension', 's_content_type', 's_path', 's_storage'],
        't_item_comment'         => ['pk_i_id', 'fk_i_item_id', 'dt_pub_date', 's_title', 's_author_name', 's_author_email', 's_body', 'b_enabled', 'b_active',
            'b_spam', 'fk_i_user_id'],
        't_api_credential'       => ['pk_i_id', 'e_kind', 's_token_id', 's_secret_hash', 's_name', 's_scopes', 'fk_i_user_id', 'fk_i_admin_id', 's_family',
            'i_rate_limit', 'b_enabled', 'dt_expires', 'dt_revoked', 'dt_created', 'i_auth_stamp'],
        't_user'                 => ['pk_i_id', 'dt_reg_date', 'dt_mod_date', 's_name', 's_username', 's_password', 's_secret', 's_email', 's_website',
            's_phone_land', 's_phone_mobile', 'b_enabled', 'b_active', 'fk_c_country_code', 's_country', 's_address', 's_zip', 'fk_i_region_id',
            's_region', 'fk_i_city_id', 's_city', 'fk_i_city_area_id', 's_city_area', 'd_coord_lat', 'd_coord_long', 'b_company'],
        't_user_description'     => ['fk_i_user_id', 'fk_c_locale_code', 's_info'],
        't_alerts'               => ['pk_i_id', 's_email', 'fk_i_user_id', 's_search', 's_secret', 'b_active', 'e_type', 'dt_date', 'dt_unsub_date'],
        't_category'             => ['pk_i_id', 'fk_i_parent_id', 'i_expiration_days', 'i_position', 'b_enabled', 'b_price_enabled', 's_icon'],
        't_category_description' => ['fk_i_category_id', 'fk_c_locale_code', 's_name', 's_description', 's_slug'],
        't_meta_fields'          => ['pk_i_id', 's_name', 's_slug', 'e_type', 's_options', 'b_required', 'b_searchable', 's_meta', 'i_position', 'fk_i_group_id'],
        't_meta_categories'      => ['fk_i_category_id', 'fk_i_field_id'],
        't_currency'             => ['pk_c_code', 's_name', 's_description', 'b_enabled'],
        't_region'               => ['pk_i_id', 'fk_c_country_code', 's_name', 's_slug', 'b_active', 'i_source_id', 'd_coord_lat', 'd_coord_long'],
        't_city'                 => ['pk_i_id', 'fk_i_region_id', 's_name', 's_slug', 'fk_c_country_code', 'b_active', 'i_source_id', 'd_coord_lat', 'd_coord_long'],
        't_city_area'            => ['pk_i_id', 'fk_i_city_id', 's_name'],
    ];

    /** Columns that are not part of the resource's version. */
    public const IGNORED = [
        't_user'           => ['dt_access_date', 's_access_ip', 'i_items', 'i_comments', 's_pass_code', 's_pass_date', 's_pass_ip', 'i_auth_stamp'],
        't_api_credential' => ['dt_last_used', 's_last_ip'],
    ];

    public function supports(string $path): bool
    {
        return isset(self::RESOURCES[$path]);
    }

    public function version(string $path, array $args, Credential $credential, bool $lock = false, bool $ownerOnly = false): ?string
    {
        $resource = self::RESOURCES[$path] ?? null;
        if ($resource === null) {
            return null;
        }
        $key = $resource['arg'] === null ? $credential->userId() : ($args[$resource['arg']] ?? null);
        if ($key === null || $key === '') {
            return null;
        }
        $owner   = $resource['owner'] ?? null;
        $selects = [];
        foreach ($resource['tables'] as $i => [$table, $column]) {
            $selects[] = 'SELECT ' . $i . ' AS t, SHA2(CONCAT_WS(\',\', '
                . implode(', ', array_map(static fn (string $c): string => 'QUOTE(CAST(' . $c . ' AS BINARY))', self::COLUMNS[$table]))
                . '), 256) AS r, ' . ($i === 0 && $owner !== null ? $owner : 'NULL') . ' AS o FROM ' . self::table($table)
                . ' WHERE ' . $column . ' = ?';
        }
        $db = Connection::getInstance();
        // Locking reads run one table at a time: a lock inside UNION is not portable across MySQL and MariaDB.
        $found = $lock
            ? array_merge(...array_map(static fn (string $sql): array => $db->select($sql . ' FOR UPDATE', [$key]), $selects))
            : $db->select(implode(' UNION ALL ', $selects), array_fill(0, count($selects), $key));

        $rows = array_fill(0, count($selects), []);
        $head = null;
        foreach ($found as $row) {
            $rows[(int) $row['t']][] = (string) $row['r'];
            if ((int) $row['t'] === 0) {
                $head = $row;
            }
        }
        if ($head === null) {
            return null;
        }
        if ($ownerOnly && $owner !== null && !$credential->isAdmin() && (int) ($head['o'] ?? 0) !== (int) $credential->userId()) {
            return null;
        }
        foreach ($rows as &$hashes) {
            sort($hashes);
        }
        unset($hashes);

        return substr(hash_hmac('sha256', (string) json_encode($rows), 'resource-version|' . SigningKey::get()), 0, 24);
    }

    private static function table(string $table): string
    {
        return DB_TABLE_PREFIX . $table;
    }

    public function atomically(callable $fn): mixed
    {
        return Db::transaction($fn);
    }
}
