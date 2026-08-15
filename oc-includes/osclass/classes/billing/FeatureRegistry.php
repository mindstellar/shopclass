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

use InvalidArgumentException;

/**
 * Registry of what credits can be spent on.
 *
 * Deliberately mirrors PaymentGatewayRegistry (and mindstellar\widgets\WidgetRegistry,
 * mindstellar\fields\FieldTypeRegistry) so the three registries read the same to a
 * plugin author. Core registers listing.publish and listing.premium; a plugin may add
 * its own or replace either by re-registering the same id.
 *
 * @package mindstellar\billing
 */
final class FeatureRegistry
{
    private static ?FeatureRegistry $instance = null;

    /** @var array<string,array> raw specs, keyed by feature id */
    private array $specs = array();

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a feature. Re-registering an id replaces the previous spec, which is
     * what lets a site override a built-in feature's price or effect.
     *
     * $spec keys:
     *   'label'       => string             Required.
     *   'description' => string             Optional.
     *   'consumes'    => Feature::CONSUMES_* Required; QUANTITY|DURATION|CAPACITY.
     *   'price'       => int|callable(): int Optional, defaults to 0.
     *   'duration'    => int|callable(): int Optional, defaults to 0.
     *   'scope'       => Feature::SCOPE_*    Optional, defaults to SCOPE_USER. SCOPE_ITEM
     *                                        is what lets a route spend this feature
     *                                        against an item id taken from the request
     *                                        (see CWebBilling::upgradePost()) -- every
     *                                        other feature stays unreachable that way.
     *   'apply'       => callable(int $userId, array $ctx): bool  Required.
     *
     * @throws InvalidArgumentException on an invalid id or an incomplete spec
     */
    public function register(string $id, array $spec): void
    {
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException('FeatureRegistry: invalid feature id "' . $id . '"');
        }

        if (empty($spec['label']) || !is_string($spec['label'])) {
            throw new InvalidArgumentException('FeatureRegistry: feature "' . $id . '" needs a string label');
        }

        $consumes = $spec['consumes'] ?? null;
        if (
            $consumes !== Feature::CONSUMES_QUANTITY
            && $consumes !== Feature::CONSUMES_DURATION
            && $consumes !== Feature::CONSUMES_CAPACITY
        ) {
            throw new InvalidArgumentException(
                'FeatureRegistry: feature "' . $id . '" needs consumes = quantity|duration|capacity'
            );
        }

        if (isset($spec['scope']) && $spec['scope'] !== Feature::SCOPE_USER && $spec['scope'] !== Feature::SCOPE_ITEM) {
            throw new InvalidArgumentException(
                'FeatureRegistry: feature "' . $id . '" needs scope = user|item'
            );
        }

        if (!isset($spec['apply']) || !is_callable($spec['apply'])) {
            throw new InvalidArgumentException('FeatureRegistry: feature "' . $id . '" needs a callable apply');
        }

        $this->specs[$id] = $spec;
    }

    public function get(string $id): ?Feature
    {
        return isset($this->specs[$id]) ? Feature::fromSpec($id, $this->specs[$id]) : null;
    }

    /**
     * @return array<string,Feature>
     */
    public function all(): array
    {
        $out = array();
        foreach ($this->specs as $id => $spec) {
            $out[$id] = Feature::fromSpec($id, $spec);
        }

        return $out;
    }

    /** Lower-case slug, matching the spelling used for widget and field type ids. */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9_.-]{1,64}$/', $id);
    }
}

/* file end: ./oc-includes/osclass/classes/billing/FeatureRegistry.php */
