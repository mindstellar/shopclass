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
 * Pins osc_item_meta_value() escaping: a URL field only links to http(s) and cannot break out
 * of the href, and dropdown/radio values are escaped.
 *
 * Usage: php tests/item-meta-escape.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

function osc_apply_filter($name, $value)
{
    return $value;
}
function __($text)
{
    return $text;
}
function osc_esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function osc_field($row, $key, $default)
{
    return $row[$key] ?? $default;
}
function osc_item_meta()
{
    return $GLOBALS['testMeta'];
}

// Load only osc_item_meta_value() so the rest of the helper file needs no stand-ins.
$source = file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hItems.php');
preg_match('~function osc_item_meta_value\(\).*?\n}\n~s', $source, $match);
eval($match[0]);

$render = static function (string $type, string $value): string {
    $GLOBALS['testMeta'] = array('e_type' => $type, 's_value' => $value);

    return osc_item_meta_value();
};

harness_section('URL fields');
check('a quote cannot leave the href', !str_contains($render('URL', 'x&quot; onmouseover=&quot;alert(1)'), '" onmouseover'));
check('a javascript: scheme never becomes the link', !preg_match('~href="javascript:~i', $render('URL', 'javascript:alert(1)//http://a')));
pin('an https link is kept and escaped', '<a href="https://ok.example/a?b=1&amp;c=2" rel="noopener nofollow">https://ok.example/a?b=1&amp;c=2</a>', $render('URL', 'https://ok.example/a?b=1&amp;c=2'));
pin('a bare host gets http://', '<a href="http://example.com" rel="noopener nofollow">http://example.com</a>', $render('URL', 'example.com'));

harness_section('Dropdown and radio fields');
pin('markup is escaped', '&lt;script&gt;x&lt;/script&gt;', $render('DROPDOWN', '<script>x</script>'));
pin('a stored entity is not double-escaped', 'Tom &amp; Jerry', $render('RADIO', 'Tom &amp; Jerry'));

exit(harness_result());
