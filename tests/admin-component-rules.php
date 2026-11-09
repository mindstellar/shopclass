<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Static rules that keep admin screens on the shared components: no hand-built tab strips,
 * confirm dialogs, status pills, toolbars, empty states or callouts, and no icon in an empty
 * state. Each rule lists the few files that may break it, with the reason.
 *
 * Usage:  php tests/admin-component-rules.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

/** @return list<string> every file under $dir with one of the extensions, sorted */
function rules_files(string $dir, array $exts): array
{
    $out = array();
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = $f->getPathname();
        $ext  = pathinfo($path, PATHINFO_EXTENSION);
        if (in_array($ext, $exts, true) && strpos($path, '/scss/') === false && substr($path, -7) !== '.min.js') {
            $out[] = $path;
        }
    }
    sort($out);
    return $out;
}

$theme   = ABS_PATH . 'oc-admin/themes/modern';
$classes = array(
    ABS_PATH . 'oc-includes/osclass/classes/datatables',
    ABS_PATH . 'oc-includes/osclass/classes/form',
    ABS_PATH . 'oc-includes/osclass/classes/controller/admin',
    ABS_PATH . 'oc-includes/classes/admin',
    ABS_PATH . 'oc-includes/classes/core/form',
);
$php = rules_files($theme, array('php'));
foreach ($classes as $dir) {
    $php = array_merge($php, rules_files($dir, array('php')));
}
$js   = rules_files($theme . '/js', array('js'));
$all  = array_merge($php, $js);
$rel  = static fn (string $p): string => substr($p, strlen(ABS_PATH));

