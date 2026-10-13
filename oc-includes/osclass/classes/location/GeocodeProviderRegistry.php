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

namespace mindstellar\location;

use InvalidArgumentException;
use mindstellar\base\Registry;

/**
 * Registry of geocode providers. The hook fires once, on the first read, so a page that
 * never geocodes builds no provider. Mirrors PaymentGatewayRegistry.
 */
final class GeocodeProviderRegistry extends Registry
{
    /** Fired once, on the first read, with the registry, for providers to register. */
    public const HOOK = 'register_geocode_providers';

    /** The preference that names the active provider; empty for none. */
    public const PREF = 'geocode_provider';

    private bool $loaded = false;

    /**
     * Register a provider. Registering an id again replaces it.
     *
     * @throws InvalidArgumentException on an invalid id
     */
    public function register(GeocodeProvider $provider): void
    {
        $id = $provider->id();
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException('GeocodeProviderRegistry: invalid provider id "' . $id . '"');
        }
        $this->entries[$id] = $provider;
    }

    public function get(string $id): ?GeocodeProvider
    {
        $this->load();

        return $this->entries[$id] ?? null;
    }

    /**
     * Every registered provider, configured or not.
     *
     * @return array<string,GeocodeProvider>
     */
    public function all(): array
    {
        $this->load();

        return $this->entries;
    }

    /**
     * The provider the owner picked, when it is registered and configured.
     */
    public function active(): ?GeocodeProvider
    {
        $id = (string) osc_get_preference(self::PREF);
        if ($id === '') {
            return null;
        }
        $provider = $this->get($id);

        return $provider !== null && $provider->isConfigured() ? $provider : null;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        // Set first, so a listener that reads the registry does not fire the hook again.
        $this->loaded = true;
        osc_run_hook('register_geocode_providers', $this);
    }
}
