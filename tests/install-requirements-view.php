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
 * Pins installer step 1: one verdict line with the checks folded away when all pass, only the
 * failures (with their fixes) when something does not, and no inline handlers in the shell.
 *
 * DB-free.  Usage: php tests/install-requirements-view.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

function __($text)
{
    return $text;
}

function _n($single, $plural, $count)
{
    return (int) $count === 1 ? $single : $plural;
}

function osc_esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$gui = ABS_PATH . 'oc-includes/osclass/installer/gui';

$rows = static function (int $total, array $failAt = array()): array {
    $out = array();
    for ($i = 1; $i <= $total; $i++) {
        $bad = in_array($i, $failAt, true);
        $out[] = array(
            'requirement' => 'Check number <code>' . $i . '</code>',
            'fn'          => !$bad,
            'solution'    => $bad ? 'Fix number <a href="https://example.test/' . $i . '">' . $i . '</a>' : '',
        );
    }

    return $out;
};
$render = static function (array $requirements) use ($gui): string {
    ob_start();
    include $gui . '/install-requirements.php';

    return (string) ob_get_clean();
};
$dom = static function (string $html): DOMXPath {
    $d = new DOMDocument();
    @$d->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');

    return new DOMXPath($d);
};

harness_section('All checks pass');
$html = $render($rows(13));
$x = $dom($html);
check('partial exists and renders', is_file($gui . '/install-requirements.php') && $html !== '');
check('one verdict, not marked bad', $x->query('//*[contains(concat(" ",@class," ")," ins-verdict ")]')->length === 1 && $x->query('//*[contains(@class,"is-bad")]')->length === 0);
check('verdict says all 13 passed', str_contains($html, 'All 13 checks passed.'));
check('no problem list', $x->query('//*[contains(@class,"ins-problems")]')->length === 0);
check('all 13 labels sit inside details.ins-reqs-more', $x->query('//details[contains(@class,"ins-reqs-more")]//ul[contains(@class,"ins-reqs")]/li')->length === 13 && substr_count($html, 'Check number') === 13);
check('summary names the count', str_contains($html, 'Show all 13 checks') && str_contains($html, 'Hide the checks'));
check('have-these-ready block has 2 items', str_contains($html, 'Have these ready') && $x->query('//*[contains(@class,"ins-ready")]//ol/li')->length === 2);
check('each ready item has one child so the grid keeps two columns', $x->query('//*[contains(@class,"ins-ready")]//ol/li[count(node()[not(self::text()[normalize-space()=""])]) = 1]/span')->length === 2);

harness_section('Two of 13 fail');
$html = $render($rows(13, array(3, 9)));
$x = $dom($html);
check('verdict is bad and counts the failures', $x->query('//*[contains(concat(" ",@class," ")," ins-verdict ") and contains(concat(" ",@class," ")," is-bad ")]')->length === 1 && str_contains($html, '2 things need fixing first'));
check('exactly 2 problems, each with its fix', $x->query('//ul[contains(@class,"ins-problems")]/li')->length === 2 && $x->query('//*[contains(@class,"ins-problem-fix")]')->length === 2 && str_contains($html, 'Fix number'));
check('no passing label is listed', !str_contains($html, 'Check number <code>1</code>') && !str_contains($html, 'Check number <code>13</code>') && $x->query('//details')->length === 0);
check('passed line says 11', str_contains($html, '11 other checks passed.'));
check('no "have these ready" on failure', !str_contains($html, 'Have these ready'));

harness_section('One fails');
$html = $render($rows(13, array(5)));
check('singular verdict', str_contains($html, '1 thing needs fixing first'));
check('passed line says 12', str_contains($html, '12 other checks passed.'));
$html = $render($rows(1, array(1)));
check('passed line omitted when nothing else passed', !str_contains($html, 'ins-passed-line'));

harness_section('A failure with its own title');
$req = $rows(3, array(2));
$req[1]['problem'] = 'The <code>oc-content/downloads</code> folder can&#039;t be written to';
$html = $render($req);
check('the failed row leads with the problem, not the passed-state label', str_contains($html, '<p class="ins-problem-title">The <code>oc-content/downloads</code> folder') && !str_contains($html, 'Check number <code>2</code>'));
$src = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/install-functions.php');
check('no fix tells the owner to run chmod or mkdir', !str_contains($src, 'chmod 0755') && !str_contains($src, '<code>mkdir'));

harness_section('Shell source');
$src = (string) file_get_contents($gui . '/install.php');
check('no inline onclick', !str_contains($src, 'onclick='));
check('step 1 form includes the partial', str_contains($src, 'id="ins-requirements-form"') && str_contains($src, 'install-requirements.php'));
check('check again is a link', str_contains($src, 'href="install.php?step=1"'));
check('check again is the main button while Continue stays disabled', (bool) preg_match('/<button type="submit" class="ins-btn ins-btn-secondary" disabled>.*?<\/button>\s*<a class="ins-btn ins-btn-primary" id="ins-check-again" href="install.php\?step=1">/s', $src));
check('step count string present', str_contains($src, 'Step %1$s of %2$s'));
check('locale select is in the top bar and tied to the form', str_contains($src, 'form="ins-requirements-form"') && strpos($src, 'id="install_locale"') < strpos($src, 'class="ins-card"'));
check('rail label for step 2 is Database', (bool) preg_match("/2 => __\('Database'\)/", $src));
$store = (string) preg_replace('/window\.insTheme = \{.*?\n        \};/s', '', $src);
check('window.insTheme is defined', str_contains($src, 'window.insTheme = {'));
check('localStorage appears only inside the insTheme definition', !str_contains($store, 'localStorage'));
check('i18n carries checking and creatingTables', str_contains($src, "'checking'") && str_contains($src, "'creatingTables'"));

exit(harness_result());
