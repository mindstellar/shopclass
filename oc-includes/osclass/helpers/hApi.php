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
 * Helpers for the REST API under /api/v1.
 */

if (!function_exists('osc_api_enabled')) {
    /**
     * Whether the REST API answers at all.
     *
     * @api
     *
     * @return bool
     */
    function osc_api_enabled(): bool
    {
        return \mindstellar\apiaccess\ApiSettings::fromPreferences()->enabled();
    }
}

if (!function_exists('osc_is_api_request')) {
    /**
     * Whether this request is for the REST API: `?page=api`, or the /api/ path while friendly
     * URLs are on. It works before the router has run.
     *
     * @api
     *
     * @return bool
     */
    function osc_is_api_request(): bool
    {
        if (Params::getParamString('page') === 'api') {
            return true;
        }
        if (!Preference::getInstance()->get('rewriteEnabled')) {
            return false;
        }

        return preg_match('#^api(?:[/?\\#]|$)#', ltrim(Params::getRequestURI(false, false, false), '/')) === 1;
    }
}

if (!function_exists('osc_api_public_reads')) {
    /**
     * Whether public data may be read with no credential at all. Off by default.
     *
     * @api
     *
     * @return bool
     */
    function osc_api_public_reads(): bool
    {
        return \mindstellar\apiaccess\ApiSettings::fromPreferences()->publicReads();
    }
}

if (!function_exists('osc_api_register_route')) {
    /**
     * Add an API endpoint, through the `api_routes` filter. The path is below /api/v1/ and
     * must start with `ext/<plugin-slug>/`; other paths are refused when the table is built,
     * except a route with both `deprecated` and `sunset`, which may keep a plugin's old path
     * until its sunset day. A second route with the same method and path is refused and logged.
     *
     * @api
     *
     * @param string              $method GET, POST, PUT, PATCH or DELETE
     * @param string              $path   e.g. 'ext/acme/offers/{id}'
     * @param array<string,mixed> $spec   handler, auth, scope, summary, description, tags, query,
     *                                    body, responses, deprecated, sunset, where, versions, plugin
     *
     * @return void
     */
    function osc_api_register_route(string $method, string $path, array $spec): void
    {
        $key = strtoupper($method) . ' ' . trim($path, '/');
        if (!isset($spec['plugin']) && function_exists('osc_plugins_path')) {
            $plugins = rtrim(str_replace('\\', '/', osc_plugins_path()), '/') . '/';
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
                if (str_starts_with($file, $plugins)) {
                    $spec['plugin'] = explode('/', substr($file, strlen($plugins)), 2)[0];
                    break;
                }
            }
        }
        osc_add_filter('api_routes', static function ($routes) use ($key, $spec) {
            $routes = is_array($routes) ? $routes : [];
            if (isset($routes[$key])) {
                error_log('API route ' . $key . ' refused: it is already registered' . (isset($routes[$key]['plugin']) ? ' by plugin ' . $routes[$key]['plugin'] : '') . '.');

                return $routes;
            }
            $routes[$key] = $spec;

            return $routes;
        });
    }
}

if (!function_exists('osc_api_register_schema')) {
    /**
     * Add a component schema for a plugin's routes, through the `api_schemas` filter. It is
     * named `Ext<Slug><Name>` (`acme-ratings`, `Rating`: `ExtAcmeRatingsRating`), so it never
     * clashes with core's components, which are not part of the plugin contract. A second,
     * different schema under the same name is refused and logged; the first one stays.
     *
     * @api
     *
     * @param string              $slug   the plugin's slug, e.g. 'acme-ratings'
     * @param string              $name   StudlyCaps, e.g. 'Rating'
     * @param array<string,mixed> $schema JSON Schema
     *
     * @return array{'$ref':string} the reference to use in a route's schemas
     */
    function osc_api_register_schema(string $slug, string $name, array $schema): array
    {
        $component = 'Ext' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', strtolower($slug)))) . $name;
        osc_add_filter('api_schemas', static function ($schemas) use ($component, $schema) {
            $schemas = is_array($schemas) ? $schemas : [];
            if (isset($schemas[$component])) {
                if ($schemas[$component] !== $schema) {
                    error_log('API schema ' . $component . ' refused: it is already registered.');
                }

                return $schemas;
            }
            $schemas[$component] = $schema;

            return $schemas;
        });

        return ['$ref' => '#/components/schemas/' . $component];
    }
}

if (!function_exists('osc_api_register_field')) {
    /**
     * Declare a field a plugin adds to a listing, user or category, so the OpenAPI document
     * shows it and `?fields=ext.<slug>.<name>` selects it. The plugin sends the value under
     * `ext.<slug>.<name>` from the `api_listing`, `api_user` or `api_category` filter. A
     * public field reaches everyone, an owner field the owner and admins, an admin field
     * admins only.
     *
     * @api
     *
     * @param string              $object listing, user or category
     * @param string              $slug   the plugin's slug, e.g. 'acme'
     * @param string              $name   lowercase letters, digits and _
     * @param array<string,mixed> $schema JSON Schema of the value
     * @param string[]            $views  public, owner or admin
     *
     * @return void
     */
    function osc_api_register_field(string $object, string $slug, string $name, array $schema, array $views = ['public']): void
    {
        $field = [$object, $slug, $name, $schema, $views];
        osc_add_filter('api_fields', static function ($fields) use ($field) {
            $fields   = is_array($fields) ? $fields : [];
            $fields[] = $field;

            return $fields;
        });
    }
}

