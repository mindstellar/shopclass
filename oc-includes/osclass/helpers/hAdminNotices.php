<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Admin notices: site problems and plugin warnings in the admin bell.
 *
 *     osc_add_admin_notice('error', 'The export failed.', array('id' => 'acme:export', 'link' => '?page=plugins'));
 *     osc_clear_admin_notice('acme:export');
 *     osc_add_admin_check('acme:no_key', 'warning', 'No key is set.', static fn (): bool => $noKey);
 *
 * A standing check is not stored, and its `when` runs on each admin page.
 */

use mindstellar\admin\AdminNotices;
use mindstellar\admin\AdminNoticeView;

if (!function_exists('osc_add_admin_notice')) {
    /**
     * Add a notice to the admin bell.
     *
     * @param string          $type    error, warning or info
     * @param string|callable $text    one plain sentence; a callable runs when the notice is shown
     * @param array<string,mixed> $options id, detail, link, link_label, menu, submenu, source,
     *                                 moderator, ttl. detail and link_label may be callables.
     *                                 A `when` callable here does what osc_add_admin_check() does.
     */
    function osc_add_admin_notice(string $type, string|callable $text, array $options = array()): void
    {
        if (!isset($options['when'])) {
            AdminNotices::getInstance()->raise($type, $text, $options);

            return;
        }
        if (is_callable($options['when'])) {
            osc_add_admin_check((string) ($options['id'] ?? ''), $type, $text, $options['when'], $options);
        } else {
            error_log('Admin notice check skipped: when is not callable');
        }
    }
}

if (!function_exists('osc_add_admin_check')) {
    /**
     * Add a standing check: shown while $when returns true, not stored, ignored outside the admin.
     *
     * @param string          $id      lowercase letters, digits and _ . : -
     * @param string          $type    error, warning or info
     * @param string|callable $text    one plain sentence; a callable runs when the notice is shown
     * @param callable        $when    must be cheap: it runs on each admin page
     * @param array<string,mixed> $options detail, link, link_label, menu, submenu, source, moderator.
     */
    function osc_add_admin_check(string $id, string $type, string|callable $text, callable $when, array $options = array()): void
    {
        AdminNotices::getInstance()->addCheck(array('id' => $id, 'type' => $type, 'text' => $text) + $options, $when);
    }
}

if (!function_exists('osc_clear_admin_notice')) {
    /**
     * Remove a notice for every admin.
     */
    function osc_clear_admin_notice(string $id): void
    {
        AdminNotices::getInstance()->clear($id);
    }
}

if (!function_exists('osc_admin_notices')) {
    /**
     * The notices of the signed-in admin.
     */
    function osc_admin_notices(): AdminNoticeView
    {
        try {
            [$admin, $moderator] = osc_admin_notice_viewer();
        } catch (\Throwable $e) {
            error_log('Admin notice role unknown: ' . $e->getMessage());

            return new AdminNoticeView();
        }

        return $admin > 0 ? AdminNotices::getInstance()->view($admin, $moderator) : new AdminNoticeView();
    }
}

if (!function_exists('osc_admin_notice_viewer')) {
    /**
     * Who is looking at the notices. The role is read once a request.
     *
     * @return array{0:int, 1:bool} the signed-in admin's id, 0 when nobody is, and whether a moderator
     */
    function osc_admin_notice_viewer(): array
    {
        static $roles = array();
        $admin = (int) osc_logged_admin_id();
        if ($admin <= 0) {
            return array(0, false);
        }
        $roles[$admin] ??= (bool) osc_is_moderator();

        return array($admin, $roles[$admin]);
    }
}
