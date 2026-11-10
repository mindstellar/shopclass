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

namespace mindstellar\admin;

/**
 * What one admin sees in the bell: active and hidden notices, action group first, then newest.
 */
final readonly class AdminNoticeView
{
    /** @var AdminNotice[] */
    public array $active;

    /** @var AdminNotice[] */
    public array $hidden;

    /**
     * @param AdminNotice[] $active
     * @param AdminNotice[] $hidden
     */
    public function __construct(array $active = [], array $hidden = [])
    {
        $this->active = self::ordered($active);
        $this->hidden = self::ordered($hidden);
    }

    public function count(): int
    {
        return count($this->active);
    }

    public function actionCount(): int
    {
        return count(array_filter($this->active, static fn (AdminNotice $n): bool => $n->group() === 'action'));
    }

    /**
     * Where the sidebar prints a dot, by 'menu' and by 'menu/submenu'.
     *
     * @return array<string,array{group:string, count:int, action:int}> group is '' when only hidden rows are there
     */
    public function marks(): array
    {
        $marks = [];
        foreach ($this->hidden as $n) {
            foreach (self::markKeys($n) as $key) {
                $marks[$key] ??= ['group' => '', 'count' => 0, 'action' => 0];
            }
        }
        foreach ($this->active as $n) {
            foreach (self::markKeys($n) as $key) {
                $mark          = $marks[$key] ?? ['group' => '', 'count' => 0, 'action' => 0];
                $mark['count']++;
                $mark['action'] += $n->group() === 'action' ? 1 : 0;
                $mark['group']  = $mark['action'] > 0 ? 'action' : 'headsup';
                $marks[$key]    = $mark;
            }
        }
        ksort($marks);

        return $marks;
    }

    /**
     * The link and the Hide or Show button of one notice, the same on the panel and the screen.
     */
    public static function actions(AdminNotice $n, bool $hidden, bool $withLink = true): string
    {
        $href = '';
        if (str_starts_with($n->link, '?page=')) {
            $href = osc_admin_base_url(true) . $n->link;
        } elseif (preg_match('#^https?://#i', $n->link) === 1) {
            $href = $n->link;
        }
        $link = !$withLink || $href === '' ? ''
            : '<a href="' . osc_esc_html($href) . '">' . osc_esc_html($n->linkLabel !== '' ? $n->linkLabel : __('See details')) . '</a>';
        [$attr, $word, $name] = $hidden
            ? ['data-osc-notice-show', __('Show again'), __('Show again: %s')]
            : ['data-osc-notice-hide', __('Hide for 7 days'), __('Hide for 7 days: %s')];

        return $link . '<button type="button" class="osc-notice-btn" ' . $attr . '="' . osc_esc_html($n->id) . '"'
            . ' aria-label="' . osc_esc_html(sprintf($name, $n->text)) . '">' . osc_esc_html($word) . '</button>';
    }

    /**
     * @return string[]
     */
    private static function markKeys(AdminNotice $n): array
    {
        return $n->submenu === '' ? [$n->menu] : [$n->menu, $n->menu . '/' . $n->submenu];
    }

    /**
     * @param AdminNotice[] $notices
     *
     * @return AdminNotice[]
     */
    private static function ordered(array $notices): array
    {
        usort(
            $notices,
            static fn (AdminNotice $a, AdminNotice $b): int => [$a->group() !== 'action', -$a->since, $a->id] <=> [$b->group() !== 'action', -$b->since, $b->id]
        );

        return $notices;
    }
}
