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
 * Pins the typed event dispatcher and the hook bridge: priority order, stopPropagation(),
 * listeners on a parent class or interface, lazy [Class::class, 'method'] pairs resolved through
 * the container on the first event, and osc_add_hook() callbacks on a name an event owns: a
 * callback typed with the event gets the event, any other gets the old arguments, and
 * removeHook(), hasHook() and callbacks() reach them.
 *
 * DB-free.  Usage: php tests/event-dispatcher.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Plugins.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\boot\CoreServices;
use mindstellar\container\Container;
use mindstellar\event\Bridged;
use mindstellar\event\Dispatcher;
use mindstellar\event\DispatcherInterface;
use mindstellar\event\Event;
use mindstellar\event\HookBridge;

if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return ABS_PATH . 'oc-content/plugins/';
    }
}

interface EdTagged
{
}

class EdBase extends Event
{
    /** @var list<string> */
    public array $log = [];
}

final class EdChild extends EdBase implements EdTagged
{
}

/** A filter-like event owning two old names: ed_words folds a returned list, ed_ping calls with no return. */
final class EdWords extends Event implements Bridged
{
    /** @var list<string> */
    public array $words = [];

    public function __construct(public string $who = 'ann')
    {
    }

    public static function legacyNames(): array
    {
        return ['ed_words', 'ed_ping'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        if ($name === 'ed_words') {
            $this->words = $fn($this->words, $this->who);
        } else {
            $fn($this->who);
        }
    }
}

final class EdRival extends Event implements Bridged
{
    public static function legacyNames(): array
    {
        return ['ed_words'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
    }
}

class EdListener
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }

    public function onBase(EdBase $e): void
    {
        $e->log[] = 'instance';
    }

    public static function onBaseStatic(EdBase $e): void
    {
        $e->log[] = 'static';
    }

