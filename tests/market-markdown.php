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

harness_section('what it refuses');

$html = Markdown::toHtml("<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>");
check('raw HTML stays text', strpos($html, '<script') === false && strpos($html, '<img') === false);
check('a javascript: link loses its address', strpos(Markdown::toHtml('[x](javascript:alert(1))'), 'javascript:') === false);
check('an http link keeps it', strpos(Markdown::toHtml('[x](https://example.com)'), 'href="https://example.com"') !== false);
check('an image keeps only http(s)', strpos(Markdown::toHtml('![a](data:image/png;base64,AAA)'), '<img') === false);

exit(harness_result());
