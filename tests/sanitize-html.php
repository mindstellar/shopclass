<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * osc_sanitize_html() — the allow-list a listing description is stored through.
 *
 * Params' XSS check strips every tag, so it is switched off wherever a rich editor is in
 * use, and for a while nothing replaced it: a description was stored exactly as posted and
 * a `<script>` in one ran for every visitor who opened the listing. The editor's source
 * view was never needed for it — the raw HTML went through the ordinary form POST.
 *
 * These pin both halves: the formatting a toolbar produces survives, and everything that
 * can execute does not. Pure function, so no database and no bootstrap.
 * Usage:  php tests/sanitize-html.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';

// Defined before the helper loads, so there is no redeclaration.
function osc_apply_filter($hook, $content, ...$args)
{
    return $content;
}

require_once __DIR__ . '/../oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/* ----------------------------------------------------------------------------
 * What must not survive. Each of these is a way to run script in a visitor's
 * browser from a field any registered user can fill in.
 * ------------------------------------------------------------------------- */
harness_section('osc_sanitize_html — nothing executable survives');

$attacks = array(
    'a script tag'              => '<script>window.x=1</script>',
    'an onerror handler'        => '<img src=x onerror="window.x=1">',
    'an onload handler'         => '<body onload="window.x=1">hi</body>',
    'a javascript: href'        => '<a href="javascript:window.x=1">click</a>',
    'a data: href'              => '<a href="data:text/html,<script>1</script>">click</a>',
    'an iframe'                 => '<iframe src="//evil.example"></iframe>',
    'an object embed'           => '<object data="//evil.example"></object>',
    'a form'                    => '<form action="//evil.example"><input name="p"></form>',
    'a style block'             => '<style>body{display:none}</style>',
    'an svg with a handler'     => '<svg onload="window.x=1"></svg>',
    'a meta refresh'            => '<meta http-equiv="refresh" content="0;url=//evil.example">',
    'an uppercase script tag'   => '<SCRIPT>window.x=1</SCRIPT>',
);

foreach ($attacks as $label => $payload) {
    $out = osc_sanitize_html($payload);
    check(
        $label . ' does not survive',
        stripos($out, '<script') === false
        && stripos($out, 'javascript:') === false
        && stripos($out, 'onerror') === false
        && stripos($out, 'onload') === false
        && stripos($out, '<iframe') === false
        && stripos($out, '<object') === false
        && stripos($out, '<form') === false
        && stripos($out, '<style') === false
        && stripos($out, 'http-equiv') === false,
        $out
    );
}

/* ----------------------------------------------------------------------------
 * What must survive — the point of having an allow-list rather than stripping
 * everything. A seller's formatting is why the rich editor is switched on.
 * ------------------------------------------------------------------------- */
harness_section('osc_sanitize_html — the editors\' own markup survives');

$kept = array(
    'paragraphs'      => '<p>hello</p>',
    'bold and italic' => '<strong>b</strong><em>i</em>',
    'underline'       => '<u>u</u>',
    'bullet lists'    => '<ul><li>one</li></ul>',
    'numbered lists'  => '<ol><li>one</li></ol>',
    'headings'        => '<h3>h</h3>',
    'blockquotes'     => '<blockquote>q</blockquote>',
    'tables'          => '<table><tbody><tr><td>c</td></tr></tbody></table>',
);
foreach ($kept as $label => $html) {
    pin($label . ' are kept', $html, osc_sanitize_html($html));
}

check(
    'an https link keeps its href',
    strpos(osc_sanitize_html('<a href="https://example.com">x</a>'), 'https://example.com') !== false
);
check(
    'a mailto link keeps its href',
    strpos(osc_sanitize_html('<a href="mailto:a@b.test">x</a>'), 'mailto:a@b.test') !== false
);
check(
    'the forecolor button\'s colour span keeps its colour',
    strpos(osc_sanitize_html('<span style="color:#ff0000">red</span>'), 'color') !== false
);
check(
    'an image keeps its source',
    strpos(osc_sanitize_html('<img src="https://example.com/a.png" alt="a">'), 'example.com/a.png') !== false
);
check(
    'but position is not a property a description may set',
    stripos(osc_sanitize_html('<span style="position:fixed;top:0">x</span>'), 'position') === false
);

/* ----------------------------------------------------------------------------
 * Shape: descriptions arrive as a per-locale map, and empty stays empty.
 * ------------------------------------------------------------------------- */
harness_section('osc_sanitize_html — input shapes');

$locales = osc_sanitize_html(array(
    'en_US' => '<p>ok</p><script>window.x=1</script>',
    'fr_FR' => '<p>bon</p><img src=x onerror="window.x=1">',
));
check('a per-locale map is walked', is_array($locales) && count($locales) === 2);
pin('...and each locale is cleaned', '<p>ok</p>', $locales['en_US']);
check('...including the second', stripos($locales['fr_FR'], 'onerror') === false, $locales['fr_FR']);

pin('an empty string stays empty', '', osc_sanitize_html(''));
pin('a plain sentence is untouched', 'Just words.', osc_sanitize_html('Just words.'));
check('a non-string is handed back as-is', osc_sanitize_html(null) === null);

/* ----------------------------------------------------------------------------
 * The public listing editor must not offer a raw-HTML pane. It is not what makes
 * the description safe -- the allow-list above is -- but handing a poster the
 * pane invites exactly what the allow-list then has to take back out.
 * ------------------------------------------------------------------------- */
harness_section('the public editor offers no source view');

require_once __DIR__ . '/../oc-includes/osclass/helpers/hUtils.php';
$basic = json_decode(osc_tinymce_config('basic'), true);
check('the basic preset loads no code plugin', strpos($basic['plugins'], 'code') === false, $basic['plugins']);
check('...and shows no code button', strpos($basic['toolbar'], 'code') === false, $basic['toolbar']);

exit(harness_result());
