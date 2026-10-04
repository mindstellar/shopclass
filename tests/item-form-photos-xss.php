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
 * ItemForm::photos() printed the request's `secret` raw into each photo's delete link, so a
 * crafted edit URL ran script. The link now carries the listing's stored secret, escaped.
 *
 * DB-free.  Usage: php tests/item-form-photos-xss.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';

$GLOBALS['viewItem'] = array('pk_i_id' => 10, 's_secret' => 'Stored01');

function osc_item_id()
{
    return (int)($GLOBALS['viewItem']['pk_i_id'] ?? 0);
}

function osc_item_secret()
{
    return (string)($GLOBALS['viewItem']['s_secret'] ?? '');
}

function osc_apply_filter($name, $value)
{
    return $value;
}

function osc_base_url()
{
    return 'https://example.test/';
}

function _e($text)
{
    echo $text;
}

$payload = "x');alert(document.domain);//\"><script>alert(1)</script>";
$_GET['secret'] = $payload;
$_REQUEST['secret'] = $payload;
Params::init();

$resources = array(
    array('pk_i_id' => 5, 'fk_i_item_id' => 10, 's_name' => 'abc123', 's_path' => 'oc-content/uploads/0/', 's_extension' => 'jpg'),
    array('pk_i_id' => 6, 'fk_i_item_id' => 11, 's_name' => 'def456', 's_path' => 'oc-content/uploads/0/', 's_extension' => 'jpg'),
);

ob_start();
ItemForm::photos($resources);
$html = ob_get_clean();

preg_match_all('/href="(javascript:[^"]*)"/', $html, $m);
$links = $m[1];

harness_section('the delete link');
check('one link per photo', count($links) === 2);
check('the request secret is not printed', strpos($html, 'alert') === false && strpos($html, '<script>') === false);
pin(
    'the link carries the stored secret',
    "javascript:delete_image(5, 10, &#039;abc123&#039;, &#039;Stored01&#039;);",
    $links[0] ?? null
);
pin(
    'a photo of another listing gets no secret',
    "javascript:delete_image(6, 11, &#039;def456&#039;, &#039;&#039;);",
    $links[1] ?? null
);

harness_section('a stored value with quotes stays inside the JS string');
$GLOBALS['viewItem']['s_secret'] = "a'b\"c";
ob_start();
ItemForm::photos(array($resources[0]));
$html = ob_get_clean();
preg_match('/href="(javascript:[^"]*)"/', $html, $one);
pin(
    'escaped for JS, then for the attribute',
    "javascript:delete_image(5, 10, &#039;abc123&#039;, &#039;a\\&#039;b\\&quot;c&#039;);",
    $one[1] ?? null
);

exit(harness_result());
