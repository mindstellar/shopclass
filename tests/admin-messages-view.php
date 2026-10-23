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
 * The admin Messages screen: CSRF and an admin actor on every change, escaped templates, the
 * menu entry, the moderator page list and the doc section.
 * Usage: php tests/admin-messages-view.php
 */

require_once __DIR__ . '/lib/harness.php';

$root       = dirname(__DIR__) . '/';
$controller = $root . 'oc-includes/osclass/classes/controller/admin/CAdminMessages.php';

harness_section('every change checks the token and acts as the signed-in admin');
foreach (['releasePost', 'removePost', 'closePost', 'clearPost'] as $method) {
    $source = harness_method_source($controller, $method);
    check("$method checks the token first", (bool) preg_match('/^[^{]*\{\s*osc_csrf_check\(\);/', $source));
}
$act = harness_method_source($controller, 'act');
check('the actor is the signed-in admin', strpos($act, 'Actor::fromSession(true)') !== false);
check('a demo refusal shows the demo message', strpos($act, 'Demo::message()') !== false);
check('no raw request value is read', strpos(file_get_contents($controller), 'Params::getParam(') === false);

harness_section('templates');
foreach (['index.php', 'thread.php'] as $file) {
    $source = file_get_contents($root . 'oc-admin/themes/modern/messages/' . $file);
    check("$file has no HTML comment", strpos($source, '<!--') === false);
    // Every echo that prints request-derived data goes through osc_esc_html, a cast or an escaping closure.
    preg_match_all('/echo\s+([^;]*);/', $source, $m);
    $raw = array_filter($m[1], static function (string $e): bool {
        $data = (bool) preg_match('/\$(row|message|names|older|itemTitle|senderName|reason|body)\b/', $e);

        return $data && strpos($e, 'osc_esc_html') === false && strpos($e, '(int)') === false
            && !preg_match('/\$(userLink|itemLink)\(/', $e);
    });
    check("$file escapes every value it prints", $raw === [], implode(' | ', $raw));
}
$index = file_get_contents($root . 'oc-admin/themes/modern/messages/index.php');
check('the link helpers escape names and titles', strpos($index, "osc_esc_html(\$name !== '' ? \$name") !== false && strpos($index, 'osc_esc_html($title)') !== false);
check('the ban link encodes the address', strpos($index, 'urlencode($email)') !== false);

check(
    'the new ban rule form takes the address from the link',
    strpos(harness_method_source($root . 'oc-includes/osclass/classes/controller/admin/CAdminUsers.php', 'createBanRule'), "Params::getParamString('email')") !== false
);

harness_section('menu and moderator access');
$menu = file_get_contents($root . 'oc-includes/osclass/classes/AdminMenu.php');
check(
    'the Messages entry is open to moderators',
    (bool) preg_match("/__\('Messages'\),\s*osc_admin_base_url\(true\) \. '\?page=messages',\s*'items_messages',\s*'moderator'/", $menu)
);
require_once $root . 'oc-includes/osclass/classes/admin/ModeratorAccess.php';
check('moderators may open the messages page', in_array('messages', \mindstellar\admin\ModeratorAccess::DEFAULT_PAGES, true));

harness_section('docs');
check('the guide has an On-site messages section', strpos(file_get_contents($root . 'docs/site/use/spam-and-abuse.md'), "\n## On-site messages\n") !== false);

exit(harness_result());
