<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Helper Users
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Gets a specific field from current user
 *
 * @param string $field
 * @param string $locale
 *
 * @return mixed
 */
function osc_user_field($field, $locale = '')
{
    if (View::newInstance()->_exists('users')) {
        $user = View::newInstance()->_current('users');
    } else {
        $user = View::newInstance()->_get('user');
    }

    return osc_field($user, $field, $locale);
}


/**
 * Gets user array from view
 *
 * @return array
 */
function osc_user()
{
    if (View::newInstance()->_exists('users')) {
        $user = View::newInstance()->_current('users');
    } else {
        $user = View::newInstance()->_get('user');
    }

    return $user;
}


/**
 * Gets true if user is logged in web
 *
 * @return boolean
 */
function osc_is_web_user_logged_in()
{
    $user = osc_resolve_web_user();
    if (isset($user['b_enabled'], $user['b_active']) && $user['b_enabled'] == 1 && $user['b_active'] == 1) {
        // Expose the identity through the historical Session::_get('userId') readers, but
        // request-scoped only — no physical session is written, so the visitor stays
        // session-free and cacheable. Identity persists across requests via the signed cookie.
        osc_web_user_apply_identity($user);

        return true;
    }

    return false;
}


/**
 * Resolve the current front-end user for this request, or null.
 *
 * Preference order: the request cache, then the signed identity cookie (the primary,
 * session-free source), then — transitionally — a physical session left behind by a login
 * that predates the cookie mechanism. The resolved row is cached in the View for the rest
 * of the request. Read-only: never starts a session for an anonymous, cookieless visitor.
 *
 * @return array|null
 */
function osc_resolve_web_user()
{
    if (View::newInstance()->_exists('_loggedUser')) {
        return View::newInstance()->_get('_loggedUser');
    }

    $cookieId     = Cookie::newInstance()->get_value('oc_userId');
    $cookieSecret = Cookie::newInstance()->get_value('oc_userSecret');
    if ($cookieId != '' && $cookieSecret != '') {
        $candidate = User::newInstance()->findByPrimaryKey($cookieId);
        if (isset($candidate['pk_i_id'])
            && \mindstellar\security\RememberMe::verify('web', $cookieId, $cookieSecret, $candidate['s_password'])
        ) {
            View::newInstance()->_exportVariableToView('_loggedUser', $candidate);

            return $candidate;
        }
    }

    // Transitional: honour identity still held in a physical session from before the cookie
    // mechanism shipped, so upgrading does not log anyone out.
    $sessionId = Session::newInstance()->_get('userId');
    if ($sessionId != '') {
        $candidate = User::newInstance()->findByPrimaryKey($sessionId);
        if (isset($candidate['pk_i_id'])) {
            View::newInstance()->_exportVariableToView('_loggedUser', $candidate);

            return $candidate;
        }
    }

    return null;
}


/**
 * Populate the request-scoped identity for the given user.
 *
 * Uses Session::_setEphemeral so the historical Session::_get('userId'|'userName'|...)
 * readers keep working without a physical session being started or written.
 *
 * @param array $user
 *
 * @return void
 */
function osc_web_user_apply_identity($user)
{
    $session = Session::newInstance();
    $session->_setEphemeral('userId', $user['pk_i_id']);
    $session->_setEphemeral('userName', $user['s_name']);
    $session->_setEphemeral('userEmail', $user['s_email']);
    $session->_setEphemeral('userPhone', $user['s_phone_mobile'] ?: $user['s_phone_land']);
    View::newInstance()->_exportVariableToView('_loggedUser', $user);
}


/**
 * Log a front-end user in by issuing the signed identity cookie.
 *
 * Every login (remember or not) goes through here, so identity lives in an HMAC-signed
 * cookie ({@see \mindstellar\security\RememberMe}) rather than the session — logged-in
 * requests need no server session, enabling reverse-proxy caching and multi-server
 * deployment without sticky sessions. "Remember me" only controls the cookie lifetime
 * (persistent vs browser-session); the signed token binds the account's password hash, so
 * a password change still invalidates every outstanding cookie.
 *
 * @param array $user     the authenticated user row (needs pk_i_id, s_password, s_name, …)
 * @param bool  $remember persist across browser restarts when true
 *
 * @return void
 */
