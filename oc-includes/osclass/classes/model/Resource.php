<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\model;

use mindstellar\database\QueryBuilder;
use Throwable;

/**
 * Model for the polymorphic t_resource table.
 *
 * A resource row belongs to an owner identified by the (s_owner_type, i_owner_id)
 * pair rather than a foreign key, so items, users, pages and plugin-defined owner
 * types all share one table and one storage pipeline. Column names and types
 * mirror t_item_resource so the storage layer's row-shape assumptions (s_path,
 * s_storage, variant naming) carry over unchanged.
 *
 * Queries run through the parameterized osc_db_* / QueryBuilder API (bound
 * placeholders, no string-built SQL); it does not extend the legacy DAO layer.
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      5.3.0
 */
class Resource
{
    /** Owner type: a classified listing (t_item). */
    public const OWNER_ITEM = 'item';

    /** Owner type: a registered user (t_user). */
    public const OWNER_USER = 'user';

    /** Owner type: a static page (t_pages). */
    public const OWNER_PAGE = 'page';

    /** Unprefixed table name. */
    private const TABLE = 't_resource';

    /** Every column on t_resource, in schema order. */
    private const COLUMNS = array(
        'pk_i_id',
        's_owner_type',
        'i_owner_id',
        's_name',
        's_extension',
        's_content_type',
        's_path',
        's_storage',
        'dt_created',
        'dt_updated',
    );

    /**
     * It references to self object: Resource.
     * It is used as a singleton.
     *
     * @var Resource
     */
    private static $instance;

    /**
     * It creates a new Resource object class if it has not been created before,
     * otherwise it returns the previous object.
     *
     * @return Resource
     */
    public static function newInstance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Whether $type is a syntactically valid owner type. Plugins may mint their
     * own owner types, so this validates the slug shape rather than a fixed list.
     *
     * @param string $type
     *
     * @return bool
     */
    public static function isValidOwnerType(string $type): bool
    {
        return preg_match('/^[a-z0-9_-]{1,20}$/', $type) === 1;
    }

    /**
     * All resources belonging to a given owner, ordered by pk_i_id.
     *
     * Cached under the same md5 key pattern as ItemResource::getAllResourcesFromItem
     * so cache behaviour is familiar and can be primed by primeOwnerCache().
     *
     * @param string $ownerType
     * @param int    $ownerId
     *
     * @return array of resources
     */
    public function findByOwner(string $ownerType, int $ownerId): array
    {
        if (!self::isValidOwnerType($ownerType)) {
            return array();
        }

        $key   = $this->ownerCacheKey($ownerType, $ownerId);
        $found = null;
        $cache = osc_cache_get($key, $found);
        if ($cache === false) {
            $rows = $this->table()
                ->where('s_owner_type', $ownerType)
                ->where('i_owner_id', $ownerId)
                ->orderBy('pk_i_id', 'ASC')
                ->get();

            osc_cache_set($key, $rows, OSC_CACHE_TTL);

            return $rows;
        }

        return $cache;
    }

    /**
     * A single resource row by primary key, read fresh (uncached) so callers that
     * need to confirm a row still exists — e.g. the storage worker checking whether
     * a resource was deleted while an offload was in flight — do not race the cache.
     *
     * @param int $id
     *
     * @return array|null the row, or null when absent
     */
    public function findByPrimaryKey(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $row = $this->table()->where('pk_i_id', $id)->first();
        } catch (Throwable $e) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Count resources for an owner type, optionally narrowed to a single owner id.
     *
     * @param string   $ownerType
     * @param int|null $ownerId
     *
     * @return int
     */
    public function countByOwner(string $ownerType, ?int $ownerId = null): int
    {
        if (!self::isValidOwnerType($ownerType)) {
            return 0;
        }

        $query = $this->table()->where('s_owner_type', $ownerType);
        if ($ownerId !== null) {
            $query = $query->where('i_owner_id', $ownerId);
        }

        return $query->count();
    }

    /**
     * Insert a resource row for an owner. $data may carry any of the resource
     * columns (s_name, s_extension, s_content_type, s_path, s_storage); the owner
     * pair and dt_created are set here and any pk/owner keys in $data are ignored.
     *
     * @param string $ownerType
     * @param int    $ownerId
     * @param array  $data
     *
     * @return int|false the new row id, or false on failure
     */
    public function insertResource(string $ownerType, int $ownerId, array $data): int|false
    {
        if (!self::isValidOwnerType($ownerType)) {
            return false;
        }

        $values = $this->filterColumns($data);
        $values['s_owner_type'] = $ownerType;
        $values['i_owner_id']   = $ownerId;
        if (empty($values['dt_created'])) {
            $values['dt_created'] = date('Y-m-d H:i:s');
        }

        try {
            $id = $this->table()->insert($values);
        } catch (Throwable $e) {
            return false;
        }

        $this->flushOwnerCache($ownerType, $ownerId);

        return $id;
    }

    /**
     * Update a resource row by id. $data may carry any resource column except the
     * primary key; dt_updated is stamped automatically.
     *
     * @param int   $id
     * @param array $data
     *
     * @return bool
     */
    public function updateResource(int $id, array $data): bool
    {
        $values = $this->filterColumns($data);
        if (empty($values)) {
            return false;
        }
        if (empty($values['dt_updated'])) {
            $values['dt_updated'] = date('Y-m-d H:i:s');
        }

        try {
            $row = $this->table()->where('pk_i_id', $id)->first();
            $this->table()->where('pk_i_id', $id)->update($values);
        } catch (Throwable $e) {
            return false;
        }

        if (is_array($row) && isset($row['s_owner_type'], $row['i_owner_id'])) {
            $this->flushOwnerCache($row['s_owner_type'], (int) $row['i_owner_id']);
        }

        return true;
    }

