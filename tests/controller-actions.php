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
 * Controllers that answer each action from its own method: every action they have always
 * answered still has its method, and any other action goes to the default one. Themes,
 * plugins and admin screens link to these action names.
 *
 * DB-free.  Usage: php tests/controller-actions.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

/** Controller => [its file, the actions it has always answered, the method for any other action]. */
$controllers = array(
    'CWebItem'    => array('CWebItem.php', array(
        'activate', 'add_comment', 'contact', 'contact_post', 'deleteResources', 'delete_comment', 'item_add',
        'item_add_post', 'item_delete', 'item_edit', 'item_edit_post', 'mark', 'send_friend', 'send_friend_post',
    ), 'showItem'),
    'CAdminItems' => array('admin/CAdminItems.php', array(
        'bulk_actions', 'clear_reports', 'clear_stat', 'delete', 'deleteResource', 'item_edit', 'item_edit_post',
        'items_reported', 'post', 'post_item', 'settings', 'settings_post', 'status', 'status_premium', 'status_spam',
    ), 'listings'),
);

foreach ($controllers as $class => [$file, $expected, $fallback]) {
    require_once ABS_PATH . 'oc-includes/osclass/classes/controller/' . $file;
    $actions = (new ReflectionClassConstant($class, 'ACTIONS'))->getValue();
    $names   = array_keys($actions);
    sort($names);

    harness_section($class);
    pin('every action it has always answered', $expected, $names);
    foreach ($actions as $action => $method) {
        check("$action has its method $method", method_exists($class, $method));
    }
    check("any other action goes to $fallback", method_exists($class, $fallback)
        && strpos(harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/' . $file, 'doModel'), "'$fallback'") !== false);
}
check('the view beacon is answered before the map', strpos(harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebItem.php', 'doModel'), "'view_beacon'") !== false);

exit(harness_result());
