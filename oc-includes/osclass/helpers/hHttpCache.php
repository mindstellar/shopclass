<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * HTTP response caching — the "may a shared cache store and replay this page?" contract.
 *
 * The application is the authority on whether a response is public or private and states it in a
 * standard Cache-Control header, so a reverse proxy or CDN respects the app instead of guessing
 * from cookie presence or URL patterns. A public read page (homepage, search, listing, category,
 * static page, public user profile) opts in with osc_mark_response_cacheable();
 * osc_send_response_cache_headers() then stamps the right header once, at the end of the
 * front-end request. The default is private, so a page that never opts in is never cached.
 *
 * Third-party analytics/ads cookies (_ga, __gads, _fbp, the consent banner, ...) are irrelevant
 * to this decision: it looks only at the app's own identity/session state, never at "is any
 * cookie present", so those cookies neither defeat the cache nor leak a personalized page.
 */

/**
 * Declare the current response a public, cacheable read page.
 *
 * Safe by default: a response is private unless a controller calls this while rendering a page
 * that carries no per-visitor data. The final emit still requires an anonymous GET/HEAD (see
 * osc_response_is_cacheable()), so marking a page that later turns out to be served to a
 * logged-in user is harmless — it is downgraded to private automatically.
 *
 * @param bool $cacheable
 *
 * @return void
 */
function osc_mark_response_cacheable($cacheable = true)
{
    $GLOBALS['osc_response_cacheable'] = (bool)$cacheable;
}

/**
 * The cookie names that mean "this response is personalized" — core's PHP session, and the
 * fixed-name cache-bypass flag Cookie::set() writes whenever the visitor carries identity or
 * locale state (front-end user, admin, chosen language).
 *
 * These are REAL wire cookie names, so a reverse proxy / CDN can match them by name. (Front-end
 * and admin identity actually live as keys inside one cookie named md5(WEB_PATH), which a proxy
 * config cannot hardcode; oc_cache_bypass is the stable public signal that stands in for them.)
 * This is the caching contract's allowlist: a proxy bypasses on exactly these and ignores every
 * other cookie (third-party analytics/ads, the consent banner), and the app applies the same set
 * below so both layers agree. Filterable so a plugin that adds its own auth cookie can extend both
 * at once — keep it in sync with the reference proxy config (.docker/nginx/microcache.conf).
 *
 * @return string[]
 */
function osc_cache_relevant_cookies()
{
    return osc_apply_filter('cache_relevant_cookies', array(
        session_name() ?: 'osclass',
        'oc_cache_bypass',
    ));
}

/**
 * Whether the current response may be served from / stored in a shared cache: an opted-in public
 * read page, requested with GET or HEAD, with no logged-in web or admin user, no active PHP
 * session, and no personalization cookie present (nothing per-visitor this request).
 *
 * @return bool
 */
function osc_response_is_cacheable()
{
    if (empty($GLOBALS['osc_response_cacheable'])) {
        return false;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }
    if (osc_is_web_user_logged_in() || osc_is_admin_user_logged_in()) {
        return false;
    }
    // A physical session means some state was written this request (a form, a flash) — treat the
    // response as personalized even if no user is logged in.
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        return false;
    }
    // Match the proxy's cookie contract exactly, so the app is safe even in front of a cache that
    // doesn't know the cookie list: a stale/tampered identity cookie, or a chosen locale (which
    // rendered this page in another language), makes the response per-visitor. Third-party
    // cookies are deliberately absent from this list and never downgrade the response.
    foreach (osc_cache_relevant_cookies() as $name) {
        if (isset($_COOKIE[$name]) && $_COOKIE[$name] !== '') {
            return false;
        }
    }

    return (bool)osc_apply_filter('response_is_cacheable', true);
}

/**
 * Emit the Cache-Control header for a front-end response, once, after the page has been built.
 *
 * A public read page (osc_mark_response_cacheable()) served anonymously gets a short shared-cache
 * window while browsers always revalidate — `public, s-maxage=<ttl>, max-age=0, must-revalidate`;
 * everything else is `private, no-store` and never stored. No-op when headers are already sent,
 * when the response is a redirect (a Location header is present), when a Cache-Control was already
 * set by a plugin/theme, or on an AJAX/JSON endpoint that manages its own headers.
 *
 * @return void
 */
function osc_send_response_cache_headers()
{
    if (headers_sent()) {
        return;
    }
    if (defined('IS_AJAX') && IS_AJAX === true) {
        return;
    }
    foreach (headers_list() as $sent) {
        $name = strtolower($sent);
        if (strpos($name, 'location:') === 0 || strpos($name, 'cache-control:') === 0) {
            return;
        }
    }

    if (osc_response_is_cacheable()) {
        $ttl    = max(0, (int)osc_apply_filter('public_cache_max_age', 30));
        $header = 'public, s-maxage=' . $ttl . ', max-age=0, must-revalidate';
    } else {
        $header = 'private, no-store';
    }

    header('Cache-Control: ' . osc_apply_filter('response_cache_control', $header));
}

/* file end: ./oc-includes/osclass/helpers/hHttpCache.php */