    /**
     * Delete every resource row owned by (ownerType, ownerId).
     *
     * NOTE: this removes database rows only. Deleting the underlying files and
     * enqueuing remote-storage cleanup is handled by the upload service added in
     * a later phase; callers that need file removal must go through that service.
     *
     * @param string $ownerType
     * @param int    $ownerId
     *
     * @return int|false affected rows, or false on failure
     */
    public function deleteByOwner(string $ownerType, int $ownerId): int|false
    {
        if (!self::isValidOwnerType($ownerType)) {
            return false;
        }

        try {
            $affected = $this->table()
                ->where('s_owner_type', $ownerType)
                ->where('i_owner_id', $ownerId)
                ->delete();
        } catch (Throwable $e) {
            return false;
        }

        $this->flushOwnerCache($ownerType, $ownerId);

        return $affected;
    }

    /**
     * Delete all resources whose id is in $ids.
     *
     * @param array $ids
     *
     * @return int|false affected rows, or false on failure
     */
    public function deleteResourcesIds(array $ids): int|false
    {
        if (empty($ids)) {
            return false;
        }

        $ids = array_map('intval', $ids);

        try {
            return $this->table()->whereIn('pk_i_id', $ids)->delete();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * A page of resource ids, ordered by pk_i_id, without loading full rows.
     * Used by low-memory batch operations that walk the whole table.
     *
     * @param int $offset
     * @param int $limit
     *
     * @return int[]
     */
    public function getResourceIdsBatch(int $offset, int $limit): array
    {
        $rows = $this->table()
            ->select('pk_i_id')
            ->orderBy('pk_i_id', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return array_map('intval', array_column($rows, 'pk_i_id'));
    }

    /**
     * A page of full resource rows for a given storage adapter id, ordered by
     * pk_i_id. Used to walk every resource on one storage backend (e.g. 'local')
     * and enqueue a job per row without loading the whole table at once.
     *
     * @param string $storage
     * @param int    $offset
     * @param int    $limit
     *
     * @return array
     */
    public function getResourcesBatchByStorage(string $storage, int $offset, int $limit): array
    {
        return $this->table()
            ->where('s_storage', $storage)
            ->orderBy('pk_i_id', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * Prime the per-owner resource cache for a set of owners of one type in a
     * single query, seeding the exact keys findByOwner() reads so its per-owner
     * calls become cache hits. Owners with no resources are seeded with an empty
     * array so they do not fall through to their own query.
     *
     * @param string $ownerType
     * @param int[]  $ownerIds
     *
     * @return void
     */
    public function primeOwnerCache(string $ownerType, array $ownerIds): void
    {
        if (!self::isValidOwnerType($ownerType)) {
            return;
        }

        $ownerIds = array_values(array_unique(array_map('intval', $ownerIds)));
        if (empty($ownerIds)) {
            return;
        }

        $rows = $this->table()
            ->where('s_owner_type', $ownerType)
            ->whereIn('i_owner_id', $ownerIds)
            ->orderBy('pk_i_id', 'ASC')
            ->get();

        $byOwner = array_fill_keys($ownerIds, array());
        foreach ($rows as $row) {
            $byOwner[(int) $row['i_owner_id']][] = $row;
        }

        foreach ($byOwner as $ownerId => $resources) {
            osc_cache_set($this->ownerCacheKey($ownerType, $ownerId), $resources, OSC_CACHE_TTL);
        }
    }

    /**
     * Drop the cached resource list for one owner. Public entry point for callers
     * outside the model (the storage worker, the upload service) that mutate a
     * row's files or storage backend and need findByOwner() to re-read.
     *
     * @param string $ownerType
     * @param int    $ownerId
     *
     * @return void
     */
    public function invalidateOwnerCache(string $ownerType, int $ownerId): void
    {
        if (!self::isValidOwnerType($ownerType)) {
            return;
        }

        $this->flushOwnerCache($ownerType, $ownerId);
    }

    /**
     * A fresh query builder bound to the (prefixed) t_resource table.
     *
     * @return QueryBuilder
     */
    private function table(): QueryBuilder
    {
        return osc_db_table(DB_TABLE_PREFIX . self::TABLE);
    }

    /**
     * Keep only keys that map to real resource columns, dropping the primary key
     * and the owner pair (set explicitly by the caller).
     *
     * @param array $data
     *
     * @return array
     */
    private function filterColumns(array $data): array
    {
        $allowed = array_diff(self::COLUMNS, array('pk_i_id', 's_owner_type', 'i_owner_id'));

        return array_intersect_key($data, array_flip($allowed));
    }

    /**
     * Cache key for a single owner's resource list. Mirrors the ItemResource key
     * pattern so behaviour is familiar and primeable.
     *
     * @param string $ownerType
     * @param int    $ownerId
     *
     * @return string
     */
    private function ownerCacheKey(string $ownerType, int $ownerId): string
    {
        return md5(osc_base_url() . 'Resource:findByOwner:' . $ownerType . ':' . $ownerId);
    }

    /**
     * Drop the cached resource list for one owner.
     *
     * @param string $ownerType
     * @param int    $ownerId
     *
     * @return void
     */
    private function flushOwnerCache(string $ownerType, int $ownerId): void
    {
        osc_cache_delete($this->ownerCacheKey($ownerType, $ownerId));
    }
}

/* file end: ./oc-includes/osclass/classes/model/Resource.php */
