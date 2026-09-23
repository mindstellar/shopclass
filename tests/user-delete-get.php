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
 * Pins that account deletion is not a GET with id+secret in the URL.
 *
 * The old action deleted as soon as the page was requested. A mail scanner or
 * a leaked referrer was enough. GET must only render the confirm view; the
 * mutation is POST delete_post with CSRF and the current password. The helper
 * that themes should print must not put a secret in the query string.
 *
 * DB-free. Usage:  php tests/user-delete-get.php
 */

require_once __DIR__ . '/lib/harness.php';

$controller = file_get_contents(
    __DIR__ . '/../oc-includes/osclass/classes/controller/CWebUser.php'
);
$defines = file_get_contents(
    __DIR__ . '/../oc-includes/osclass/helpers/hDefines.php'
);

harness_section('GET delete does not mutate');
check(
    'the GET case exists',
    (bool)preg_match("/case 'delete':/", $controller)
);
preg_match("/case 'delete':(.*?)case 'delete_post':/s", $controller, $get);
check('GET delete was parsed as its own case', isset($get[1]) && $get[1] !== '');
check(
    'GET delete does not call deleteUser',
    isset($get[1]) && strpos($get[1], 'deleteUser') === false
);
check(
    'GET delete does not read a posted secret',
    isset($get[1]) && strpos($get[1], "getParam('secret')") === false
        && strpos($get[1], 'getParamString(\'secret\')') === false
);
check(
    'GET delete renders the confirm view',
    isset($get[1]) && strpos($get[1], 'user-delete_account.php') !== false
);

harness_section('POST delete_post is the mutation');
preg_match("/case 'delete_post':(.*?)private function handleAvatarUpload/s", $controller, $post);
check('delete_post was parsed', isset($post[1]) && $post[1] !== '');
check(
    'delete_post checks CSRF',
    isset($post[1]) && strpos($post[1], 'osc_csrf_check()') !== false
);
check(
    'delete_post verifies the current password',
    isset($post[1]) && strpos($post[1], 'osc_verify_password') !== false
);
check(
    'delete_post calls deleteUser',
    isset($post[1]) && strpos($post[1], 'deleteUser') !== false
);

harness_section('osc_user_delete_url has no secret');
check(
    'the helper exists',
    (bool)preg_match('/function osc_user_delete_url\(\)/', $defines)
);
$start = strpos($defines, 'function osc_user_delete_url()');
$end   = strpos($defines, 'function osc_user_unsubscribe_alert_url');
$helper = ($start !== false && $end !== false && $end > $start)
    ? substr($defines, $start, $end - $start)
    : '';
check('the helper body was parsed', $helper !== '');
check(
    'the helper builds the user_delete route and nothing else',
    $helper !== '' && strpos($helper, "osc_core_url('user_delete')") !== false
);

// The URL itself is declared in the route table now, so that is where the
// no-id, no-secret, confirm-view-only shape has to be checked.
require_once __DIR__ . '/../oc-includes/osclass/classes/routing/CoreRoutes.php';
$route = \mindstellar\routing\CoreRoutes::all()['user_delete'] ?? array();
check('the route exists', $route !== array());
pin('the route points at the GET confirm action', 'delete', $route['to']['action'] ?? null);
pin('the route carries no parameters at all', array(), $route['params'] ?? array());

exit(harness_result());