if (!function_exists('osc_api_url')) {
    /**
     * The absolute URL of an API path, with or without friendly URLs.
     *
     * @api
     *
     * @param string      $path    below the version, may carry a query string
     * @param string|null $version e.g. `v1`; ApiSettings::PINNED_VERSION when null
     *
     * @return string
     */
    function osc_api_url(string $path = '', ?string $version = null): string
    {
        $parts = explode('?', ltrim($path, '/'), 2);
        $route = ($version ?? \mindstellar\apiaccess\ApiSettings::PINNED_VERSION) . ($parts[0] === '' ? '' : '/' . $parts[0]);
        $query = $parts[1] ?? '';

        if (osc_rewrite_enabled()) {
            return osc_base_url() . 'api/' . $route . ($query === '' ? '' : '?' . $query);
        }

        return osc_base_url() . 'index.php?page=api&path=' . $route . ($query === '' ? '' : '&' . $query);
    }
}

if (!function_exists('osc_api_session_token')) {
    /**
     * The page token theme JavaScript sends as the X-Shopclass-Token header to call the API as
     * the signed-in user, from this site's own pages. It lives two hours.
     *
     * @api
     *
     * @return string '' when the API is off or nobody is signed in
     */
    function osc_api_session_token(): string
    {
        if (!osc_api_enabled()) {
            return '';
        }
        $token = \mindstellar\apiaccess\ApiAccess::site()->pageTokens()->forWebUser();

        return $token === null ? '' : $token->token();
    }
}

if (!function_exists('osc_api_session_meta')) {
    /**
     * A `<meta name="shopclass-api">` tag for the page head. Its content is JSON: the API's
     * address (`url`), the page token (`token`, '' when nobody is signed in), the `header` to
     * send it in and when it expires (`expires_at`).
     *
     * @api
     *
     * @return string '' when the API is off
     */
    function osc_api_session_meta(): string
    {
        if (!osc_api_enabled()) {
            return '';
        }
        $token = \mindstellar\apiaccess\ApiAccess::site()->pageTokens()->forWebUser();
        $data  = [
            'url'        => osc_api_url(),
            'token'      => $token === null ? '' : $token->token(),
            'header'     => \mindstellar\apiaccess\PageTokens::HEADER,
            'expires_at' => $token === null ? null : \mindstellar\database\UtcDatetime::rfc3339((int) $token->expiresAt()),
        ];

        return '<meta name="shopclass-api" content="' . osc_esc_html((string) json_encode($data, JSON_UNESCAPED_SLASHES)) . '">' . PHP_EOL;
    }
}

if (!function_exists('osc_webhook_emit')) {
    /**
     * Queue a webhook event for every enabled endpoint that subscribes to it. A plugin's
     * event is named `ext.<plugin-slug>.<name>` and registered on `api_webhook_events` first.
     *
     * @api
     *
     *     osc_webhook_emit('ext.acme.offer_created', ['id' => $offerId, 'url' => $url]);
     *
     * @param string              $type    a type from the catalogue, e.g. 'listing.created'
     * @param array<string,mixed> $data    the event's `data`; keep secrets and e-mails out
     * @param array<string,mixed> $options thin: the `data` sent when the body is over 64 KB
     *                                     (default: its id and url). endpoints: only these ids.
     *
     * @return string|null the message id (`webhook-id`), or null when no endpoint wants it
     * @throws InvalidArgumentException for a type the catalogue does not have
     */
    function osc_webhook_emit(string $type, array $data, array $options = []): ?string
    {
        return \mindstellar\webhook\WebhookServices::site()->dispatcher()->emit($type, $data, $options);
    }
}

if (!function_exists('osc_user_api_access_url')) {
    /**
     * The account page listing the user's API sign-ins and personal keys, for a theme's account menu.
     *
     * @api
     */
    function osc_user_api_access_url(): string
    {
        return osc_core_url('user_api_access');
    }
}

/*
 * "API access" on the account menu, once the user has something there: a sign-in or key, or
 * a site that lets users make keys.
 */
osc_add_filter('user_menu_filter', static function ($options) {
    $options = is_array($options) ? $options : [];
    $userId  = (int) osc_logged_user_id();
    if ($userId > 0 && \mindstellar\apiaccess\ApiAccess::site()->accountAccess()->relevant($userId)) {
        $options[] = ['name' => _m('API access'), 'url' => osc_user_api_access_url(), 'class' => 'opt_api_access'];
    }

    return $options;
});

// Signing out everywhere (which a password change does) revokes the API sign-ins and keys
// too, in the same transaction.
osc_add_hook('user_signout_all_after', static function ($userId): void {
    \mindstellar\apiaccess\ApiAccess::site()->accessEntries()->endAll((int) $userId);
});
osc_add_hook('admin_signout_all_after', static function ($adminId): void {
    \mindstellar\apiaccess\ApiAccess::site()->keyService()->revokeAdminKeys((int) $adminId);
});

// The REST API registers its request handler and turns core events into webhooks.
require_once __DIR__ . '/../classes/api/boot.php';
