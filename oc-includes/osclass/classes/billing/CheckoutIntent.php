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
 * What a gateway wants the browser to do next in order to pay.
 *
 * Two shapes cover every provider worth supporting: send the user somewhere (hosted
 * checkout, provider-side redirect) or render something in place (an embedded form, a
 * card widget, bank transfer instructions). Core does not interpret either -- it hands
 * the redirect to the browser or prints the markup -- which is what keeps gateway
 * specifics out of core entirely.
 *
 * @package mindstellar\billing
 */
final class CheckoutIntent
{
    public const KIND_REDIRECT = 'redirect';
    public const KIND_HTML     = 'html';

    private function __construct(
        private string $kind,
        private string $payload
    ) {
    }

    /** Send the browser to the gateway. */
    public static function redirect(string $url): self
    {
        return new self(self::KIND_REDIRECT, $url);
    }

    /**
     * Render this markup in the checkout page.
     *
     * The gateway is responsible for the safety of what it returns -- core prints it
     * unescaped, because escaping a payment form would break it. Markup here must
     * never interpolate unsanitised request data.
     */
    public static function html(string $html): self
    {
        return new self(self::KIND_HTML, $html);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function isRedirect(): bool
    {
        return $this->kind === self::KIND_REDIRECT;
    }

    /** The URL for a redirect intent, or the markup for an html one. */
    public function getPayload(): string
    {
        return $this->payload;
    }
}

/* file end: ./oc-includes/osclass/classes/billing/CheckoutIntent.php */
