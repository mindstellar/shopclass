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
 * The form of a declared settings page: one section per group, spaced by the form's stack,
 * and the permalink screen's disclosure and server rules on the same sections.
 *
 * Usage:  php tests/settings-form-structure.php
 */

declare(strict_types=1);

use mindstellar\admin\form\PermalinkSettingsScreen;
use mindstellar\admin\ui\PageState;
use mindstellar\admin\ui\SettingsForm;

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

$GLOBALS['fired']   = array();
$GLOBALS['nginx']   = true;
$GLOBALS['filters'] = array();

function osc_run_hook($hook, ...$args)
{
    if ($hook === 'settings_page_after_group') {
        $GLOBALS['fired'][] = $args;
        echo '<b class="after-group" data-index="' . (int)$args[2] . '"></b>';
    }
}

function osc_add_hook($hook, $fn, $priority = 5)
{
}

function osc_add_filter($hook, $fn, $priority = 5)
{
}

function osc_apply_filter($hook, $content = '', ...$args)
{
    return isset($GLOBALS['filters'][$hook]) ? $GLOBALS['filters'][$hook]($content, ...$args) : $content;
}

function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/index.php';
}

function osc_server_is_nginx()
{
    return $GLOBALS['nginx'];
}

function osc_server_rewrite_rules()
{
    return 'rewrite <rules>;';
}

function osc_base_path()
{
    return $GLOBALS['basePath'];
}

class Params
{
    public static function getParam($key, $a = false, $b = true)
    {
        return '';
    }
}

require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

/** @param array<string,mixed> $field */
function declared(array $field): array
{
    return $field + array('type' => 'text', 'label' => ucfirst((string)$field['name']));
}

/** @param list<array<string,mixed>> $groups */
function page_of(array $groups): array
{
    return array('id' => 'demo', 'groups' => $groups);
}

function group(string $title, string $intro, string ...$names): array
{
    return array(
        'title'  => $title,
        'intro'  => $intro,
        'fields' => array_map(static fn (string $n) => declared(array('name' => $n)), $names),
    );
}

/** @param array<string,mixed> $opts */
function drawn(array $page, array $opts = array()): DOMXPath
{
    ob_start();
    SettingsForm::render($page, array(), $opts);

    return parse((string)ob_get_clean());
}

function parse(string $html): DOMXPath
{
    $GLOBALS['last'] = $html;
    $doc             = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><!doctype html><body>' . $html . '</body>', LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    return new DOMXPath($doc);
}

function sections(string $inside = ''): string
{
    return $inside . "//section[contains(concat(' ', @class, ' '), ' osc-section ')]";
}

function texts(DOMXPath $x, string $query): array
{
    $out = array();
    foreach ($x->query($query) as $node) {
        $out[] = trim($node->textContent);
    }

    return $out;
}

function tags_balanced(string $html, string $tag): bool
{
    return preg_match_all('~<' . $tag . '[\s>]~', $html) === substr_count($html, '</' . $tag . '>');
}

$three = page_of(array(
    group('General', 'About the site', 'site_name', 'site_mail'),
    group('Sharing', '', 'share_on'),
    group('Limits', 'How much is allowed', 'max_items'),
));

harness_section('three groups under a header title');
PageState::setHeaderTitle('Settings');
$x = drawn($three);

pin('three sections', 3, $x->query(sections())->length);
pin('in order, each with its own anchor', array('s-general', 's-sharing', 's-limits'), array_map(
    static fn (DOMElement $n) => $n->getAttribute('id'),
    iterator_to_array($x->query(sections()))
));
pin('h2 headings', array('General', 'Sharing', 'Limits'), texts($x, sections() . "/h2[contains(@class, 'osc-section-title')]"));
pin('no id repeated on a heading', 0, $x->query(sections() . '/h2[@id]')->length);
pin('intros, only where declared', array('About the site', 'How much is allowed'), texts($x, sections() . "/p[contains(@class, 'form-intro')]"));
pin('the form is a stack', 1, $x->query("//form/fieldset/div[contains(@class, 'form-horizontal') and contains(@class, 'osc-stack')]")->length);
pin('sections are direct children of the stack', 3, $x->query("//div[contains(@class, 'osc-stack')]/section")->length);
foreach (array('General' => 'site_name', 'Sharing' => 'share_on', 'Limits' => 'max_items') as $title => $name) {
    pin(
        $name . ' sits in the body of ' . $title,
        1,
        $x->query(sections() . "[h2='" . $title . "']/div[@class='osc-section-body']//input[@name='" . $name . "']")->length
    );
}
pin('the save row follows the last section', 1, $x->query("//div[contains(@class, 'osc-stack')]/section[last()]/following-sibling::div[contains(@class, 'form-actions')]")->length);
pin('and is not inside a section', 0, $x->query("//section//div[contains(@class, 'form-actions')]")->length);
check('no separate-top', strpos($GLOBALS['last'], 'separate-top') === false);

harness_section('settings_page_after_group');
pin('fires once per group', 3, count($GLOBALS['fired']));
pin('with the page id, the group and its index', array('demo', 'Sharing', 1), array($GLOBALS['fired'][1][0], $GLOBALS['fired'][1][1]['title'], $GLOBALS['fired'][1][2]));
foreach (array('s-general', 's-sharing', 's-limits') as $index => $anchor) {
    pin(
        'its output lands in the body of ' . $anchor,
        1,
        $x->query("//section[@id='" . $anchor . "']/div[@class='osc-section-body']/b[@data-index='" . $index . "']")->length
    );
}

