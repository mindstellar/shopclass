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

namespace mindstellar\api\serializer;

/**
 * The last step of every serializer: keep what the `api_listing`, `api_user` and `api_category`
 * filters returned inside the rules, then apply the sparse fieldset. A filter may change a core
 * member's value but not its type, remove one or add one, and plugin data lives under `ext.<slug>`.
 */
final class Extensions
{
    /** @var \Closure(string): void */
    private \Closure $log;

    /** @var array<string,true> messages already logged, so a page of 50 rows logs each once */
    private array $logged = [];

    /**
     * @param callable|null $log receives each message; error_log() by default
     */
    public function __construct(private ExtensionMembers $declared, ?callable $log = null)
    {
        $this->log = \Closure::fromCallable($log ?? 'error_log');
    }

    public function declared(): ExtensionMembers
    {
        return $this->declared;
    }

    /**
     * Check a filter's result against the members the view has, then apply the context's
     * fieldset. A top-level key the filter added, other than `ext`, is dropped and logged.
     *
     * @param string              $hook     the filter that ran, to name its callbacks
     * @param string              $object   ExtensionMembers::OBJECTS
     * @param string[]            $members  the object's top-level members, `ext` among them
     * @param array<string,mixed> $before   the data the filter received
     * @param mixed               $filtered what it returned
     * @param array<int,mixed>    $args     the filter's other arguments, to replay it
     *
     * @return array<string,mixed>
     */
    public function finish(string $hook, string $object, array $members, array $before, mixed $filtered, array $args, ViewContext $context): array
    {
        $data = $this->settle($hook, $object, $members, $before, $filtered, $args, $context->view());

        return $context->fields() === null ? $data : $context->fields()->apply($data);
    }

    /**
     * @param string[]            $members
     * @param array<string,mixed> $before
     * @param array<int,mixed>    $args
     *
     * @return array<string,mixed>
     */
    private function settle(string $hook, string $object, array $members, array $before, mixed $filtered, array $args, string $view): array
    {
        if (!is_array($filtered)) {
            ($this->log)($hook . ' returned ' . get_debug_type($filtered) . ' instead of an array; the filter was ignored.');

            return $before;
        }
        // The view's members are the ones the serializer built, plus `ext`.
        $allowed = array_values(array_intersect($members, [...array_keys($before), 'ext']));
        $extra   = array_diff(array_keys($filtered), $allowed);
        if ($extra !== []) {
            $this->blame($hook, $allowed, $before, $args, $extra);
            $filtered = array_diff_key($filtered, array_flip($extra));
        }
        $filtered = $this->keepCore($hook, $before, $filtered);
        if (array_key_exists('ext', $filtered)) {
            $filtered = $this->cleanExt($hook, $object, $filtered, $view);
        }

        return $filtered;
    }

    /**
     * Put back each core member a filter removed or changed to another JSON type, in core's order. Nothing inside an object member is checked.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $filtered
     *
     * @return array<string,mixed>
     */
    private function keepCore(string $hook, array $before, array $filtered): array
    {
        $out = [];
        foreach ($before as $name => $value) {
            if ($name === 'ext') {
                continue;
            }
            if (!array_key_exists($name, $filtered)) {
                $this->once($hook . ': core member ' . $name . ' was removed by a filter and is restored; plugins add data under ext.<plugin-slug>.');
                $out[$name] = $value;
            } elseif (!self::sameType($value, $filtered[$name])) {
                $this->once($hook . ': core member ' . $name . ' was changed from ' . self::jsonType($value) . ' to ' . self::jsonType($filtered[$name]) . ' by a filter and is restored.');
                $out[$name] = $value;
            } else {
                $out[$name] = $filtered[$name];
            }
        }

        return $out + $filtered;
    }

    private static function sameType(mixed $core, mixed $new): bool
    {
        $a = self::jsonType($core);
        $b = self::jsonType($new);

        // An empty PHP array encodes as `[]` but may stand for an empty object.
        return $a === $b || ($core === [] && $b === 'object') || ($new === [] && $a === 'object');
    }

    private static function jsonType(mixed $value): string
    {
        return match (true) {
            $value === null                            => 'null',
            is_bool($value)                            => 'boolean',
            is_int($value) || is_float($value)         => 'number',
            is_string($value)                          => 'string',
            is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1)) => 'array',
            is_array($value), is_object($value)        => 'object',
            default                                    => get_debug_type($value),
        };
    }

    private function once(string $message): void
    {
        if (!isset($this->logged[$message])) {
            $this->logged[$message] = true;
            ($this->log)($message);
        }
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function cleanExt(string $hook, string $object, array $data, string $view): array
    {
        $ext = is_array($data['ext']) ? $data['ext'] : [];
        foreach ($ext as $slug => $values) {
            if (!is_string($slug) || !preg_match(ExtensionMembers::SLUG, $slug) || !is_array($values)) {
                ($this->log)($hook . ': ext.' . $slug . ' was dropped; ext holds one object per plugin slug.');
                unset($ext[$slug]);
                continue;
            }
            foreach (array_keys($values) as $name) {
                $field   = $this->declared->find($object, $slug, (string) $name);
                $visible = $field === null ? $view === ViewContext::ADMIN : $field->visibleIn($view);
                if (!$visible) {
                    unset($ext[$slug][$name]);
                }
            }
            if ($ext[$slug] === []) {
                unset($ext[$slug]);
            }
        }
        if ($ext === []) {
            unset($data['ext']);
        } else {
            $data['ext'] = $ext;
        }

        return $data;
    }

    /**
     * Run the filter's callbacks one at a time to name the ones that add a top-level key.
     * Only reached when a filter broke the rule, so the normal path runs the filter once.
     *
     * @param string[]            $members
     * @param array<string,mixed> $data
     * @param array<int,mixed>    $args
     * @param string[]            $extra
     */
    private function blame(string $hook, array $members, array $data, array $args, array $extra): void
    {
        $named = [];
        foreach (\Plugins::callbacks($hook) as $callback) {
            if (!is_callable($callback)) {
                continue;
            }
            $result = $callback($data, ...$args);
            if (!is_array($result)) {
                continue;
            }
            $added = array_diff(array_keys($result), $members);
            if ($added !== []) {
                $named[] = self::describe($callback) . ' (' . implode(', ', $added) . ')';
            }
            $data = array_diff_key($result, array_flip($added));
        }
        ($this->log)(
            $hook . ': top-level ' . implode(', ', $extra) . ' dropped; plugin data belongs under ext.<plugin-slug>. Added by '
            . ($named === [] ? 'an unknown callback' : implode('; ', $named)) . '.'
        );
    }

    private static function describe(callable $callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }
        if (is_array($callback)) {
            return (is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0]) . '::' . $callback[1];
        }
        $function = new \ReflectionFunction(\Closure::fromCallable($callback));

        return 'closure in ' . $function->getFileName() . ':' . $function->getStartLine();
    }
}
