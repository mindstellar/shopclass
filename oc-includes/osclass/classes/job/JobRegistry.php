<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\job;

use InvalidArgumentException;

/**
 * Which callable runs which job type. Core registers `storage.*` and `category.*` through
 * `osc_job_register_handler()`, the same call a plugin uses.
 *
 * A type is `namespace.name` (lower-case letters, digits, underscores, dots); the namespace
 * is required so a plugin's `invoice.send` cannot collide with another plugin's or core's.
 */
final class JobRegistry
{
    /** @var array<string,callable> type => handler */
    private static $handlers = array();

    /** @var array<string,array{name:string,detail:?callable}> type => how the admin names it */
    private static $names = array();

    /**
     * Route $type to $handler.
     *
     * Registering a type twice replaces the first handler, so a plugin can override
     * one of core's deliberately. The last registration before the worker runs wins.
     *
     * @param string   $type    namespaced, e.g. 'invoice.send'
     * @param callable $handler fn(Job $job): void -- throw to fail the job
     *
     * @return void
     * @throws InvalidArgumentException on a malformed type
     */
    public static function register(string $type, callable $handler): void
    {
        self::assertType($type);
        self::$handlers[$type] = $handler;
    }

    /**
     * Forget a registration. Mostly for tests; a plugin that wants to replace a
     * handler should just register over it.
     *
     * @param string $type
     *
     * @return void
     */
    public static function forget(string $type): void
    {
        unset(self::$handlers[$type]);
    }

    /**
     * @param string $type
     *
     * @return bool
     */
    public static function has(string $type): bool
    {
        return isset(self::$handlers[$type]);
    }

    /**
     * @param string $type
     *
     * @return callable|null null when nothing has registered for $type
     */
    public static function handler(string $type): ?callable
    {
        return self::$handlers[$type] ?? null;
    }

    /**
     * Give $type a name the admin can read, e.g. "Empty a category", and optionally
     * fn(array $payload): string for the one job's details, e.g. "Cars (#12)".
     *
     * @param string        $type
     * @param string        $name
     * @param callable|null $detail
     *
     * @return void
     * @throws InvalidArgumentException on a malformed type
     */
    public static function describe(string $type, string $name, ?callable $detail = null): void
    {
        self::assertType($type);
        self::$names[$type] = array('name' => $name, 'detail' => $detail);
    }

    /**
     * The readable name of $type, or the type itself when none was given.
     *
     * @param string $type
     *
     * @return string
     */
    public static function name(string $type): string
    {
        return self::$names[$type]['name'] ?? $type;
    }

    /**
     * The details of one job, or '' when its type gives none.
     *
     * @param string              $type
     * @param array<string,mixed> $payload
     *
     * @return string
     */
    public static function detail(string $type, array $payload): string
    {
        $detail = self::$names[$type]['detail'] ?? null;
        if ($detail === null) {
            return '';
        }
        try {
            return (string) $detail($payload);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Every registered type, sorted. The admin queue screen lists these so a job
     * sitting on an unregistered type is obvious.
     *
     * @return array<int,string>
     */
    public static function types(): array
    {
        $types = array_keys(self::$handlers);
        sort($types);

        return $types;
    }

    /**
     * Whether $type is well formed. Public so an enqueue can refuse a bad type at the
     * call site, where the stack trace still points at whoever wrote it, rather than
     * in a cron run hours later.
     *
     * @param string $type
     *
     * @return bool
     */
    public static function isValidType(string $type): bool
    {
        return (bool) preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $type) && strlen($type) <= 60;
    }

    /**
     * @param string $type
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public static function assertType(string $type): void
    {
        if (!self::isValidType($type)) {
            throw new InvalidArgumentException(
                'Job type "' . $type . '" is not valid: expected namespace.name, lower-case'
                . ' letters, digits, underscores and dots, at most 60 characters.'
            );
        }
    }
}