harness_section('a first group named like the page');
$x = drawn(page_of(array(group('Settings', 'Intro', 'a'), group('Other', '', 'b'))));
pin('keeps its anchor', 1, $x->query("//section[@id='s-settings']")->length);
pin('draws no heading', array('Other'), texts($x, '//h2'));
pin('keeps its intro', array('Intro'), texts($x, "//section[@id='s-settings']/p"));
$x = drawn(page_of(array(group('Other', '', 'a'), group('Settings', '', 'b'))));
pin('only the first group is taken for the title', array('Other', 'Settings'), texts($x, '//h2'));

harness_section("'titles' => false");
$x = drawn($three, array('titles' => false));
pin('still three sections', 3, $x->query(sections())->length);
pin('no headings', 0, $x->query('//h2 | //h3')->length);
pin('the anchors stay', 1, $x->query("//section[@id='s-limits']")->length);
pin('the intros stay', array('About the site', 'How much is allowed'), texts($x, sections() . '/p'));

harness_section('groups with nothing to say');
$x = drawn(page_of(array(group('', '', 'a'))));
pin('an untitled group is a section', 1, $x->query(sections() . "[not(@id)]/div[@class='osc-section-body']//input[@name='a']")->length);
pin('with no heading', 0, $x->query('//h2 | //h3')->length);

harness_section('multipart');
$image = page_of(array(array('title' => 'Brand', 'intro' => '', 'fields' => array(declared(array('name' => 'logo', 'type' => 'image'))))));
pin('a page with an image field posts multipart', 'multipart/form-data', drawn($image)->query('//form')->item(0)->getAttribute('enctype'));
pin('a page without one does not', '', drawn($three)->query('//form')->item(0)->getAttribute('enctype'));

harness_section('admin_form_render_field');
$GLOBALS['filters']['admin_form_render_field'] = static function (array $field, $pageId) {
    return array('label' => 'Relabelled on ' . $pageId) + $field;
};
$x = drawn(page_of(array(group('General', '', 'a'))));
pin('the filter still applies', array('Relabelled on demo'), texts($x, '//label'));
unset($GLOBALS['filters']['admin_form_render_field']);

harness_section('permalinks: server rules');
$rules = new ReflectionMethod(PermalinkSettingsScreen::class, 'serverRules');
$draw  = static function () use ($rules): DOMXPath {
    ob_start();
    $rules->invoke(null, true);

    return parse((string)ob_get_clean());
};

$x = $draw();
pin('nginx rules are a section', 1, $x->query("//div[@class='server-config']/section[@id='s-server-rules-nginx']")->length);
pin('with its heading', array('Server rules (nginx)'), texts($x, '//section/h2'));
pin('and an intro', 1, $x->query("//section/p[contains(@class, 'osc-section-intro')]")->length);
pin('the rules inside the body, escaped', array('rewrite <rules>;'), texts($x, "//section/div[@class='osc-section-body']/div[@class='server-config-grid']/div[@class='server-config-block']/pre"));
check('no spaced margin', strpos($GLOBALS['last'], 'separate-top') === false);

$GLOBALS['nginx']    = false;
$GLOBALS['basePath'] = sys_get_temp_dir() . '/osc-permalink-' . getmypid() . '/';
mkdir($GLOBALS['basePath']);
$x = $draw();
pin('.htaccess rules are a section', 1, $x->query("//div[@class='server-config']/section[@id='s-server-rules-htaccess']")->length);
pin('one sub-section with no file yet', array('What your .htaccess file should look like'), texts($x, "//section[contains(@class, 'server-config-block')]/h3[contains(@class, 'osc-subsection-title')]"));
pin('which has no anchor of its own', 0, $x->query("//section[contains(@class, 'server-config-block')][@id]")->length);

file_put_contents($GLOBALS['basePath'] . '.htaccess', 'old <file>');
$x = $draw();
pin('two sub-sections beside a file', array('Your current .htaccess file', 'What it should look like'), texts($x, "//div[@class='server-config-grid']/section/h3"));
pin('each with its text in its body', array('old <file>', 'rewrite <rules>;'), texts($x, "//div[@class='server-config-grid']/section/div[@class='osc-section-body']/pre"));
unlink($GLOBALS['basePath'] . '.htaccess');
rmdir($GLOBALS['basePath']);

harness_section('permalinks: the disclosure');
$GLOBALS['nginx'] = true;
$page              = osc_settings_page(PermalinkSettingsScreen::register());
ob_start();
SettingsForm::render($page, array('rewrite_enabled' => 1));
$html = (string)ob_get_clean();
$x    = parse($html);

foreach (array('section', 'details', 'div', 'form') as $tag) {
    check('every <' . $tag . '> is closed', tags_balanced($html, $tag));
}
$inside = "//details[@class='rules-disclosure']/div[contains(@class, 'osc-body')]/section";
pin('the disclosure holds a stack of sections', 5, $x->query($inside)->length);
pin(
    'in the order the screen declares them',
    array('s-listings-pages-amp-categories', 's-search', 's-contact-feed-amp-language', 's-listing-actions', 's-user-account'),
    array_map(static fn (DOMElement $n) => $n->getAttribute('id'), iterator_to_array($x->query($inside)))
);
pin('each field is in its own section', 1, $x->query("//section[@id='s-search']/div[@class='osc-section-body']//input[@name='rewrite_search_url']")->length);
pin('none of it spills into the next', 0, $x->query("//section[@id='s-search']/div[@class='osc-section-body']//input[@name='rewrite_contact']")->length);
pin('the server rules sit after the disclosure', 1, $x->query("//div[@id='custom_rules']/following-sibling::div[@class='server-config']")->length);
check('no separate-top on the screen', strpos($html, 'separate-top') === false);

exit(harness_result());
