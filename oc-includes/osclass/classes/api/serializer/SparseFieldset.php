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

use mindstellar\api\ProblemException;

/**
 * A sparse fieldset from `?fields=id,title,ext.acme.rating`: the top-level members to keep,
 * plus whole plugin namespaces (`ext.acme`) or single declared plugin fields
 * (`ext.acme.rating`). `id` is always kept.
 */
final class SparseFieldset
{
    /**
     * @param array<string,true>              $top  member => true
     * @param array<string,true|array<string,true>> $ext  slug => true (all) or name => true
     */
    private function __construct(private array $top, private array $ext)
    {
    }

    /**
     * @param string     $raw     the `fields` query value; '' selects everything (null returned)
     * @param string[]   $members the top-level members the resource has
     * @param string     $object  ExtensionMembers::OBJECTS, for `ext.<slug>.<name>`
     *
     * @throws ProblemException 400 for a member the resource does not have
     */
    public static function parse(string $raw, array $members, ExtensionMembers $declared, string $object): ?self
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $top = ['id' => true];
        $ext = [];
        foreach (explode(',', $raw) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $parts = explode('.', $name);
            if ($parts[0] === 'ext' && count($parts) <= 3 && in_array('ext', $members, true)) {
                $top['ext'] = true;
                $slug       = $parts[1] ?? null;
                if ($slug === null) {
                    $ext = ['*' => true];
                } elseif (!preg_match(ExtensionMembers::SLUG, $slug)) {
                    throw self::unknown($name);
                } elseif (!isset($parts[2])) {
                    $ext[$slug] = true;
                } elseif ($declared->find($object, $slug, $parts[2]) === null) {
                    throw self::unknown($name);
                } elseif (($ext[$slug] ?? null) !== true) {
                    $ext[$slug][$parts[2]] = true;
                }
                continue;
            }
            if (count($parts) > 1 || !in_array($name, $members, true)) {
                throw self::unknown($name);
            }
            $top[$name] = true;
        }

        return new self($top, $ext);
    }

    /**
     * Whether a top-level member is wanted, so a serializer can skip work.
     */
    public function wants(string $member): bool
    {
        return isset($this->top[$member]);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public function apply(array $data): array
    {
        $out = array_intersect_key($data, $this->top);
        if (!isset($out['ext']) || !is_array($out['ext']) || isset($this->ext['*'])) {
            return $out;
        }
        $ext = [];
        foreach ($this->ext as $slug => $names) {
            if (!isset($out['ext'][$slug]) || !is_array($out['ext'][$slug])) {
                continue;
            }
            $kept = $names === true ? $out['ext'][$slug] : array_intersect_key($out['ext'][$slug], $names);
            if ($kept !== []) {
                $ext[$slug] = $kept;
            }
        }
        if ($ext === []) {
            unset($out['ext']);
        } else {
            $out['ext'] = $ext;
        }

        return $out;
    }

    private static function unknown(string $name): ProblemException
    {
        return ProblemException::of('invalid_query', 'fields: unknown field ' . $name . '.');
    }
}
