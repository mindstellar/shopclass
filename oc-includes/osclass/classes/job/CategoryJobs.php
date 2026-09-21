<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\job;

use Category;
use Item;
use mindstellar\database\DbException;
use Throwable;

/**
 * Deleting a category, in batches, when it is too big to do in a request.
 *
 * `Category::deleteByPrimaryKey()` loads every listing in the tree and removes them one
 * at a time inside a single transaction. Measured on a 243k-listing production copy: 808
 * listings took 17.9 seconds, about 22ms each. The largest category there holds 39,225,
 * which is roughly 14.5 minutes of held InnoDB locks -- and any host with a
 * `max_execution_time` kills the request part-way, rolls the whole thing back, and leaves
 * a category nobody can delete. It was never a data-safety problem; it was an
 * availability one.
 *
 * So a big category is deleted here instead, a batch at a time, with nothing held open
 * between batches. A small one is still deleted in the request, because a site owner
 * clicking delete on a category with nine listings should see it gone, not "queued".
 */
final class CategoryJobs
{
    public const TYPE = 'category.delete';

    /** At or below this many listings in the tree, delete in the request as before. */
    public const INLINE_LIMIT = 500;

    /** Listings removed per run. Each is ~22ms, so a batch is a few seconds. */
    public const BATCH = 200;

    /**
     * @return void
     */
    public static function register(): void
    {
        JobRegistry::register(self::TYPE, static fn (Job $job) => self::delete($job));
    }

    /**
     * Delete a category, choosing the way that fits its size.
     *
     * @param int $categoryId
     *
     * @return string 'done' when it was deleted here, 'queued' when a job will finish it,
     *                'failed' when it could not be deleted
     */
    public static function requestDelete(int $categoryId): string
    {
        if ($categoryId <= 0) {
            return 'failed';
        }

        $ids = self::treeIds($categoryId);
        if ($ids === array()) {
            return 'failed';
        }

        if (self::countItems($ids) <= self::INLINE_LIMIT) {
            return Category::newInstance()->deleteByPrimaryKey($categoryId) === false ? 'failed' : 'done';
        }

        // Take the tree out of the public site now. The listings are still there until
        // the job reaches them, and a category being emptied should not be browsable.
        self::disable($ids);

        $id = osc_job_enqueue(self::TYPE, array('category_id' => $categoryId, 'ids' => $ids));

        return $id > 0 ? 'queued' : 'failed';
    }

    /**
     * Remove one batch of listings, then ask to run again. Once the tree is empty the
     * ordinary delete finishes the job, and by then it has almost nothing left to do.
     *
     * Idempotent: every run re-reads which listings are left, so a repeated run removes
     * the next ones rather than redoing anything.
     *
     * @param Job $job
     *
     * @return void
     */
    private static function delete(Job $job): void
    {
        $categoryId = (int) $job->get('category_id', 0);
        if ($categoryId <= 0) {
            return;
        }

        // Re-read the tree each run: a subcategory may have been added or moved since
        // the job was queued, and the stored list would miss it.
        $ids = self::treeIds($categoryId);
        if ($ids === array()) {
            return; // already gone
        }

        $removed = self::deleteItemBatch($ids, self::BATCH);
        $left    = self::countItems($ids);

        // Keep batching while more than one batch is left. Comparing against the inline
        // limit instead would hand the tail back to the ordinary delete as soon as the
        // tree dropped under it -- hundreds of listings in one request, which is the
        // thing this exists to avoid.
        if ($left > self::BATCH) {
            if ($removed === 0) {
                // Every listing in the batch refused to go. Carrying on would spin
                // forever, so fail and let the backoff and the queue screen show it.
                throw new \RuntimeException(
                    'Category ' . $categoryId . ' still has ' . $left
                    . ' listing(s) and none could be removed this run'
                );
            }

            // More than a batch left. Nothing is held between runs.
            $job->repeat(array('category_id' => $categoryId, 'ids' => $ids));

            return;
        }

        // One batch or fewer left, so the ordinary path is bounded now, and it keeps every hook,
        // cascade and cache flush in one place.
        if (Category::newInstance()->deleteByPrimaryKey($categoryId) === false) {
            throw new \RuntimeException('Could not delete category ' . $categoryId);
        }
    }

    /**
     * The category and every descendant, deepest first, so a caller may delete in order.
     *
     * @param int $categoryId
     *
     * @return array<int,int> empty when the category does not exist
     */
    public static function treeIds(int $categoryId): array
    {
        $category = Category::newInstance()->findByPrimaryKey($categoryId);
        if ($category === false || $category === null) {
            return array();
        }

        $ids   = array();
        $stack = array($categoryId);

        while ($stack !== array()) {
            $id    = (int) array_pop($stack);
            $ids[] = $id;

            foreach (Category::newInstance()->findSubcategories($id) as $child) {
                $stack[] = (int) $child['pk_i_id'];
            }
        }

        // Deepest last out of the walk above, so reverse to get deepest first.
        return array_reverse($ids);
    }

    /**
     * How many listings sit anywhere in the tree.
     *
     * @param array<int,int> $ids
     *
     * @return int
     */
    public static function countItems(array $ids): int
    {
        if ($ids === array()) {
            return 0;
        }

        try {
            return osc_db_table(DB_TABLE_PREFIX . 't_item')
                ->whereIn('fk_i_category_id', $ids)
                ->count();
        } catch (DbException $e) {
            // Unknown size. Treat it as big: queuing a small category costs one cron
            // tick, while running a huge one inline is the failure this exists to stop.
            return PHP_INT_MAX;
        }
    }

    /**
     * Remove up to $limit listings from the tree, through the ordinary item delete so
     * photos, comments and caches go with them.
     *
     * @param array<int,int> $ids
     * @param int            $limit
     *
     * @return int how many were removed
     */
    private static function deleteItemBatch(array $ids, int $limit): int
    {
        try {
            $rows = osc_db_table(DB_TABLE_PREFIX . 't_item')
                ->select('pk_i_id')
                ->whereIn('fk_i_category_id', $ids)
                ->orderBy('pk_i_id', 'ASC')
                ->limit(max(1, $limit))
                ->get();
        } catch (DbException $e) {
            return 0;
        }

        $removed = 0;
        foreach ($rows as $row) {
            try {
                Item::newInstance()->deleteByPrimaryKey((int) $row['pk_i_id']);
                $removed++;
            } catch (Throwable $e) {
                // One unremovable listing must not stop the rest of the category. It is
                // re-read next run; if it keeps failing the job gives up and says so.
                if (defined('OSC_DEBUG') && OSC_DEBUG) {
                    trigger_error(
                        'category.delete: item ' . $row['pk_i_id'] . ': ' . $e->getMessage(),
                        E_USER_NOTICE
                    );
                }
            }
        }

        return $removed;
    }

    /**
     * Hide a tree from the public site while its listings are being removed.
     *
     * @param array<int,int> $ids
     *
     * @return void
     */
    private static function disable(array $ids): void
    {
        if ($ids === array()) {
            return;
        }

        try {
            osc_db_table(DB_TABLE_PREFIX . 't_category')
                ->whereIn('pk_i_id', $ids)
                ->update(array('b_enabled' => 0));
        } catch (DbException $e) {
            // Not fatal: the delete still runs, the tree is just visible until it does.
        }
    }
}