function osc_web_user_login($user, $remember = false)
{
    $cookie = Cookie::newInstance();
    if ($remember) {
        // Persistent: the cookie and its signed token both last a year.
        $tokenTtl = osc_time_cookie();
        $cookie->set_expires($tokenTtl);
    } else {
        // Browser-session cookie (dropped when the browser closes). The signed token
        // also carries a short absolute TTL so a stolen non-remember cookie value can
        // only be replayed for a brief window rather than a year. Filterable for sites
        // that want longer-lived non-persistent logins.
        $tokenTtl = (int)osc_apply_filter('non_remember_login_ttl', 2 * 3600);
        $cookie->set_expires(0);
    }
    $cookie->push('oc_userId', $user['pk_i_id']);
    $cookie->push('oc_userSecret', \mindstellar\security\RememberMe::issue(
        'web',
        $user['pk_i_id'],
        $user['s_password'],
        $tokenTtl
    ));
    $cookie->set();

    // Make the identity live for the remainder of this request without a session.
    osc_web_user_apply_identity($user);
}


/**
 * Resolve front-end identity early in the bootstrap so the historical
 * Session::_get('userId') readers see a cookie-authenticated user even before any
 * osc_is_web_user_logged_in() call. No-op — and no session, no DB query — for an
 * anonymous, cookieless visitor, which keeps such requests cacheable.
 *
 * @return void
 */
function osc_run_web_user_identity()
{
    if (View::newInstance()->_exists('_loggedUser')) {
        return;
    }
    // A physical session already carrying identity (transitional pre-upgrade login)
    // already satisfies the readers — leave it alone.
    if (Session::newInstance()->_get('userId') != '') {
        return;
    }
    if (Cookie::newInstance()->get_value('oc_userId') == '') {
        return;
    }
    osc_is_web_user_logged_in();
}


/**
 * Gets logged user id
 *
 * @return int
 */
function osc_logged_user_id()
{
    return (int)Session::newInstance()->_get('userId');
}


/**
 * Gets logged user mail
 *
 * @return string
 */
function osc_logged_user_email()
{
    return (string)Session::newInstance()->_get('userEmail');
}


/**
 * Gets logged user name
 *
 * @return string
 */
function osc_logged_user_name()
{
    return (string)Session::newInstance()->_get('userName');
}


/**
 * Gets logged user phone
 *
 * @return string
 */
function osc_logged_user_phone()
{
    return (string)Session::newInstance()->_get('userPhone');
}


/**
 * Gets user's profile url
 *
 * @param null $id
 *
 * @return string
 */
function osc_user_public_profile_url($id = null)
{
    if ($id == null) {
        $id = osc_user_id();
    }
    if ($id != '') {
        if (osc_rewrite_enabled()) {
            $user = User::newInstance()->findByPrimaryKey($id);
            $path = osc_base_url() . osc_get_preference('rewrite_user_profile') . '/' . $user['s_username'];
        } else {
            $path = sprintf(osc_base_url(true) . '?page=user&action=pub_profile&id=%d', $id);
        }
    } else {
        $path = '';
    }

    return $path;
}


/**
 * Gets current items page from public profile
 *
 * @param string $page
 * @param bool   $itemsPerPage
 *
 * @return string
 */
