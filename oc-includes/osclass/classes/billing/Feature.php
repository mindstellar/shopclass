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
    /** A ceiling that is read, never spent -- see Entitlements::capacity(). */
    public const CONSUMES_CAPACITY = 'capacity';

    /** Spends against the buyer's own account -- listing.publish, listing.premium's default. */
    public const SCOPE_USER = 'user';
    /** Spends against one of the buyer's items -- the only scope a route may spend
     *  on behalf of an item id taken from the request. See FeatureRegistry::register(). */
    public const SCOPE_ITEM = 'item';

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
     * Who a spend on this feature is scoped to. Defaults to SCOPE_USER, so a
     * feature that never declares one is not reachable through a route that spends
     * on an item id taken from the request -- item-scoped is opt-in, not assumed.
     */
    public function getScope(): string
    {
        return (string) ($this->spec['scope'] ?? self::SCOPE_USER);
    }

    /**
     * Credit price, after billing_feature_price runs. Never negative.
     *
     * $userId is who the price is for -- an admin granting on someone's behalf or a
     * cron acting for a user is not that user, so the filter must be told explicitly
     * rather than reaching for osc_logged_user_id() itself.
     */
    public function price(?int $userId = null): int
    {
        $price = $this->resolve($this->spec['price'] ?? 0);
        $price = (int) osc_apply_filter('billing_feature_price', $price, $this->id, $userId);

        return max(0, $price);
    }

    /**
     * Days granted for a duration feature; 0 for a quantity one. Same $userId
     * treatment as price() and for the same reason: a plan that grants 60 days
     * where the default is 30 needs to know who it is granting to.
     */
    public function duration(?int $userId = null): int
    {
        $days = $this->resolve($this->spec['duration'] ?? 0);
        $days = (int) osc_apply_filter('billing_feature_duration', $days, $this->id, $userId);

        return max(0, $days);
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
