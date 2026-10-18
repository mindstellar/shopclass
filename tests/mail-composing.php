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
 * Pins MailComposing: the old pipeline order (kind filter, generic filter, words, _after) is
 * kept; typed and old listeners share one priority list; a callback on another kind's name does
 * not run; setWord() escapes by default; preview() skips the _after callbacks.
 *
 * DB-free.  Usage: php tests/mail-composing.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Plugins.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\boot\CoreServices;
use mindstellar\container\Container;
use mindstellar\event\Dispatcher;
use mindstellar\mail\event\MailComposing;

function osc_plugins_path()
{
    return ABS_PATH . 'oc-content/plugins/';
}

function osc_apply_filter(string $hook, $value = null, ...$args)
{
    return Plugins::applyFilter($hook, $value, ...$args);
}

function osc_esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function osc_mailBeauty($text, $params)
{
    return str_replace($params[0], $params[1], (string) $text);
}

$c = new Container();
Container::use($c);
CoreServices::register($c);
$events = $c->get(Dispatcher::class);

$make = static fn (string $kind = 'order_placed'): MailComposing => new MailComposing(
    $kind,
    'a@example.test',
    'A',
    'Hi {NAME}',
    '<p>{NAME}</p>',
    [['{NAME}'], ['Ann']],
    [['{NAME}'], ['Ann']],
    null,
    null,
    null,
    ['arg']
);

harness_section('the pipeline');
$log = [];
Plugins::addHook('email_title', static function ($t) use (&$log) {
    $log[] = 'generic:' . $t;

    return $t . '!';
});
Plugins::addHook('email_order_placed_title', static function ($t, $arg) use (&$log) {
    $log[] = 'kind:' . $arg;

    return $t . '?';
}, 6);
Plugins::addHook('email_order_placed_title', static function (MailComposing $e) {
    $e->setSubject($e->subject() . '#');
}, 5);
Plugins::addHook('email_order_placed_title_after', static function ($t, $arg) use (&$log) {
    $log[] = 'after:' . $t;

    return $t . '~';
});
Plugins::addHook('email_order_shipped_title', static function ($t) {
    return 'wrong kind';
});
Plugins::addHook('email_order_shipped_title', static function (MailComposing $e) {
    $e->setSubject('wrong kind');
});
$e = $events->dispatch($make());
pin('the subject template after typed and old listeners by priority', 'Hi {NAME}#?', $e->subject());
pin('render(): generic filter, words, then the after callback', 'Hi Ann#?!~', $e->render()['subject']);
pin('the order the pieces ran', ['kind:arg', 'generic:Hi {NAME}#?', 'after:Hi Ann#?!'], $log);
pin('preview() leaves the after callbacks out', 'Hi Ann#?!', $e->preview()['subject']);
pin('a callback, typed or old, on another kind\'s name does not run', 'Hi {NAME}', $events->dispatch($make('order_refunded'))->subject());

harness_section('words and suppress');
$e = $make();
$e->setWord('{NAME}', 'Bo <b>');
pin('setWord() escapes the body and keeps the subject plain', ['Hi Bo <b>!', '<p>Bo &lt;b&gt;</p>'], [$e->preview()['subject'], $e->preview()['body']]);
$e->setWord('{NAME}', 'Cy', '<i>Cy</i>');
pin('setWord() takes HTML for the body', '<p><i>Cy</i></p>', $e->preview()['body']);
check('suppress() is remembered', !$e->isSuppressed() && ($e->suppress() ?? true) && $e->isSuppressed());
pin('names: 7 kinds, 4 parts', 28, count(MailComposing::legacyNames()));

exit(harness_result());
