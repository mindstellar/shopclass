<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\routing;

/**
 * Every core page that has a friendly URL, declared once.
 *
 * Both directions read this table: Rewrite::buildRules() compiles the match
 * patterns from it, and osc_core_url() builds the outgoing link from it. Before,
 * each route was written twice -- a regular expression on one side and a string
 * concatenation on the other -- and the two drifted silently, because nothing
 * fails when a link points somewhere no rule answers.
 *
 * Permalink structures ({ITEM_ID}, {PAGE_SLUG}, {CATEGORIES}) are not here. Those
 * already have a single definition in their own preference, and their builders
 * need category lookups this table has no business doing.
 */
class CoreRoutes
{
    /**
     * Path segment placeholder used when a route's arguments carry no value.
     */
    private const DEFAULT_SEPARATOR = '/';

    /**
     * The table, in the order the rules must be tried. Order is load bearing:
     * the first pattern that matches wins, so a route whose path is a prefix of
     * another's has to come after it.
     *
     * pref    preference holding the editable path; null for a route that has no
     *         friendly form and is always built as a query string
     * to      fixed query parameters, in the order they are written
     * params  arguments, in path order. Each may set:
     *           re        pattern that captures it in a rule
     *           query     true to keep it a query parameter even when rewriting
     *           keepEmpty true to keep an empty value in the path
     *           enc       true to urlencode it
     *           sep       what precedes it in the pattern (default '/')
     * order   query parameter order, when it differs from path order
     * tail    end of the pattern (default '/?$')
     * base    'admin' to build against the admin URL instead of the site's
     * rule    false for a route that generates no rewrite rule
     * group   which block of the rule table it belongs to: 'listing' rules are
     *         tried before the listing permalink pattern, 'account' after it
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return array(
            'contact' => array(
                'group' => 'listing',
                'pref' => 'rewrite_contact',
                'to'   => array('page' => 'contact'),
            ),
            'feed' => array(
                'group' => 'listing',
                'pref' => 'rewrite_feed',
                'to'   => array('page' => 'search', 'sFeed' => 'rss'),
            ),
            'feed_named' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_feed',
                'to'     => array('page' => 'search'),
                'params' => array('sFeed' => array('re' => '(.+)')),
            ),
            'language' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_language',
                'to'     => array('page' => 'language'),
                'params' => array('locale' => array('re' => '(.*?)')),
            ),
            'search' => array(
                'group' => 'listing',
                'pref' => 'rewrite_search_url',
                'to'   => array('page' => 'search'),
                'tail' => '$',
            ),
            'search_params' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_search_url',
                'to'     => array('page' => 'search'),
                'params' => array('sParams' => array('re' => '(.*)')),
                'tail'   => '$',
            ),
            'item_mark' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_item_mark',
                'to'     => array('page' => 'item', 'action' => 'mark'),
                'params' => array(
                    'as' => array('re' => '(.*?)'),
                    'id' => array('re' => '([0-9]+)'),
                ),
            ),
            'item_send_friend' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_item_send_friend',
                'to'     => array('page' => 'item', 'action' => 'send_friend'),
                'params' => array('id' => array('re' => '([0-9]+)')),
            ),
            'item_contact' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_item_contact',
                'to'     => array('page' => 'item', 'action' => 'contact'),
                'params' => array('id' => array('re' => '([0-9]+)')),
            ),
            'item_new' => array(
                'group' => 'listing',
                'pref' => 'rewrite_item_new',
                'to'   => array('page' => 'item', 'action' => 'item_add'),
            ),
            'item_new_in_category' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_item_new',
                'to'     => array('page' => 'item', 'action' => 'item_add'),
                'params' => array('catId' => array('re' => '([0-9]+)')),
            ),
            'item_activate' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_item_activate',
                'to'     => array('page' => 'item', 'action' => 'activate'),
                'params' => array(
                    'id'     => array('re' => '([0-9]+)'),
                    'secret' => array('re' => '(.*?)', 'keepEmpty' => true),
                ),
            ),
            'item_edit' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_item_edit',
                'to'     => array('page' => 'item', 'action' => 'item_edit'),
                'params' => array(
                    'id'     => array('re' => '([0-9]+)'),
                    'secret' => array('re' => '(.*?)', 'keepEmpty' => true),
                ),
            ),
            'item_delete' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_item_delete',
                'to'     => array('page' => 'item', 'action' => 'item_delete'),
                'params' => array(
                    'id'     => array('re' => '([0-9]+)'),
                    'secret' => array('re' => '(.*?)', 'keepEmpty' => true),
                ),
            ),
            'item_resource_delete' => array(
                'group' => 'listing',
                'pref'   => 'rewrite_item_resource_delete',
                'to'     => array('page' => 'item', 'action' => 'deleteResource'),
                'params' => array(
                    'id'     => array('re' => '([0-9]+)'),
                    'item'   => array('re' => '([0-9]+)'),
                    'code'   => array('re' => '([0-9A-Za-z]+)'),
                    'secret' => array('re' => '(.*?)', 'sep' => '/?'),
                ),
            ),
            'item' => array(
                'to'     => array('page' => 'item'),
                'params' => array(
                    'id'   => array(),
                    'lang' => array(),
                ),
                'rule'   => false,
            ),
            'item_delete_comment' => array(
                'to'     => array('page' => 'item', 'action' => 'delete_comment'),
                'params' => array('id' => array(), 'comment' => array()),
                'rule'   => false,
            ),
            // The friendly form of a static page comes from the {PAGE_SLUG}/{PAGE_ID}
            // structure, which its own builder expands; only the query form is here.
            'page' => array(
                'to'     => array('page' => 'page'),
                'params' => array('id' => array(), 'lang' => array()),
                'rule'   => false,
            ),
            'item_view_beacon' => array(
                'to'     => array('page' => 'item', 'action' => 'view_beacon'),
                'params' => array('id' => array()),
                'rule'   => false,
            ),
            'user_login' => array(
                'pref' => 'rewrite_user_login',
                'to'   => array('page' => 'login'),
            ),
            'user_dashboard' => array(
                'pref' => 'rewrite_user_dashboard',
                'to'   => array('page' => 'user', 'action' => 'dashboard'),
            ),
            'user_logout' => array(
                'pref' => 'rewrite_user_logout',
                'to'   => array('page' => 'main', 'action' => 'logout'),
            ),
            'user_register' => array(
                'pref' => 'rewrite_user_register',
                'to'   => array('page' => 'register', 'action' => 'register'),
            ),
            'user_activate' => array(
                'pref'   => 'rewrite_user_activate',
                'to'     => array('page' => 'register', 'action' => 'validate'),
                'params' => array(
                    'id'   => array('re' => '([0-9]+)'),
                    'code' => array('re' => '(.*?)'),
                ),
            ),
            'user_activate_alert' => array(
                'pref'   => 'rewrite_user_activate_alert',
                'to'     => array('page' => 'user', 'action' => 'activate_alert'),
                'params' => array(
                    'id'     => array('re' => '([0-9]+)'),
                    'secret' => array('re' => '([a-zA-Z0-9]+)'),
                    'email'  => array('re' => '(.+)', 'enc' => true),
                ),
                'order'  => array('email', 'secret', 'id'),
                'tail'   => '$',
            ),
            'user_profile' => array(
                'pref' => 'rewrite_user_profile',
                'to'   => array('page' => 'user', 'action' => 'profile'),
            ),
            'user_pub_profile_id_paged' => array(
                'pref'   => 'rewrite_user_profile',
                'to'     => array('page' => 'user', 'action' => 'pub_profile'),
                'params' => array(
                    'id'    => array('re' => '([0-9]+)'),
                    'iPage' => array('re' => '([0-9]+)'),
                ),
            ),
            'user_pub_profile_id' => array(
                'pref'   => 'rewrite_user_profile',
                'to'     => array('page' => 'user', 'action' => 'pub_profile'),
                'params' => array('id' => array('re' => '([0-9]+)')),
            ),
            'user_pub_profile_paged' => array(
                'pref'   => 'rewrite_user_profile',
                'to'     => array('page' => 'user', 'action' => 'pub_profile'),
                'params' => array(
                    'username' => array('re' => '([^/]+)'),
                    'iPage'    => array('re' => '([0-9]+)'),
                ),
            ),
            'user_pub_profile' => array(
                'pref'   => 'rewrite_user_profile',
                'to'     => array('page' => 'user', 'action' => 'pub_profile'),
                'params' => array('username' => array('re' => '([^/]+)')),
            ),
            'user_items' => array(
                'pref'   => 'rewrite_user_items',
                'to'     => array('page' => 'user', 'action' => 'items'),
                'params' => array(
                    'iPage'    => array('query' => true),
                    'itemType' => array('query' => true),
                ),
            ),
            'user_alerts' => array(
                'pref' => 'rewrite_user_alerts',
                'to'   => array('page' => 'user', 'action' => 'alerts'),
            ),
            'user_recover' => array(
                'pref' => 'rewrite_user_recover',
                'to'   => array('page' => 'login', 'action' => 'recover'),
            ),
            'user_forgot' => array(
                'pref'   => 'rewrite_user_forgot',
                'to'     => array('page' => 'login', 'action' => 'forgot'),
                'params' => array(
                    'userId' => array('re' => '([0-9]+)'),
                    'code'   => array('re' => '(.*)'),
                ),
            ),
            'user_change_password' => array(
                'pref' => 'rewrite_user_change_password',
                'to'   => array('page' => 'user', 'action' => 'change_password'),
            ),
            'user_change_email' => array(
                'pref' => 'rewrite_user_change_email',
                'to'   => array('page' => 'user', 'action' => 'change_email'),
            ),
            'user_change_username' => array(
                'pref' => 'rewrite_user_change_username',
                'to'   => array('page' => 'user', 'action' => 'change_username'),
            ),
            'user_change_email_confirm' => array(
                'pref'   => 'rewrite_user_change_email_confirm',
                'to'     => array('page' => 'user', 'action' => 'change_email_confirm'),
                'params' => array(
                    'userId' => array('re' => '([0-9]+)'),
                    'code'   => array('re' => '(.*?)'),
                ),
            ),
            'user_resend_activation' => array(
                'to'     => array('page' => 'login', 'action' => 'resend'),
                'params' => array('id' => array(), 'email' => array()),
                'rule'   => false,
            ),
            'user_unsub_alert' => array(
                'to'     => array('page' => 'user', 'action' => 'unsub_alert'),
                'params' => array(
                    'email'  => array('enc' => true),
                    'secret' => array(),
                    'id'     => array(),
                ),
                'rule'   => false,
            ),
            'user_export' => array(
                'to'     => array('page' => 'user', 'action' => 'export'),
                'params' => array(
                    'id'     => array(),
                    'secret' => array('enc' => 'raw'),
                ),
                'rule'   => false,
            ),
            'user_delete' => array(
                'to'   => array('page' => 'user', 'action' => 'delete'),
                'rule' => false,
            ),
            // Billing's three navigable pages, specific first: the buy path nests
            // under the wallet's by default. Both patterns are end-anchored, which
            // already keeps them apart, but the order is what stays correct if an
            // admin renames one into something that does overlap. The gateway
            // callback keeps its query form -- gateways hold that URL on their side.
            'billing_buy' => array(
                'pref' => 'rewrite_billing_buy',
                'to'   => array('page' => 'billing', 'action' => 'buy'),
            ),
            'billing_orders' => array(
                'pref' => 'rewrite_billing_orders',
                'to'   => array('page' => 'billing', 'action' => 'orders'),
            ),
            'billing_wallet' => array(
                'pref' => 'rewrite_billing_wallet',
                'to'   => array('page' => 'billing'),
            ),
            'billing_upgrade' => array(
                'to'     => array('page' => 'billing', 'action' => 'upgrade'),
                'params' => array(
                    'itemId'  => array(),
                    'feature' => array('enc' => 'raw'),
                ),
                'rule'   => false,
            ),
            'admin_item_edit' => array(
                'to'     => array('page' => 'items', 'action' => 'item_edit'),
                'params' => array('id' => array()),
                'base'   => 'admin',
                'rule'   => false,
            ),
            'admin_forgot' => array(
                'to'     => array('page' => 'login', 'action' => 'forgot'),
                'params' => array('adminId' => array(), 'code' => array()),
                'base'   => 'admin',
                'rule'   => false,
            ),
        );
    }

    /**
     * The three permalink structures an admin can rewrite, declared once.
     *
     * Each is a small template stored in a preference. Both directions read this:
     * the pattern that matches an incoming URL is compiled from it, and the link a
     * theme prints is the same template with values filled in.
     *
     * tokens    placeholder => what it means. `re` is the pattern that captures it
     *           (omit it for a placeholder that only exists when building a link);
     *           `param` names the request parameter that capture becomes.
     * variants  one entry per rule the structure produces, in the order they are
     *           tried. `locale` prefixes the two-part language code, `suffix`
     *           appends a pattern whose capture becomes `suffixParam`.
     * order     request parameters the structure fills, most specific first: the
     *           first placeholder present in the structure is the one that is used.
     * strip     characters removed from a built link.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function templates(): array
    {
        return array(
            'item' => array(
                'pref'   => 'rewrite_item_url',
                'to'     => array('page' => 'item'),
                'tokens' => array(
                    '{ITEM_ID}'    => array('re' => '([0-9]+)', 'param' => 'id'),
                    '{ITEM_TITLE}' => array('re' => '.*'),
                    '{ITEM_CITY}'  => array('re' => '.*'),
                    '{CATEGORIES}' => array('re' => '.*'),
                ),
                'order'  => array('id'),
                'strip'  => '?',
                'variants' => array(
                    // A query string is cut off before the rules run, so neither
                    // comments-page rule can ever match. They are kept because the
                    // parameter still arrives as the ordinary GET one it is, and
                    // removing a published rule is not worth the nothing it buys.
                    array('locale' => true,  'suffix' => '\\?comments-page=([0-9al]*)',
                        'suffixParam' => 'comments-page', 'tail' => '$'),
                    array('locale' => false, 'suffix' => '\\?comments-page=([0-9al]*)',
                        'suffixParam' => 'comments-page', 'tail' => '$'),
                    array('locale' => true,  'tail' => '$'),
                    array('locale' => false, 'tail' => '$'),
                ),
            ),
            'page' => array(
                'pref'   => 'rewrite_page_url',
                'to'     => array('page' => 'page'),
                'tokens' => array(
                    '{PAGE_ID}'    => array('re' => '([0-9]+)', 'param' => 'id'),
                    '{PAGE_SLUG}'  => array('re' => '([\p{L}\p{N}_\-,]+)', 'param' => 'slug', 'enc' => true),
                    // Never an accepted keyword on the permalinks screen, still
                    // filled in when a structure happens to carry it.
                    '{PAGE_TITLE}' => array(),
                ),
                'order'  => array('id', 'slug'),
                'variants' => array(
                    array('locale' => false, 'tail' => '/?$'),
                    array('locale' => true,  'tail' => '/?$'),
                ),
            ),
            'category' => array(
                'pref'   => 'rewrite_cat_url',
                'to'     => array('page' => 'search'),
                'tokens' => array(
                    '{CATEGORIES}'     => array('re' => '(.+)', 'param' => 'sCategory'),
                    '{CATEGORY_NAME}'  => array('re' => '([^/]+)', 'param' => 'sCategory'),
                    '{CATEGORY_ID}'    => array('re' => '([0-9]+)', 'param' => 'sCategory'),
                    // The older spelling, sanitised away on save and still built.
                    '{CATEGORY_SLUG}'  => array(),
                ),
                'order'  => array('sCategory'),
                'variants' => array(
                    array('locale' => false, 'suffix' => '/([0-9]+)', 'suffixParam' => 'iPage', 'tail' => '$'),
                    array('locale' => false, 'tail' => '/?$'),
                ),
            ),
        );
    }

    /**
     * Match patterns for one permalink structure, in the order they are tried.
     *
     * @param string $name Template name
     *
     * @return array<string,string> pattern => rewrite target
     */
    public static function templateRules(string $name): array
    {
        $templates = self::templates();
        if (!isset($templates[$name])) {
            return array();
        }
        $tpl      = $templates[$name];
        $structure = (string)osc_get_preference($tpl['pref']);

        // Where each placeholder sits in the structure decides which capture it is.
        $found = array();
        foreach ($tpl['tokens'] as $token => $spec) {
            if (!isset($spec['re'])) {
                continue;
            }
            $at = stripos($structure, $token);
            if ($at !== false) {
                $found[$at] = array($token, $spec);
            }
        }
        ksort($found);

        $body     = $structure;
        $captures = array();
        $index    = 0;
        foreach ($found as $entry) {
            list($token, $spec) = $entry;
            $body = str_ireplace($token, $spec['re'], $body);
            if (strpos($spec['re'], '(') === 0) {
                $index++;
                // First placeholder wins: a structure naming a parameter twice is
                // answered by the one the reader sees first.
                if (isset($spec['param']) && !isset($captures[$spec['param']])) {
                    $captures[$spec['param']] = $index;
                }
            }
        }

        $rules = array();
        foreach ($tpl['variants'] as $variant) {
            $shift   = empty($variant['locale']) ? 0 : 2;
            $pattern = (empty($variant['locale']) ? '^' : '^([a-z]{2})_([A-Z]{2})/')
                . $body . ($variant['suffix'] ?? '') . $variant['tail'];

            $query = $tpl['to'];
            foreach ($tpl['order'] as $param) {
                if (isset($captures[$param])) {
                    $query[$param] = '$' . ($captures[$param] + $shift);
                }
            }
            if (!empty($variant['locale'])) {
                $query['lang'] = '$1_$2';
            }
            if (isset($variant['suffixParam'])) {
                $query[$variant['suffixParam']] = '$' . ($index + $shift + 1);
            }

            $target = 'index.php?';
            foreach ($query as $k => $v) {
                $target .= ($target === 'index.php?' ? '' : '&') . $k . '=' . $v;
            }
            $rules[$pattern] = $target;
        }

        return $rules;
    }

