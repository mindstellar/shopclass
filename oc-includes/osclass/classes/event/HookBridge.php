<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\event;

use mindstellar\container\Container;

/**
 * Turns osc_add_hook() / osc_add_filter() callbacks on a name a typed event owns into listeners of
 * that event, at the same priority. A callback whose first parameter type is an event class gets
 * the event; any other callback is old-style and goes through the event's applyLegacy().
 */
final class HookBridge
{
    /** @var array<string,class-string<Bridged>> hook name => the event class that owns it */
    private array $owners = [];

    /** @var array<string,list<array{fn:mixed,priority:int,listener:\Closure}>> hook name => callbacks added on it */
    private array $bound = [];

    public function __construct(private Dispatcher $events)
    {
    }

    /**
     * The site's bridge, or null before the core services are registered.
     */
    public static function active(): ?self
    {
        $container = Container::root();

        return $container->has(self::class) ? $container->get(self::class) : null;
    }

    /**
     * Let $eventClass own the names its legacyNames() gives. Callbacks already added on one of
     * those names with osc_add_hook() move over, keeping their priority.
     *
     * @param class-string<Bridged> $eventClass
     */
    public function own(string $eventClass): void
    {
        if (!is_subclass_of($eventClass, Bridged::class)) {
            throw new \InvalidArgumentException(sprintf('%s does not implement %s.', $eventClass, Bridged::class));
        }
        foreach ($eventClass::legacyNames() as $name) {
            $owner = $this->owners[$name] ?? null;
            if ($owner !== null && $owner !== $eventClass) {
                throw new \LogicException(sprintf('The hook "%s" is already owned by %s.', $name, $owner));
            }
        }
        foreach ($eventClass::legacyNames() as $name) {
            $this->owners[$name] = $eventClass;
            if ($this === self::active()) {
                foreach (\Plugins::detach($name) as $priority => $callbacks) {
                    foreach ($callbacks as $fn) {
                        $this->add($name, $fn, (int) $priority);
                    }
                }
            }
        }
    }

    /**
     * The event class that owns $name, or null for an old hook.
     *
     * @return class-string<Bridged>|null
     */
    public function eventFor(string $name): ?string
    {
        return $this->owners[$name] ?? null;
    }

    /**
     * Every hook name an event owns, with its event class.
     *
     * @return array<string,class-string<Bridged>>
     */
    public function owners(): array
    {
        return $this->owners;
    }

    /**
     * Add a callback on an owned name. The same callback on the same name is added once.
     *
     * @param mixed $fn
     */
    public function add(string $name, $fn, int $priority = 5): void
    {
        $eventClass = $this->eventFor($name);
        if ($eventClass === null) {
            throw new \LogicException(sprintf('No event owns the hook "%s".', $name));
        }
        foreach ($this->bound[$name] ?? [] as $entry) {
            if ($entry['fn'] === $fn) {
                return;
            }
        }
        $typed    = null;
        $listener = function (object $event) use ($name, $fn, &$typed): void {
            $callable = $this->events->resolve($fn);
            if ($callable === null) {
                trigger_error('Invalid callable on hook ' . $name, E_USER_WARNING);

                return;
            }
            $typed ??= self::takesEvent($callable, $event);
            if ($typed) {
                $callable($event);
            } elseif ($event instanceof Bridged) {
                $event->applyLegacy($name, $callable);
            }
        };
        $this->bound[$name][] = ['fn' => $fn, 'priority' => $priority, 'listener' => $listener];
        $this->events->listen($eventClass, $listener, $priority);
    }

    /**
     * Take a callback off an owned name, whatever priority it was added at. Returns whether it was there.
     *
     * @param mixed $fn
     */
    public function remove(string $name, $fn): bool
    {
        $eventClass = $this->eventFor($name);
        $removed    = false;
        foreach ($this->bound[$name] ?? [] as $i => $entry) {
            if ($entry['fn'] == $fn) {
                if ($eventClass !== null) {
                    $this->events->remove($eventClass, $entry['listener']);
                }
                unset($this->bound[$name][$i]);
                $removed = true;
            }
        }
        if (isset($this->bound[$name])) {
            $this->bound[$name] = array_values($this->bound[$name]);
        }

        return $removed;
    }

    /**
     * Whether any callback is added on $name.
     */
    public function has(string $name): bool
    {
        return !empty($this->bound[$name]);
    }

    /**
     * The callbacks added on $name, in the order they run.
     *
     * @return list<mixed>
     */
    public function callbacks(string $name): array
    {
        $entries = $this->bound[$name] ?? [];
        usort($entries, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return array_map(static fn (array $entry) => $entry['fn'], $entries);
    }

    /**
     * Whether $callable's first parameter is typed as an event class, or as a class or interface $event is.
     */
    public static function takesEvent(callable $callable, object $event): bool
    {
        if (is_array($callable)) {
            $reflection = new \ReflectionMethod($callable[0], (string) $callable[1]);
        } elseif (is_string($callable) && strpos($callable, '::') !== false) {
            $reflection = new \ReflectionMethod($callable);
        } elseif (is_object($callable) && !$callable instanceof \Closure) {
            $reflection = new \ReflectionMethod($callable, '__invoke');
        } else {
            $reflection = new \ReflectionFunction(\Closure::fromCallable($callable));
        }
        $first = $reflection->getParameters()[0] ?? null;
        $type  = $first !== null ? $first->getType() : null;
        if ($type === null) {
            return false;
        }
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $named) {
            if (!$named instanceof \ReflectionNamedType || $named->isBuiltin()) {
                continue;
            }
            $class = $named->getName();
            if ($event instanceof $class || is_a($class, Event::class, true) || is_a($class, Bridged::class, true)) {
                return true;
            }
        }

        return false;
    }
}
