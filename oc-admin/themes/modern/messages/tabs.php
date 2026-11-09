<?php

if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$tab    = (string) __get('tab');
$counts = (array) __get('counts');
$base   = osc_admin_base_url(true) . '?page=messages';
$links  = array(
    array('label' => __('Held'), 'url' => $base, 'active' => $tab === 'held', 'count' => (int) ($counts['held'] ?? 0)),
    array('label' => __('Reported'), 'url' => $base . '&action=reported', 'active' => $tab === 'reported', 'count' => (int) ($counts['reported'] ?? 0)),
);
if (!osc_is_moderator()) {
    $links[] = array('label' => __('Settings'), 'url' => $base . '&action=settings', 'active' => $tab === 'settings');
}
osc_admin_tab_nav($links);
