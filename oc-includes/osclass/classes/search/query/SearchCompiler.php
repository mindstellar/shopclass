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
 * Builds the SELECT statements of a search: the result page, its count, and the
 * featured (premium) block.
 */
final class SearchCompiler
{
    /** Seconds the featured block keeps one random order. */
    private const PREMIUM_ROTATION = 300;

    /**
     * The result page, or with $count the unordered, unlimited match list a COUNT wraps.
     *
     * @param SearchParts          $parts
     * @param \DBCommandClass|null $dao   clauses a caller added on the model's $dao
     * @param bool                 $count
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public static function results(SearchParts $parts, ?\DBCommandClass $dao, bool $count = false): array
    {
        $p             = DB_TABLE_PREFIX;
        $conditionsSql = $parts->plugin->conditionsSql();
        $extraFields   = $parts->plugin->fieldList();
        $withLocations = $parts->locations->used();
        $s             = $parts->newStatement();

        if ($parts->byItemId) {
            $s->select($p . 't_item.*, ' . $p . 't_item.s_contact_name as s_user_name');
            $s->from($p . 't_item');
            $s->where('pk_i_id = ?', array((int)$parts->itemId));

            return $s->compile();
        }

        $s->select($count ? $p . 't_item.pk_i_id' : $p . 't_item.*, ' . $p . 't_item.s_contact_name as s_user_name');
        $s->select($extraFields);
        $s->from($p . 't_item');

        if ($parts->byContactEmail) {
            $s->where($p . 't_item.s_contact_email = ?', array(SqlValue::bind($parts->contactEmail)));
        }

        $pattern = $parts->pattern;
        if ($pattern->active()) {
            $s->join($p . 't_item_description as d', 'd.fk_i_item_id = ' . $p . 't_item.pk_i_id', 'LEFT');
            $relevance = $parts->ordering->column() === 'relevance';
            if ($pattern->fullTextUsable()) {
                if ($relevance) {
                    $s->selectBound(...$pattern->relevanceSelect());
                    $s->having('relevance > 0');
                } else {
                    $s->where(...$pattern->matchCondition());
                }
            } else {
                $s->where(...$pattern->likeCondition());
                if ($relevance) {
                    $s->select('1 as relevance');
                }
            }
            $pattern->defaultLocale($parts->userLocale);
            $s->where(...$pattern->localeCondition());
        }

        $parts->plugin->applyItemConditions($s);
        $parts->categories->apply($s);
        $parts->users->apply($s);
        if ($withLocations || self::admin()) {
            self::joinLocation($s);
            $parts->locations->apply($s);
        }
        if ($parts->withPicture) {
            $s->join($p . 't_item_resource', $p . 't_item_resource.fk_i_item_id = ' . $p . 't_item.pk_i_id', 'LEFT');
            $s->where($p . "t_item_resource.s_content_type LIKE '%image%' ");
            $s->groupBy($p . 't_item.pk_i_id');
        }
        if ($parts->onlyPremium) {
            $s->where($p . 't_item.b_premium = 1');
        }
        $parts->applyPrice($s);
        $parts->plugin->applyRest($s, $conditionsSql);

        // Order and limit do not matter to a COUNT, and leaving the limit out keeps it exact.
        if (!$count) {
            $parts->ordering->apply($s);
            $s->prependOrderBy($parts->leadOrder);
        }
        if ($dao !== null) {
            $s->mergeDao($dao, $count);
        }

        return $s->compile();
    }

    /**
     * The count statement around results($count = true).
     *
     * @param array{0:string,1:array<int,mixed>} $matches
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public static function count(array $matches): array
    {
        return array('SELECT COUNT(*) AS total FROM (' . $matches[0] . ') AS search_count', $matches[1]);
    }

    /**
     * The featured block: premium listings matching only the keyword, location and
     * category, in a random order that holds for PREMIUM_ROTATION seconds.
     *
     * @param SearchParts $parts
     * @param int         $num
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public static function premiums(SearchParts $parts, $num): array
    {
        $p = DB_TABLE_PREFIX;
        // The filters fire here too, as they always have, though the block uses neither.
        $parts->plugin->conditionsSql();
        $parts->plugin->fieldList();
        $withLocations = $parts->locations->used();
        $sub           = null;

        if ($parts->pattern->active()) {
            $inner = $parts->newStatement();
            $inner->select('distinct d.fk_i_item_id');
            $inner->from($p . 't_item_description as d');
            $inner->from($p . 't_item as ti');
            $inner->where('ti.pk_i_id = d.fk_i_item_id');
            $inner->where(...$parts->pattern->matchCondition());
            $inner->where('ti.b_premium = 1');
            $parts->pattern->defaultLocale(self::admin() ? osc_current_admin_locale() : osc_current_user_locale());
            $inner->where(...$parts->pattern->localeCondition());
            $sub = $inner->compile();
        }

        $s = $parts->newStatement();
        $s->select($p . 't_item.*, ' . $p . 't_item.s_contact_name as s_user_name');
        $s->from($p . 't_item');
        $s->from($p . 't_item_stats');
        $s->where($p . 't_item_stats.fk_i_item_id = ' . $p . 't_item.pk_i_id');
        $s->where($p . 't_item.b_premium = 1');
        $s->where($p . 't_item.b_enabled = 1 ');
        $s->where($p . 't_item.b_active = 1 ');
        $s->where($p . 't_item.b_spam = 0');
        if ($withLocations || self::admin()) {
            self::joinLocation($s);
            $parts->locations->apply($s);
        }
        $parts->categories->apply($s);
        if ($sub !== null) {
            $s->where($p . 't_item.pk_i_id IN (' . $sub[0] . ')', $sub[1]);
        }
        $s->orderBy('RAND(' . intdiv(time(), self::PREMIUM_ROTATION) . ')');
        $s->limit(0, $num);

        return $s->compile();
    }

    /**
     * @param Statement $s
     *
     * @return void
     */
    private static function joinLocation(Statement $s): void
    {
        $p = DB_TABLE_PREFIX;
        $s->join($p . 't_item_location', $p . 't_item_location.fk_i_item_id = ' . $p . 't_item.pk_i_id', 'LEFT');
    }

    /**
     * @return bool
     */
    private static function admin(): bool
    {
        return defined('OC_ADMIN') && OC_ADMIN;
    }
}
