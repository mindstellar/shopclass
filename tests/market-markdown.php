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
 * Pins Markdown::toHtml(), the README renderer the catalog builder and the admin detail
 * dialog share: everything is escaped first, and links and images keep only http(s).
 * Usage:  php tests/market-markdown.php
 */

require_once __DIR__ . '/../oc-includes/osclass/classes/market/Markdown.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\market\Markdown;

harness_section('what it renders');

pin('a heading and a paragraph', "<h1>Title</h1>\n<p>Some <strong>bold</strong> text.</p>", Markdown::toHtml("# Title\n\nSome **bold** text."));
check('a list', strpos(Markdown::toHtml("- one\n- two"), '<li>one</li>') !== false);
check('a table', strpos(Markdown::toHtml("| a | b |\n|---|---|\n| 1 | 2 |"), '<td>1</td>') !== false);
check('a code block keeps its text', strpos(Markdown::toHtml("```\n<b>x</b>\n```"), '&lt;b&gt;x&lt;/b&gt;') !== false);
pin('nothing for nothing', '', Markdown::toHtml(null));
pin('a wrapped list item stays one item', '<ul><li>one and more</li><li>two</li></ul>', Markdown::toHtml("- one and\n  more\n- two"));
pin('an ordered item keeps its wrapped line', '<ol><li>a b</li><li>c</li></ol>', Markdown::toHtml("1. a\n   b\n2. c"));
pin('a blank line between items keeps one list', '<ul><li>a</li><li>b</li></ul>', Markdown::toHtml("- a\n\n- b"));
pin('code under an item stays in it', '<ol><li>run<pre><code>npm i</code></pre></li><li>next</li></ol>', Markdown::toHtml("1. run\n\n   ```\n   npm i\n   ```\n2. next"));
pin('an ordered list keeps its first number', '<ol start="3"><li>c</li></ol>', Markdown::toHtml('3. c'));
pin('an empty header row is left out', '<table><tbody><tr><td>a</td><td>b</td></tr></tbody></table>', Markdown::toHtml("| | |\n|---|---|\n| a | b |"));
check('an <https://…> address is a link', strpos(Markdown::toHtml('<https://x.com>'), '<a href="https://x.com"') !== false);
check('a bare address is a link, without the full stop', strpos(Markdown::toHtml('See https://x.com/a_b.'), 'href="https://x.com/a_b"') !== false);
pin('a line of = under text is a heading', '<h1>Title</h1>', Markdown::toHtml("Title\n====="));
pin('an HTML comment is dropped', '<p>Seen</p>', Markdown::toHtml("<!-- note\nmore -->\nSeen"));
pin('front matter is dropped', '<p>Body</p>', Markdown::toHtml("---\ntitle: x\n---\nBody"));
pin('a backslash escapes, two spaces break', '<p>*a*<br>b</p>', Markdown::toHtml("\\*a\\*  \nb"));
check('a quote holds a list', strpos(Markdown::toHtml("> - a\n> - b"), '<blockquote><ul><li>a</li><li>b</li></ul></blockquote>') !== false);
check('a relative image resolves against the base',
    strpos(Markdown::toHtml('![s](assets/a.png)', 'https://raw.githubusercontent.com/o/r/v1/'), 'src="https://raw.githubusercontent.com/o/r/v1/assets/a.png"') !== false);
check('a path out of the package does not', strpos(Markdown::toHtml('![s](../a.png)', 'https://raw.githubusercontent.com/o/r/v1/'), 'src="../a.png"') !== false);

harness_section('what it refuses');

$html = Markdown::toHtml("<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>");
check('raw HTML stays text', strpos($html, '<script') === false && strpos($html, '<img') === false);
check('a javascript: link loses its address', strpos(Markdown::toHtml('[x](javascript:alert(1))'), 'javascript:') === false);
check('an http link keeps it', strpos(Markdown::toHtml('[x](https://example.com)'), 'href="https://example.com"') !== false);
check('an image keeps only http(s)', strpos(Markdown::toHtml('![a](data:image/png;base64,AAA)'), '<img') === false);
check('a title cannot leave its attribute', strpos(Markdown::toHtml('[x](https://x.com "a\" onmouseover=\"b")'), 'onmouseover="') === false);
check('placeholder bytes in the text are dropped', strpos(Markdown::toHtml("`c` ![a\x010\x02](https://x.com/i.png)"), 'alt="a0"') !== false);

exit(harness_result());
