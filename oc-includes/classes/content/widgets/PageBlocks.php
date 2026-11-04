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

use mindstellar\pages\PageQuery;
use mindstellar\pages\PageTemplateRegistry;

/**
 * Page mode of the customiser: the spec for one Page builder page, the block summary the
 * page form shows, and the removal of a page's drafts.
 */
final class PageBlocks
{
    /**
     * A customiser spec for one Page builder page on any theme: its single location 'page.N'
     * takes every type that fits it, and there are no look fields. Null when the page does
     * not exist or its template is not a builder template.
     *
     * @return array{locations:array<string,array{label:string,types:string[],preview:string}>,settings:array{page:string,fields:string[]},swatches:array<string,mixed>,live:array<string,string>}|null
     * @throws \mindstellar\database\DbException
     */
    public static function spec(int $pageId): ?array
    {
        $page = self::builderPage($pageId);
        if ($page === null) {
            return null;
        }

        $slug     = 'page.' . $pageId;
        $registry = WidgetRegistry::getInstance();
        $types    = array_values(array_filter(
            array_map('strval', array_keys($registry->all())),
            static fn (string $id): bool => $registry->fits($id, $slug)
        ));
        $name  = (string) $page['s_internal_name'];
        $title = self::title($pageId, $name);

        return array(
            'locations' => array($slug => array(
                'label'   => $title,
                'types'   => $types,
                'preview' => PageQuery::siteUrl($pageId, $name, $title, ''),
            )),
            'settings'  => array('page' => '', 'fields' => array()),
            'swatches'  => array(),
            'live'      => array(),
        );
    }

    /**
     * What the page form shows about a builder page's blocks: the live rows in order, whether
     * this admin has a draft, and the Edit blocks link. Null when the page is missing or not a
     * builder page. A row's name is its description, else its type label; a row whose type is
     * not registered, or that is switched off, is hidden.
     *
     * @return array{count:int,blocks:list<array{name:string,type:string,icon:string,hidden:bool}>,draft:bool,edit_url:string}|null
     * @throws \mindstellar\database\DbException
     */
    public static function summary(int $pageId, int $adminId): ?array
    {
        if (self::builderPage($pageId) === null) {
            return null;
        }

        $rows = WidgetStore::rowsAt('page.' . $pageId);
        uasort($rows, static fn (array $a, array $b): int => ((int) $a['i_order'] <=> (int) $b['i_order']) ?: ((int) $a['pk_i_id'] <=> (int) $b['pk_i_id']));
        $blocks = array_values(array_map(CustomiserScreen::blockLabel(...), $rows));

        return array(
            'count'    => count($blocks),
            'blocks'   => $blocks,
            'draft'    => (new CustomiserDraft('page.' . $pageId, $adminId))->read() !== null,
            'edit_url' => osc_admin_base_url(true) . '?page=pages&action=blocks&id=' . $pageId,
        );
    }

    /**
     * Whether the page exists but its template is not a builder template, so it has no blocks.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function isPlainPage(int $pageId): bool
    {
        return $pageId > 0 && self::builderPage($pageId) === null && (new PageQuery())->find((string) $pageId) !== null;
    }

    /**
     * Remove every draft of one page, whichever admin made it.
     *
     * @return int rows removed
     * @throws \mindstellar\database\DbException
     */
    public static function forgetDrafts(int $pageId): int
    {
        return CustomiserDraft::forgetScope('page.' . $pageId);
    }

    /**
     * The page row when it exists and its template is a builder template, else null.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    private static function builderPage(int $pageId): ?array
    {
        if ($pageId <= 0) {
            return null;
        }
        $page = (new PageQuery())->find((string) $pageId);
        if ($page === null) {
            return null;
        }
        $meta     = json_decode((string) ($page['s_meta'] ?? ''), true);
        $template = is_array($meta) && is_string($meta['template'] ?? null) ? $meta['template'] : '';
        $layout   = $template === '' ? null : PageTemplateRegistry::getInstance()->get($template);

        return $layout === null || empty($layout['builder']) ? null : $page;
    }

    /**
     * The page title in the admin's language, then the site's, then any; else its internal name.
     *
     * @throws \mindstellar\database\DbException
     */
    private static function title(int $pageId, string $fallback): string
    {
        $texts = (new PageQuery())->texts(array($pageId))[$pageId] ?? array();
        foreach (array_unique(array((string) osc_current_admin_locale(), (string) osc_language())) as $locale) {
            $title = trim((string) ($texts[$locale]['s_title'] ?? ''));
            if ($title !== '') {
                return $title;
            }
        }
        foreach ($texts as $text) {
            $title = trim((string) ($text['s_title'] ?? ''));
            if ($title !== '') {
                return $title;
            }
        }

        return $fallback;
    }
}
