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
use mindstellar\container\ContainerInterface;

/**
 * Runs the listeners of an event, lowest priority first; equal priorities keep the order they were added in.
 * A listener on a parent class or interface runs for every event below it. A [Class::class, 'method'] pair
 * is kept as is and resolved on the first event: a static method is called as is, an instance method on
 * the container's Class.
 */
final class Dispatcher implements DispatcherInterface
{
    /** @var array<string,list<array{0:int,1:int,2:mixed}>> event class => [priority, order added, listener] */
    private array $listeners = [];

    /** @var array<string,list<mixed>> event class => its listeners in run order, parents included */
    private array $runOrder = [];

    /** @var array<string,callable> 'Class::method' => the callable it resolved to */
    private array $resolved = [];

    private int $added = 0;

    /**
     * @param ContainerInterface|null $container where instance methods of lazy pairs come from; the root one when null
     */
    public function __construct(private ?ContainerInterface $container = null)
    {
    }

    /**
     * Add a listener for $eventClass, its subclasses and, for an interface, every class that implements it.
     * Adding the same listener to the same class again does nothing.
     *
     * @param mixed $listener a callable, or a [Class::class, 'method'] pair or 'Class::method' string
     */
    public function listen(string $eventClass, $listener, int $priority = 5): void
    {
        foreach ($this->listeners[$eventClass] ?? [] as $entry) {
            if ($entry[2] === $listener) {
                return;
            }
        }
        $this->listeners[$eventClass][] = [$priority, $this->added++, $listener];
        $this->runOrder                 = [];
    }

    /**
     * Take a listener off $eventClass. Returns whether it was there.
     *
     * @param mixed $listener the value given to listen()
     */
    public function remove(string $eventClass, $listener): bool
    {
        foreach ($this->listeners[$eventClass] ?? [] as $i => $entry) {
            if ($entry[2] === $listener) {
                array_splice($this->listeners[$eventClass], $i, 1);
                $this->runOrder = [];

                return true;
            }
        }

        return false;
    }

    /**
     * Whether anything listens to $eventClass, counting listeners on its parents and interfaces.
     */
    public function hasListeners(string $eventClass): bool
    {
        return $this->listenersFor($eventClass) !== [];
    }

    /**
     * The listeners an event of $eventClass reaches, in the order they run.
     *
     * @return list<mixed>
     */
    public function listenersFor(string $eventClass): array
    {
        if (isset($this->runOrder[$eventClass])) {
            return $this->runOrder[$eventClass];
        }
        $types = [$eventClass];
        if (class_exists($eventClass) || interface_exists($eventClass)) {
            $types = array_merge($types, array_values(class_parents($eventClass) ?: []), array_values(class_implements($eventClass) ?: []));
        }
        $entries = [];
        foreach ($types as $type) {
            foreach ($this->listeners[$type] ?? [] as $entry) {
                $entries[] = $entry;
            }
        }
        usort($entries, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $this->runOrder[$eventClass] = array_map(static fn (array $entry) => $entry[2], $entries);
    }

    public function dispatch(object $event): object
    {
        $stoppable = $event instanceof Event;
        foreach ($this->listenersFor(get_class($event)) as $listener) {
            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }
            $callable = $this->resolve($listener);
            if ($callable === null) {
                trigger_error('Invalid listener on ' . get_class($event), E_USER_WARNING);
                continue;
            }
            $callable($event);
        }

        return $event;
    }

    /**
     * What a listener value calls: a callable as is, a [Class::class, 'method'] pair or 'Class::method'
     * string through the container when the method is not static. Null when it calls nothing.
     *
     * @param mixed $listener
     */
    public function resolve($listener): ?callable
    {
        $pair = null;
        if (is_array($listener) && count($listener) === 2 && is_string($listener[0] ?? null) && is_string($listener[1] ?? null)) {
            $pair = $listener;
        } elseif (is_string($listener) && strpos($listener, '::') !== false) {
            $pair = explode('::', $listener, 2);
        }
        if ($pair === null) {
            return is_callable($listener) ? $listener : null;
        }

        $key = $pair[0] . '::' . $pair[1];
        if (!isset($this->resolved[$key])) {
            if (!method_exists($pair[0], $pair[1])) {
                // A class that serves the name through __callStatic is called as given.
                return is_callable($listener) ? $listener : null;
            }
            $method = new \ReflectionMethod($pair[0], $pair[1]);
            if (!$method->isPublic()) {
                return null;
            }
            $target = $method->isStatic() ? $pair[0] : ($this->container ?? Container::root())->get($pair[0]);
            $this->resolved[$key] = [$target, $pair[1]];
        }

        return $this->resolved[$key];
    }
}
