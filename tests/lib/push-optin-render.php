<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Renders gui/account/parts/push-optin.php with DB-free stand-ins for the helpers it calls,
 * on a site at https://example.test/sub/. For tests that load no real helpers.
 */

const PUSH_TEST_KEY_BYTES = 65;

$GLOBALS['pushTestEnabled'] = true;

function push_test_key_bytes(): array
{
    $bytes = array(4);
    for ($i = 1; $i < PUSH_TEST_KEY_BYTES; $i++) {
        $bytes[] = ($i * 37 + 200) % 256;
    }

    return $bytes;
}

function push_test_key(): string
{
    return rtrim(strtr(base64_encode(pack('C*', ...push_test_key_bytes())), '+/', '-_'), '=');
}

function osc_push_enabled(): bool
{
    return (bool) $GLOBALS['pushTestEnabled'];
}

function osc_push_public_key(): string
{
    return osc_push_enabled() ? push_test_key() : '';
}

if (!function_exists('osc_esc_html')) {
    function osc_esc_html($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

function osc_base_url($withIndex = false)
{
    return 'https://example.test/sub/' . ($withIndex ? 'index.php' : '');
}

function osc_messaging_inbox_url(): string
{
    return 'https://example.test/sub/user/messages';
}

function osc_logged_user_id()
{
    return 7;
}

function osc_page_title()
{
    return 'Shop & Co';
}

function _m($text)
{
    return $text;
}

function _mn($one, $many, $n)
{
    return $n === 1 ? $one : $many;
}

function osc_csrf_token_form()
{
    return "<input type='hidden' name='CSRFName' value='test-name' />\n<input type='hidden' name='CSRFToken' value='test-token' />";
}

function push_optin_render(bool $enabled): string
{
    $GLOBALS['pushTestEnabled'] = $enabled;
    ob_start();
    require ABS_PATH . 'oc-includes/osclass/gui/account/parts/push-optin.php';

    return (string) ob_get_clean();
}