    /**
     * Fill one permalink structure in. Returns the path only, with no base URL and
     * no language prefix -- the caller owns both.
     *
     * @param string               $name   Template name
     * @param array<string,string> $values Placeholder name (no braces) => value
     *
     * @return string
     */
    public static function expand(string $name, array $values): string
    {
        $templates = self::templates();
        if (!isset($templates[$name])) {
            return '';
        }
        $tpl  = $templates[$name];
        $path = (string)osc_get_preference($tpl['pref']);

        foreach ($tpl['tokens'] as $token => $spec) {
            $key = trim($token, '{}');
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = (string)$values[$key];
            $path  = str_ireplace($token, empty($spec['enc']) ? $value : urlencode($value), $path);
        }

        return isset($tpl['strip']) ? str_replace($tpl['strip'], '', $path) : $path;
    }

    /**
     * Match patterns for every route that has one, in table order.
     *
     * A route whose path preference is empty is skipped: an empty path compiles to
     * '^/?$', which would answer the site's front page. That is reachable in
     * practice -- the table is rebuilt the moment new code is deployed, which can
     * be before the release's migration has seeded a newly added preference.
     *
     * @param string|null $group Only this block of the table, or all of it
     *
     * @return array<string,string> pattern => rewrite target
     */
    public static function rules(?string $group = null): array
    {
        $rules = array();
        foreach (self::all() as $route) {
            if (($route['rule'] ?? true) === false) {
                continue;
            }
            if ($group !== null && ($route['group'] ?? 'account') !== $group) {
                continue;
            }
            $path = trim((string)osc_get_preference($route['pref']), '/');
            if ($path === '') {
                continue;
            }

            $pattern  = '^' . $path;
            $captures = array();
            foreach (($route['params'] ?? array()) as $name => $spec) {
                if (!isset($spec['re'])) {
                    continue;
                }
                $pattern    .= ($spec['sep'] ?? self::DEFAULT_SEPARATOR) . $spec['re'];
                $captures[]  = $name;
            }
            $pattern .= $route['tail'] ?? '/?$';

            $query = $route['to'];
            foreach (self::order($route, $captures) as $name) {
                $query[$name] = '$' . (array_search($name, $captures, true) + 1);
            }

            $target = 'index.php?';
            foreach ($query as $k => $v) {
                $target .= ($target === 'index.php?' ? '' : '&') . $k . '=' . $v;
            }
            $rules[$pattern] = $target;
        }

        return $rules;
    }

