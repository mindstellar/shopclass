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

namespace mindstellar\widgets;

/**
 * The last page editor change to one of a page's blocks, kept so one admin can undo it: a
 * t_key_value row in the group 'page_block_undo', keyed '<adminId>:<pageId>', for 15 minutes.
 */
final class PageBlockUndo
{
    public const GROUP = 'page_block_undo';
    public const TTL   = 900;

    private const EDIT_COLUMNS = array('s_description', 's_content', 's_type', 's_config');
    private const ADD_COLUMNS  = array('s_description', 's_location', 'e_kind', 's_content', 'i_order', 's_type', 's_config');

    /**
     * Keep an undo for a block that was 'added', 'edited' or 'deleted' on the page.
     *
     * @param array<string,mixed>|null $before the row as it was, or null for a new block
     *
     * @return bool whether an undo was kept
     */
    public static function remember(int $adminId, int $pageId, string $action, int $id, ?array $before): bool
    {
        if ($pageId <= 0 || $id <= 0 || ($before !== null && (string) ($before['s_location'] ?? '') !== 'page.' . $pageId)) {
            return false;
        }
        osc_kv_set(self::GROUP, self::key($adminId, $pageId), array('action' => $action, 'id' => $id, 'before' => $before), self::TTL);

        return true;
    }

    /**
     * @return array<string,mixed>|null the undo waiting for this admin and page
     */
    public static function pending(int $adminId, int $pageId): ?array
    {
        $undo = $pageId > 0 ? osc_kv_get(self::GROUP, self::key($adminId, $pageId)) : null;

        return is_array($undo) ? $undo : null;
    }

    /**
     * Undo the kept change: remove the block it added, or put back the block as it was.
     *
     * @return bool false when there was nothing to undo
     */
    public static function apply(int $adminId, int $pageId): bool
    {
        $undo = self::pending($adminId, $pageId);
        osc_kv_delete(self::GROUP, self::key($adminId, $pageId));
        if ($undo === null) {
            return false;
        }
        $location = 'page.' . $pageId;
        $id       = (int) ($undo['id'] ?? 0);
        $before   = is_array($undo['before'] ?? null) ? $undo['before'] : null;
        $row      = WidgetStore::find($id);
        $here     = $row !== null && $row['s_location'] === $location;
        match ($undo['action'] ?? '') {
            'added'   => $here ? WidgetStore::delete($id) : null,
            'edited'  => $here && $before !== null ? WidgetStore::update($id, self::columns($before, self::EDIT_COLUMNS)) : null,
            'deleted' => $row === null && $before !== null && ($before['s_location'] ?? '') === $location
                ? WidgetStore::add(self::columns($before, self::ADD_COLUMNS)) : null,
            default   => null,
        };

        return true;
    }

    private static function key(int $adminId, int $pageId): string
    {
        return $adminId . ':' . $pageId;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string>        $columns
     *
     * @return array<string,mixed>
     */
    private static function columns(array $row, array $columns): array
    {
        return array_intersect_key($row, array_flip($columns));
    }
}
