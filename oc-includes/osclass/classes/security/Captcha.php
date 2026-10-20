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

namespace mindstellar\security;

/**
 * The captcha check every public form runs before it acts.
 */
final class Captcha
{
    /**
     * True when no captcha is asked for, or the answer sent is right.
     * $feature is null for forms that always ask, or 'items', 'comments' or 'reports' for those with their own switch.
     */
    public static function passes(?string $feature = null): bool
    {
        $wanted = match ($feature) {
            null       => true,
            'items'    => osc_recaptcha_items_enabled(),
            'comments' => osc_recaptcha_comments_enabled(),
            'reports'  => osc_recaptcha_reports_enabled(),
            default    => throw new \InvalidArgumentException('Unknown captcha feature: ' . $feature),
        };

        return !$wanted || !osc_captcha_enabled() || osc_check_captcha();
    }

    public static function failMessage(): string
    {
        return _m('Please complete the security check.');
    }
}