    /**
     * Build a route's URL. Returns '' for a name the table does not hold.
     *
     * @param string              $name Route name
     * @param array<string,mixed> $args Values for the route's parameters
     *
     * @return string
     */
    public static function url(string $name, array $args = array()): string
    {
        $routes = self::all();
        if (!isset($routes[$name])) {
            return '';
        }
        $route  = $routes[$name];
        $admin  = ($route['base'] ?? 'web') === 'admin';
        $params = $route['params'] ?? array();

        $friendly = !$admin && isset($route['pref']) && osc_rewrite_enabled();
        if ($friendly) {
            $path  = trim((string)osc_get_preference($route['pref']), '/');
            $query = array();
            foreach ($params as $key => $spec) {
                $value = (string)($args[$key] ?? '');
                if (!empty($spec['query'])) {
                    if ($value !== '') {
                        $query[$key] = self::encode($value, $spec);
                    }
                    continue;
                }
                if ($value === '' && empty($spec['keepEmpty'])) {
                    continue;
                }
                $path .= '/' . self::encode($value, $spec);
            }

            return osc_base_url() . $path . ($query === array() ? '' : '?' . self::join($query));
        }

        $query = $route['to'];
        foreach (self::order($route, array_keys($params)) as $key) {
            $value = (string)($args[$key] ?? '');
            if ($value === '') {
                continue;
            }
            $query[$key] = self::encode($value, $params[$key]);
        }

        return ($admin ? osc_admin_base_url(true) : osc_base_url(true)) . '?' . self::join($query);
    }

    /**
     * Parameter names in the order they are written into a query string.
     *
     * @param array<string,mixed> $route
     * @param array<int,string>   $fallback
     *
     * @return array<int,string>
     */
    private static function order(array $route, array $fallback): array
    {
        return $route['order'] ?? $fallback;
    }

    /**
     * @param string             $value
     * @param array<string,mixed> $spec
     *
     * @return string
     */
    private static function encode(string $value, array $spec): string
    {
        if (($spec['enc'] ?? false) === 'raw') {
            return rawurlencode($value);
        }

        return !empty($spec['enc']) ? urlencode($value) : $value;
    }

    /**
     * @param array<string,string> $query
     *
     * @return string
     */
    private static function join(array $query): string
    {
        $parts = array();
        foreach ($query as $k => $v) {
            $parts[] = $k . '=' . $v;
        }

        return implode('&', $parts);
    }
}
