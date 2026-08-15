<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\billing;

/**
 * A registered feature spec, wrapped so callers never touch the raw array.
 *
 * Constructed by FeatureRegistry from whatever a plugin registered; nothing here
 * mutates that spec or reaches back into the registry.
 *
 * @package mindstellar\billing
 */
final class Feature
{
    public const CONSUMES_QUANTITY = 'quantity';
    public const CONSUMES_DURATION = 'duration';

    private string $id;

    /** @var array raw spec passed to FeatureRegistry::register() */
    private array $spec;

    private function __construct(string $id, array $spec)
    {
        $this->id   = $id;
        $this->spec = $spec;
    }

    public static function fromSpec(string $id, array $spec): self
    {
        return new self($id, $spec);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return (string) ($this->spec['label'] ?? '');
    }

    public function getDescription(): string
    {
        return (string) ($this->spec['description'] ?? '');
    }

    public function getConsumes(): string
    {
        return (string) ($this->spec['consumes'] ?? self::CONSUMES_QUANTITY);
    }

    /**
     * Credit price, after billing_feature_price runs. Never negative.
     */
    public function price(): int
    {
        $price = $this->resolve($this->spec['price'] ?? 0);
        $price = (int) osc_apply_filter('billing_feature_price', $price, $this->id, null);

        return max(0, $price);
    }

    /**
     * Days granted for a duration feature; 0 for a quantity one.
     */
    public function duration(): int
    {
        return max(0, $this->resolve($this->spec['duration'] ?? 0));
    }

    /**
     * Run the spec's apply callable and coerce its answer to bool.
     */
    public function apply(int $userId, array $ctx): bool
    {
        $callable = $this->spec['apply'] ?? null;

        return is_callable($callable) && (bool) $callable($userId, $ctx);
    }

    /**
     * A spec value is either a literal int or a callable(): int -- resolve either form.
     */
    private function resolve($value): int
    {
        return is_callable($value) ? (int) $value() : (int) $value;
    }
}

/* file end: ./oc-includes/osclass/classes/billing/Feature.php */