/** @param list<string> $files @param array<string,string> $allow path suffix => reason */
function rules_grep(array $files, string $re, array $allow): array
{
    $hits = array();
    foreach ($files as $path) {
        $src = (string)file_get_contents($path);
        if (!preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $rel = substr($path, strlen(ABS_PATH));
        foreach ($allow as $suffix => $_reason) {
            if (substr($rel, -strlen($suffix)) === $suffix) {
                continue 2;
            }
        }
        foreach ($m[0] as [$text, $off]) {
            $hits[] = $rel . ':' . (substr_count($src, "\n", 0, $off) + 1);
        }
    }
    return $hits;
}

// Tab strips come from osc_admin_tab_nav() and Field::localeStrip().
$tabAllow = array(
    'parts/ui.php'                 => 'osc_admin_tab_nav() builds both strips',
    'classes/admin/ui/Field.php'   => 'localeStrip() and localeTab() own the locale strip',
    'classes/admin/ui/Form.php'    => 'form helper',
);
$tabRules = array(
    'no hand-built .osc-tab strip'     => '/class=\\\\?["\'][^"\']*\bosc-tab(?![\w-])/',
    'no hand-built .ui-osc-tabs strip' => '/class=\\\\?["\'][^"\']*\bui-osc-tabs(?![\w-])/',
    'no hand-built .osc-tabnav list'   => '/class=\\\\?["\'][^"\']*\bosc-tabnav(?:-scroll)?(?![\w-])/',
);
$tabProbe = array(
    '<nav class="osc-tabnav-scroll"><ul id="t" class="osc-tabnav">' => 'no hand-built .osc-tabnav list',
    '<ul class="mb-2 osc-tabnav">'                                  => 'no hand-built .osc-tabnav list',
    'echo "<div class=\\"osc-tab\\">";'                              => 'no hand-built .osc-tab strip',
    "echo '<div id=\"x\" class=\"ui-osc-tabs osc-tab\">';"            => 'no hand-built .ui-osc-tabs strip',
    '<div class="osc-tabs-x">'                                      => '',
);
foreach ($tabProbe as $code => $rule) {
    $ok = $rule === ''
        ? array_filter($tabRules, static fn ($re) => preg_match($re, $code)) === array()
        : (bool)preg_match($tabRules[$rule], $code);
    check('tab rule probe: ' . $code, $ok);
}
foreach ($tabRules as $name => $re) {
    $hits = rules_grep($all, $re, $tabAllow);
    check($name, $hits === array(), implode(', ', $hits));
}

// Confirm dialogs come from osc_admin_confirm_dialog(); these <dialog> ids are not confirmations.
$dialogAllow = array(
    'addDialog'                => 'add form with fields',
    'submissionModal'          => 'record viewer',
    'languageModal'            => 'language editor form',
    'locationModal'            => 'typed-confirm surface fed by script, own test pins it',
    'dialog-watermark-warning' => 'notice with one button',
    'media-preview'            => 'image viewer',
    'oscMediaPicker'           => 'picker',
    'marketDetailDialog'       => 'filled by market.js',
    'catSheet'                 => 'category sheet',
    'catMove'                  => 'move picker',
    'catExpiry'                => 'expiry form',
    'display-filters'          => 'filter form',
    'cz-expand'                => 'customise preview',
    'oe-contact-dialog'        => 'public profile contact form',
);
$bad = array();
foreach ($php as $path) {
    $r = $rel($path);
    if (substr($r, -12) === 'parts/ui.php') {
        continue;
    }
    $src = (string)file_get_contents($path);
    if (!preg_match_all('/<dialog(?=[\s>])/', $src, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($m[0] as [$_t, $off]) {
        $tag = substr($src, $off, 400);
        $tag = substr($tag, 0, (int)strpos($tag . '>', '>'));
        $id  = preg_match('/\bid=["\']([\w-]+)/', $tag, $im) ? $im[1] : '(no id)';
        if (!isset($dialogAllow[$id])) {
            $bad[] = $r . ':' . (substr_count($src, "\n", 0, $off) + 1) . ' #' . $id;
        }
    }
}
check('the dialog rule sees a bare <dialog>', (bool)preg_match('/<dialog(?=[\\s>])/', '<dialog>'));
check('every <dialog> is the helper or on the allow list', $bad === array(), implode(', ', $bad));
$hits = rules_grep($php, '/osc-dialog-danger/', array('parts/ui.php' => 'osc_admin_confirm_dialog() adds it'));
check('no hand-set osc-dialog-danger', $hits === array(), implode(', ', $hits));

// Status pills come from osc_admin_status() / Form::statusBadge().
$hits = rules_grep($all, '/badge bg-/', array());
check('no Bootstrap "badge bg-" pill', $hits === array(), implode(', ', $hits));
$hits = rules_grep($php, '/class=["\'][^"\']*\bosc-status(?![\w-])/', array(
    'classes/admin/ui/Form.php'   => 'statusBadge() builds it',
    'parts/package-ui.php'        => 'package state badge, one helper',
    'parts/market.php'            => 'market badges, one helper',
));
check('no raw osc-status outside the helpers', $hits === array(), implode(', ', $hits));
$hits = rules_grep($js, '/osc-status/', array(
    'js/market.js'       => 'mirrors the market badge',
    'js/dependencies.js' => 'mirrors the package badge',
    'js/location.js'     => 'table rows are built client-side',
));
check('no raw osc-status in other scripts', $hits === array(), implode(', ', $hits));

// List filters, empty states and callouts.
$hits = rules_grep($all, '/(?<![\w-])table-toolbar(?![\w-])/', array(
    'parts/ui.php' => 'docblock says why osc_admin_list_filter() avoids it',
));
check('no table-toolbar', $hits === array(), implode(', ', $hits));
$hits = rules_grep($all, '/(?<![\w-])(builder-empty|osc-empty-icon)(?![\w-])/', array());
check('no builder-empty or osc-empty-icon', $hits === array(), implode(', ', $hits));
$hits = rules_grep($all, '/(?<![\w-])callout-block(?![\w-])/', array(
    'classes/admin/ui/Form.php' => 'Form::callout() builds it',
    'parts/ui.php'              => 'osc_admin_verdict() box',
    'tools/upgrade.php'         => 'verdict box built by script',
));
check('no hand-built callout-block', $hits === array(), implode(', ', $hits));
$hits = rules_grep($all, '/(?<![\w-])callout-(?:danger|warning|info|success)(?![\w-])/', array(
    'classes/admin/ui/Form.php' => 'Form::callout() builds it',
    'parts/ui.php'              => 'osc_admin_verdict() box',
    'tools/upgrade.php'         => 'verdict box built by script',
));
check('no hand-built callout tone class', $hits === array(), implode(', ', $hits));

/**
 * Calls and specs that draw an empty state, each with the source of its argument.
 *
 * @return list<array{int,string}> [line, source of the spec]
 */
function rules_empty_specs(string $src): array
{
    $tokens = array_values(array_filter(
        token_get_all($src),
        static fn ($t) => !is_array($t) || !in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)
    ));
    $text = static fn ($t): string => is_array($t) ? $t[1] : $t;
    $line = static fn ($t): int => is_array($t) ? $t[2] : 0;
    $n    = count($tokens);
    $specs = array();

    // Source of the balanced region starting at $i (an open bracket), and the index after it.
    $region = static function (int $i) use ($tokens, $text, $n): array {
        $depth = 0;
        $out   = '';
        for ($j = $i; $j < $n; $j++) {
            $s = $text($tokens[$j]);
            if ($s === '(' || $s === '[' || $s === '{') {
                $depth++;
            } elseif ($s === ')' || $s === ']' || $s === '}') {
                $depth--;
            }
            $out .= $s . ' ';
            if ($depth === 0) {
                return array($out, $j + 1);
            }
        }
        return array($out, $n);
    };
    // Source of a value up to a comma or close bracket at depth zero.
    $value = static function (int $i) use ($tokens, $text, $n, $region): string {
        $out = '';
        for ($j = $i; $j < $n; $j++) {
            $s = $text($tokens[$j]);
            if ($s === ',' || $s === ')' || $s === ']' || $s === ';') {
                break;
            }
            if ($s === '(' || $s === '[') {
                [$r, $next] = $region($j);
                $out .= $r;
                $j = $next - 1;
                continue;
            }
            $out .= $s . ' ';
        }
        return $out;
    };

    for ($i = 0; $i < $n; $i++) {
        $s = $text($tokens[$i]);
        if (
            ($s === 'osc_admin_empty' || $s === 'osc_admin_table_empty')
            && ($tokens[$i + 1] ?? '') === '(' && $text($tokens[$i - 1] ?? '') !== 'function'
        ) {
            [$r] = $region($i + 1);
            $specs[] = array($line($tokens[$i]), $r);
        } elseif (
            ($s === "'empty'" || $s === '"empty"') && ($tokens[$i + 1][0] ?? 0) === T_DOUBLE_ARROW
        ) {
            $specs[] = array($line($tokens[$i]), $value($i + 2));
        }
    }
    // A spec that is a variable: use the array it was assigned.
    foreach ($specs as $k => [$ln, $source]) {
        if (preg_match('/\$(\w+)/', $source, $v) && strpos($source, 'array') === false && strpos($source, '[') === false) {
            for ($j = 0; $j < $n - 3; $j++) {
                if (
                    $text($tokens[$j]) === '$' . $v[1] && $text($tokens[$j + 1]) === '='
                    && in_array($text($tokens[$j + 2]), array('array', '['), true)
                ) {
                    [$r] = $region($text($tokens[$j + 2]) === 'array' ? $j + 3 : $j + 2);
                    $specs[$k][1] .= $r;
                }
            }
        }
    }
    return $specs;
}

/** Whether a spec's own 'icon' key is set; an action button inside it may keep its icon. */
function rules_spec_has_icon(string $source): bool
{
    // Cut each 'action' => array( ... ) with its balanced brackets.
    while (preg_match('/[\'"]action[\'"]\s*=>\s*(?:array\s*)?[(\[]/', $source, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1];
        $i     = $start + strlen($m[0][0]);
        $depth = 1;
        for ($n = strlen($source); $i < $n && $depth > 0; $i++) {
            $depth += strpos('([', $source[$i]) !== false ? 1 : (strpos(')]', $source[$i]) !== false ? -1 : 0);
        }
        $source = substr($source, 0, $start) . substr($source, $i);
    }
    return (bool)preg_match("/['\"]icon['\"]\s*=>/", $source);
}

// The scanner itself: it must see an icon in each shape a spec takes.
$probe = array(
    "<?php osc_admin_empty(array('icon' => 'bi-x', 'title' => 'a'));"                    => true,
    "<?php \$e = array('icon' => 'bi-x'); osc_admin_empty(\$e);"                          => true,
    "<?php \$t = array('empty' => array('title' => 'a', 'icon' => 'bi-x'));"              => true,
    "<?php \$s = array('icon' => 'bi-x'); \$t = array('empty' => \$s);"                    => true,
    "<?php osc_admin_table_empty(3, array('title' => 'a', 'icon' => 'bi-x'));"            => true,
    "<?php osc_admin_empty(array('title' => 'a', 'action' => array('icon' => 'bi-x')));"  => false,
    "<?php osc_admin_empty(array('title' => 'a'));"                                       => false,
);
foreach ($probe as $code => $expect) {
    $found = false;
    foreach (rules_empty_specs($code) as [$_l, $source]) {
        if (rules_spec_has_icon($source)) {
            $found = true;
        }
    }
    check('scanner ' . ($expect ? 'flags' : 'passes') . ': ' . $code, $found === $expect);
}

$hits = array();
foreach ($php as $path) {
    $src = (string)file_get_contents($path);
    if (stripos($src, 'empty') === false) {
        continue;
    }
    foreach (rules_empty_specs($src) as [$ln, $source]) {
        if (rules_spec_has_icon($source)) {
            $hits[] = $rel($path) . ':' . $ln;
        }
    }
}
check("no 'icon' inside an empty-state spec", $hits === array(), implode(', ', $hits));

exit(harness_result());
