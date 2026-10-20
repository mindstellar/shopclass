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
 * Pins the changelog renderer used by the upgrade screen and the version page.
 *
 * Usage: php tests/admin-inline-markdown.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\admin\InlineMarkdown;

harness_section('inline Markdown');

pin('bold', 'a <strong>b</strong> c', InlineMarkdown::render('a **b** c'));
pin('code', '<code>x</code>', InlineMarkdown::render('`x`'));
pin('code is not parsed further', '<code>**x**</code>', InlineMarkdown::render('`**x**`'));
pin('tags are escaped', '&lt;b&gt;', InlineMarkdown::render('<b>'));
pin('relative link', '<a href="ChangelogHistory.txt">ChangelogHistory.txt</a>', InlineMarkdown::render('[ChangelogHistory.txt](ChangelogHistory.txt)'));
pin('relative link with base', '<a href="https://x.test/a.txt">a</a>', InlineMarkdown::render('[a](a.txt)', 'https://x.test/'));
pin(
    'external link opens safely',
    '<a href="https://example.com/a" target="_blank" rel="noopener noreferrer">x</a>',
    InlineMarkdown::render('[x](https://example.com/a)')
);
pin('javascript link stays text', '[x](javascript:alert(1))', InlineMarkdown::render('[x](javascript:alert(1))'));
pin('protocol-relative link stays text', '[x](//evil.com)', InlineMarkdown::render('[x](//evil.com)'));

foreach ([
    'entity colon'      => '[x](javascript&colon;alert&#40;1&#41;)',
    'entity first char' => '[x](&#106;avascript:alert)',
    'entity tab'        => '[x](java&Tab;script&colon;alert)',
    'leading C0 byte'   => "[x](\x01javascript:alert)",
    'data link'         => '[x](data:text/html,hi)',
    'mixed-case scheme' => '[x](JavaScript:alert)',
    'entity in https'   => '[x](https://a.test/&#106;)',
] as $label => $md) {
    pin("{$label} stays text", false, str_contains(InlineMarkdown::render($md), '<a '));
}
pin('https with query keeps working', true, str_contains(InlineMarkdown::render('[x](https://a.test/?a=1&b=2)'), '<a href="https://a.test/?a=1&amp;b=2"'));

exit(harness_result());