function osc_user_list_items_pub_profile_url($page = '', $itemsPerPage = false)
{
    $path = osc_user_public_profile_url();

    // On a friendly-URL install the query string is stripped before routing, so a
    // ?iPage= page number never reaches the controller. Carry the page as a path
    // segment instead (matched by the paginated profile rewrite rule).
    if (osc_rewrite_enabled() && $page) {
        $path = rtrim($path, '/') . '/' . $page;
        if ($itemsPerPage) {
            $path .= '?itemsPerPage=' . $itemsPerPage;
        }

        return $path;
    }

    if ($itemsPerPage) {
        $path .= '?itemsPerPage=' . $itemsPerPage;
    }
    if ($page) {
        $path .= ($itemsPerPage ? '&' : '?') . 'iPage=' . $page;
    }

    return $path;
}


/**
 * Gets true if admin user is logged in
 *
 * @return boolean
 */
function osc_is_admin_user_logged_in()
{
    if (Session::newInstance()->_get('adminId') != '') {
        $admin = Admin::newInstance()->findByPrimaryKey(Session::newInstance()->_get('adminId'));
        if (isset($admin['pk_i_id'])) {
            return true;
        }

        return false;
    }

    //can already be a logged user or not, we'll take a look into the cookie
    if (Cookie::newInstance()->get_value('oc_adminId') != ''
        && Cookie::newInstance()->get_value('oc_adminSecret') != ''
    ) {
        $adminId = Cookie::newInstance()->get_value('oc_adminId');
        $admin   = Admin::newInstance()->findByPrimaryKey($adminId);
        if (isset($admin['pk_i_id'])
            && \mindstellar\security\RememberMe::verify(
                'admin',
                $adminId,
                Cookie::newInstance()->get_value('oc_adminSecret'),
                $admin['s_password']
            )
        ) {
            Session::newInstance()->_set('adminId', $admin['pk_i_id']);
            Session::newInstance()->_set('adminUserName', $admin['s_username']);
            Session::newInstance()->_set('adminName', $admin['s_name']);
            Session::newInstance()->_set('adminEmail', $admin['s_email']);
            Session::newInstance()->_set('adminLocale', Cookie::newInstance()->get_value('oc_adminLocale'));

            return true;
        }

        return false;
    }

    return false;
}


/**
 * Gets logged admin id
 *
 * @return int
 */
function osc_logged_admin_id()
{
    return (int)Session::newInstance()->_get('adminId');
}


/**
 * Gets logged admin username
 *
 * @return string
 */
function osc_logged_admin_username()
{
    return (string)Session::newInstance()->_get('adminUserName');
}


/**
 * Gets logged admin name
 *
 * @return string
 */
function osc_logged_admin_name()
{
    return (string)Session::newInstance()->_get('adminName');
}


/**
 * Gets logged admin email
 *
 * @return string
 */
function osc_logged_admin_email()
{
    return (string)Session::newInstance()->_get('adminEmail');
}


/**
 * Gets name of current user
 *
 * @return string
 */
function osc_user_name()
{
    return (string)osc_user_field('s_name');
}


/**
 * Gets email of current user
 *
 * @return string
 */
function osc_user_email()
{
    return (string)osc_user_field('s_email');
}


/**
 * Gets username of current user
 *
 * @return string
 */
function osc_user_username()
{
    return (string)osc_user_field('s_username');
}


/**
 * Gets registration date of current user
 *
 * @return string
 */
function osc_user_regdate()
{
    return (string)osc_user_field('dt_reg_date');
}


/**
 * Gets id of current user
 *
 * @return int
 */
function osc_user_id()
{
    return (int)osc_user_field('pk_i_id');
}


/**
 * Gets last access date
 *
 * @return string
 */
function osc_user_access_date()
{
    return (string)osc_user_field('dt_access_date');
}


/**
 * Gets last access ip
 *
 * @return string
 */
function osc_user_access_ip()
{
    return (string)osc_user_field('s_access_ip');
}


/**
 * Gets website of current user
 *
 * @return string
 */
function osc_user_website()
{
    return (string)osc_user_field('s_website');
}


/**
 * Gets description/information of current user
 *
 * @param string $locale
 *
 * @return string
 */
