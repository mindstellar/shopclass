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

namespace mindstellar\language\event;

use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * The core, theme and plugin texts for a locale are loaded; a catalogue a listener loads now
 * replaces them. Old name: translation_load_after.
 */
final class TranslationLoaded extends Event implements Bridged
{
    public function __construct(private \Translation $translation, private string $locale)
    {
    }

    public static function legacyNames(): array
    {
        return ['translation_load_after'];
    }

    public function translation(): \Translation
    {
        return $this->translation;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->translation, $this->locale);
    }
}
