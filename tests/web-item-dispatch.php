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
 * The listing page's actions: every action it has always answered still has its method, and
 * any other action shows the listing. Themes and plugins link to these action names.
 *
 * DB-free.  Usage: php tests/web-item-dispatch.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$actions = (new ReflectionClassConstant('CWebItem', 'ACTIONS'))->getValue();

harness_section('the actions');
pin('every action the page has always answered', array(
    'activate', 'add_comment', 'contact', 'contact_post', 'deleteResources', 'delete_comment', 'item_add',
    'item_add_post', 'item_delete', 'item_edit', 'item_edit_post', 'mark', 'send_friend', 'send_friend_post',
), (static function (array $names): array {
    sort($names);

    return $names;
})(array_keys($actions)));
foreach ($actions as $action => $method) {
    check("$action has its method $method", method_exists('CWebItem', $method));
}
check('any other action shows the listing', method_exists('CWebItem', 'showItem'));
check('the view beacon is answered before the map', strpos(harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebItem.php', 'doModel'), "'view_beacon'") !== false);

exit(harness_result());