function osc_user_info($locale = '')
{
    $userId = osc_user_id();
    if ($locale == '') {
        $locale = osc_current_user_locale();
    }
    $info = osc_user_field('s_info', $locale);
    $info = osc_apply_filter('user_info', $info, $userId, $locale);
    if ($info == '') {
        $info = osc_user_field('s_info', osc_language());
        $info = osc_apply_filter('user_info', $info, $userId, osc_language());
        if ($info == '') {
            $aLocales = osc_get_locales();
            foreach ($aLocales as $locale2) {
                $info = osc_user_field('s_info', $locale2['pk_c_code']);
                $info = osc_apply_filter('user_info', $info, $userId, $locale2['pk_c_code']);
                if ($info != '') {
                    break;
                }
            }
        }
    }

    return (string)$info;
}


/**
 * Gets phone of current user
 *
 * @return string
 */
function osc_user_phone_land()
{
    return (string)osc_user_field('s_phone_land');
}


/**
 * Gets cell phone of current user
 *
 * @return string
 */
function osc_user_phone_mobile()
{
    return (string)osc_user_field('s_phone_mobile');
}


/**
 * Gets phone_land if exist, else if exist return phone_mobile,
 * else return string blank
 *
 * @return string
 */
function osc_user_phone()
{
    if (osc_user_field('s_phone_land') != '') {
        return osc_user_field('s_phone_land');
    }

    if (osc_user_field('s_phone_mobile') != '') {
        return osc_user_field('s_phone_mobile');
    }

    return '';
}


/**
 * Gets country of current user
 *
 * @return string
 */
function osc_user_country()
{
    return (string)osc_user_field('s_country');
}


/**
 * Gets region of current user
 *
 * @return string
 */
function osc_user_region()
{
    return (string)osc_user_field('s_region');
}


/**
 * Gets region id of current user
 *
 * @return string
 */
function osc_user_region_id()
{
    return (string)osc_user_field('fk_i_region_id');
}


/**
 * Gets city of current user
 *
 * @return string
 */
function osc_user_city()
{
    return (string)osc_user_field('s_city');
}


/**
 * Gets city id of current user
 *
 * @return string
 */
function osc_user_city_id()
{
    return (string)osc_user_field('fk_i_city_id');
}


/**
 * Gets city area of current user
 *
 * @return string
 */
function osc_user_city_area()
{
    return (string)osc_user_field('s_city_area');
}


/**
 * Gets city area id of current user
 *
 * @return string
 */
function osc_user_city_area_id()
{
    return (string)osc_user_field('fk_i_city_area_id');
}


/**
 * Gets address of current user
 *
 * @return string
 */
function osc_user_address()
{
    return (string)osc_user_field('s_address');
}


/**
 * Gets postal zip of current user
 *
 * @return string
 */
function osc_user_zip()
{
    return (string)osc_user_field('s_zip');
}


/**
 * Gets latitude of current user
 *
 * @return float
 */
function osc_user_latitude()
{
    return (float)osc_user_field('d_coord_lat');
}


/**
 * Gets longitude of current user
 *
 * @return float
 */
function osc_user_longitude()
{
    return (float)osc_user_field('d_coord_long');
}


/**
 * Gets type (company/user) of current user
 *
 * @return float
 */
function osc_user_is_company()
{
    return (bool)osc_user_field('b_company');
}


/**
 * Gets number of items validated of current user
 *
 * @return int
 */
function osc_user_items_validated()
{
    return (int)osc_user_field('i_items');
}


/**
 * Gets number of comments validated of current user
 *
 * @return int
 */
function osc_user_comments_validated()
{
    return osc_user_field('i_comments');
}


/**
 * Gets number of users
 *
 * @param string $condition
 *
 * @return int
 */
function osc_total_users($condition = '')
{
    switch ($condition) {
        case 'active':
            return User::newInstance()->countUsers('b_active = 1');
            break;
        case 'enabled':
            return User::newInstance()->countUsers('b_enabled = 1');
            break;
        default:
            return User::newInstance()->countUsers();
            break;
    }
}