    public function typedWords(EdWords $e): void
    {
        $e->words[] = 'typed-pair';
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    public static function oldWords(array $words, string $who): array
    {
        $words[] = 'old-pair:' . $who;

        return $words;
    }
}

function ed_mark(string $tag): Closure
{
    return static function (EdBase $e) use ($tag): void {
        $e->log[] = $tag;
    };
}

harness_section('Priority');

$d = new Dispatcher(new Container());
check('the dispatcher has the PSR-14 shape', $d instanceof DispatcherInterface);
$d->listen(EdBase::class, ed_mark('at20'), 20);
$d->listen(EdBase::class, ed_mark('at5'));
$d->listen(EdBase::class, ed_mark('atminus1'), -1);
$d->listen(EdBase::class, ed_mark('first7'), 7);
$d->listen(EdBase::class, ed_mark('second7'), 7);
$e = new EdBase();
check('dispatch() returns the same event', $d->dispatch($e) === $e);
pin('lowest priority first, default 5, equal priorities keep the order added', ['atminus1', 'at5', 'first7', 'second7', 'at20'], $e->log);

$same = ed_mark('once');
$d    = new Dispatcher(new Container());
$d->listen(EdBase::class, $same);
$d->listen(EdBase::class, $same, 9);
pin('the same listener on the same class is added once', ['once'], $d->dispatch(new EdBase())->log);
check('remove() reports a listener that was there', $d->remove(EdBase::class, $same));
pin('a removed listener does not run', [], $d->dispatch(new EdBase())->log);
check('remove() reports one that was not', !$d->remove(EdBase::class, $same));

harness_section('Parents and interfaces');

$d = new Dispatcher(new Container());
$d->listen(EdChild::class, ed_mark('child'), 5);
$d->listen(EdBase::class, ed_mark('base'), 5);
$d->listen(EdTagged::class, ed_mark('tagged'), 1);
$d->listen(Event::class, ed_mark('any'), 9);
pin('a child event reaches listeners on its parents and interfaces, by priority then order added', ['tagged', 'child', 'base', 'any'], $d->dispatch(new EdChild())->log);
pin('a parent event does not reach a child listener', ['base', 'any'], $d->dispatch(new EdBase())->log);
check('hasListeners() counts listeners on parents', $d->hasListeners(EdChild::class) && !$d->hasListeners(stdClass::class));
$late = ed_mark('late');
$d->listen(EdBase::class, $late, 0);
pin('a listener added after a dispatch is picked up', ['late', 'tagged', 'child', 'base', 'any'], $d->dispatch(new EdChild())->log);

harness_section('Stopping');

$d = new Dispatcher(new Container());
$d->listen(EdBase::class, ed_mark('one'), 1);
$d->listen(EdBase::class, static function (EdBase $e): void {
    $e->log[] = 'stopper';
    $e->stopPropagation();
}, 2);
$d->listen(EdBase::class, ed_mark('three'), 3);
$e = $d->dispatch(new EdBase());
pin('stopPropagation() ends the run', ['one', 'stopper'], $e->log);
check('and the event says so', $e->isPropagationStopped());
$stopped = new EdBase();
$stopped->stopPropagation();
pin('an event stopped before dispatch reaches no listener', [], $d->dispatch($stopped)->log);
$plain = new stdClass();
$d->listen(stdClass::class, static function (stdClass $o): void {
    $o->seen = true;
});
check('any object can be dispatched', ($d->dispatch($plain)->seen ?? false) === true);

harness_section('Lazy pairs');

$c = new Container();
$d = new Dispatcher($c);
EdListener::$built = 0;
$d->listen(EdBase::class, [EdListener::class, 'onBase'], 2);
$d->listen(EdBase::class, EdListener::class . '::onBaseStatic', 1);
pin('nothing is built when a pair is added', 0, EdListener::$built);
pin('a static method runs as is, an instance method on the container\'s object', ['static', 'instance'], $d->dispatch(new EdBase())->log);
pin('the object is built once, on the first event', 1, EdListener::$built);
$d->dispatch(new EdBase());
pin('and shared after', 1, EdListener::$built);

$fake = new class () extends EdListener {
    public function onBase(EdBase $e): void
    {
        $e->log[] = 'fake';
    }
};
$c2 = new Container();
$c2->instance(EdListener::class, $fake);
$d2 = new Dispatcher($c2);
$d2->listen(EdBase::class, [EdListener::class, 'onBase']);
pin('a fake put in the container takes the pair', ['fake'], $d2->dispatch(new EdBase())->log);

$warned = '';
set_error_handler(static function (int $no, string $msg) use (&$warned): bool {
    $warned = $msg;

    return true;
}, E_USER_WARNING);
$d3 = new Dispatcher(new Container());
$d3->listen(EdBase::class, [EdListener::class, 'noSuchMethod'], 1);
$d3->listen(EdBase::class, ed_mark('after'), 2);
pin('an invalid listener is skipped and the rest still run', ['after'], $d3->dispatch(new EdBase())->log);
check('with a warning', strpos($warned, 'Invalid listener') !== false);
restore_error_handler();

harness_section('Bridge: owning names');

$root = Container::root();
$c    = new Container();
Container::use($c);

Plugins::addHook('ed_words', static function ($words) {
    $words[] = 'early';

    return $words;
}, 3);
CoreServices::register($c);
$bridge = HookBridge::active();
$events = $c->get(Dispatcher::class);
$core = $bridge->owners();
check('core events own their names', $core !== [] && array_keys($core) === array_merge(...array_map(static fn (string $e): array => $e::legacyNames(), CoreServices::events())));
$bridge->own(EdWords::class);
pin('the event owns its names', ['ed_words' => EdWords::class, 'ed_ping' => EdWords::class], array_diff_key($bridge->owners(), $core));
pin('a callback added before the name was owned moves over', ['early'], $events->dispatch(new EdWords())->words);
pin('and is gone from the old store', 'xs', Plugins::applyFilter('ed_words', 'xs'));

$thrown = '';
try {
    $bridge->own(EdRival::class);
} catch (LogicException $e) {
    $thrown = $e->getMessage();
}
check('a second event cannot own a name another owns', strpos($thrown, 'ed_words') !== false);
$thrown = '';
try {
    $bridge->own(EdBase::class);
} catch (InvalidArgumentException $e) {
    $thrown = $e->getMessage();
}
check('a class that is not Bridged cannot own names', $thrown !== '');
$bridge->own(EdWords::class);
check('owning the same names again is harmless', $bridge->eventFor('ed_ping') === EdWords::class);

harness_section('Bridge: old and typed callbacks');

harness_reset_container();
$c      = Container::root();
$bridge = HookBridge::active();
$bridge->own(EdWords::class);
$events = $c->get(Dispatcher::class);

EdListener::$built = 0;
$old = static function ($words, $who) {
    $words[] = 'old:' . $who;

    return $words;
};
osc_add_filter_like('ed_words', $old, 4);
osc_add_filter_like('ed_words', static function (EdWords $e): void {
    $e->words[] = 'typed';
}, 2);
osc_add_filter_like('ed_words', [EdListener::class, 'typedWords'], 6);
osc_add_filter_like('ed_words', [EdListener::class, 'oldWords'], 8);
osc_add_filter_like('ed_words', static function ($words) {
    $words[] = 'untyped';

    return $words;
}, 1);
$events->listen(EdWords::class, static function (EdWords $e): void {
    $e->words[] = 'direct';
}, 7);
pin('nothing is built when a pair is added through osc_add_filter', 0, EdListener::$built);
pin(
    'old and typed callbacks on one name run in one list, by priority',
    ['untyped', 'typed', 'old:bo', 'typed-pair', 'direct', 'old-pair:bo'],
    $events->dispatch(new EdWords('bo'))->words
);
check('hasHook() sees callbacks on an owned name', Plugins::hasHook('ed_words'));
pin('callbacks() lists them by priority', 5, count(Plugins::callbacks('ed_words')));
check('callbacks() puts each in its priority place', Plugins::callbacks('ed_words')[2] === $old);
check('getActive() does not carry owned names', !isset(Plugins::getActive()['ed_words']));

osc_add_filter_like('ed_words', $old, 9);
pin('the same callback on the same name is added once', 5, count(Plugins::callbacks('ed_words')));
Plugins::removeHook('ed_words', $old);
pin('removeHook() takes an old callback off an owned name', 4, count(Plugins::callbacks('ed_words')));
check('and it no longer runs', !in_array('old:bo', $events->dispatch(new EdWords('bo'))->words, true));
Plugins::removeHook('ed_words', [EdListener::class, 'typedWords']);
check('removeHook() takes a pair off too', !in_array('typed-pair', $events->dispatch(new EdWords('bo'))->words, true));

$pinged = [];
osc_add_filter_like('ed_ping', static function ($who) use (&$pinged): void {
    $pinged[] = 'old:' . $who;
}, 5);
osc_add_filter_like('ed_ping', static function (EdWords $e) use (&$pinged): void {
    $pinged[] = 'typed:' . $e->who;
    $e->stopPropagation();
}, 4);
$events->dispatch(new EdWords('cy'));
pin('a typed listener that stops the event keeps later old callbacks from running', ['typed:cy'], $pinged);
check('a name nothing owns or uses has no hook', !Plugins::hasHook('ed_unused') && $bridge->eventFor('ed_unused') === null);

Plugins::addHook('ed_plain', static fn ($v) => $v . '!', 5);
pin('a name no event owns keeps the old path', 'hi!', Plugins::applyFilter('ed_plain', 'hi'));

Container::use($root);

/**
 * osc_add_filter() as hPlugins.php defines it, without loading the helpers.
 *
 * @param mixed $fn
 */
function osc_add_filter_like(string $hook, $fn, int $priority = 5): void
{
    Plugins::addHook($hook, $fn, $priority);
}

exit(harness_result());