/////////////
// ALERTS  //
/////////////

/**
 * Gets a specific field from current alert
 *
 * @param string $field
 *
 * @return mixed
 */
function osc_alert_field($field)
{
    return osc_field(View::newInstance()->_current('alerts'), $field, '');
}


/**
 * Gets next alert if there is, else return null
 *
 * @return array
 */
function osc_has_alerts()
{
    $result = View::newInstance()->_next('alerts');
    $alert  = osc_alert();
    View::newInstance()->_exportVariableToView('items', isset($alert['items']) ? $alert['items'] : array());

    return $result;
}


/**
 * Gets number of alerts in array alerts
 *
 * @return int
 */
function osc_count_alerts()
{
    return (int)View::newInstance()->_count('alerts');
}


/**
 * Gets current alert fomr view
 *
 * @return array
 */
function osc_alert()
{
    return View::newInstance()->_current('alerts');
}


/**
 * Gets search field of current alert
 *
 * @return string
 */
function osc_alert_search()
{
    return (string)osc_alert_field('s_search');
}


/**
 * Gets secret of current alert
 *
 * @return string
 */
function osc_alert_secret()
{
    return (string)osc_alert_field('s_secret');
}


/**
 * Gets id of current alert
 *
 * @return string
 */
function osc_alert_id()
{
    return (string)osc_alert_field('pk_i_id');
}


/**
 * Gets aate of current alert
 *
 * @return string
 */
function osc_alert_date()
{
    return (string)osc_alert_field('dt_date');
}


/**
 * Gets unsub date of current alert
 *
 * @return string
 */
function osc_alert_unsub_date()
{
    return (string)osc_alert_field('dt_unsub_date');
}


/**
 * Gets type of current alert
 *
 * @return string
 */
function osc_alert_type()
{
    return (string)osc_alert_field('e_type');
}


/**
 * Gets active of current alert
 *
 * @return boolean
 */
function osc_alert_is_active()
{
    return (bool)osc_alert_field('b_active');
}


/**
 * Public URL of a user's avatar, or a bundled placeholder when they have none.
 *
 * Resolves the user's single 'user'-owned resource through the polymorphic
 * resource layer, so the URL reflects whichever storage adapter the avatar lives
 * on (local or remote). The main image is stored at the 'normal' variant (its
 * base file, no suffix) and a 'thumbnail' variant; pass 'normal' for the base
 * file or 'thumbnail' (the default) for the small one.
 *
 * @param int|null $userId  defaults to the logged-in web user
 * @param string   $variant 'normal' (base file), 'thumbnail', 'preview', 'original'
 *
 * @return string
 */
function osc_user_avatar_url(?int $userId = null, string $variant = 'thumbnail'): string
{
    if ($userId === null) {
        $userId = osc_logged_user_id();
    }
    $userId = (int)$userId;

    if ($userId > 0) {
        $resources = osc_get_resources('user', $userId);
        if (!empty($resources)) {
            return osc_get_resource_url($resources[0], $variant === 'normal' ? '' : $variant);
        }
    }

    return osc_base_url() . 'oc-includes/images/avatar-placeholder.svg';
}


/**
 * Whether a user has an uploaded avatar.
 *
 * @param int|null $userId defaults to the logged-in web user
 *
 * @return bool
 */
function osc_has_user_avatar(?int $userId = null): bool
{
    if ($userId === null) {
        $userId = osc_logged_user_id();
    }
    $userId = (int)$userId;

    return $userId > 0 && !empty(osc_get_resources('user', $userId));
}


/**
 * Gets next user in users array
 *
 * @return array
 */
function osc_prepare_user_info()
{
    if (!View::newInstance()->_exists('users')) {
        View::newInstance()
            ->_exportVariableToView('users', array(User::newInstance()->findByPrimaryKey(osc_item_user_id())));
    }

    return View::newInstance()->_next('users');
}
