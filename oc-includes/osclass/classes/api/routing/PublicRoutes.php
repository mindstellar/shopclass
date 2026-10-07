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

namespace mindstellar\api\routing;

use mindstellar\api\controller\AccountController;
use mindstellar\api\controller\AccountKeysController;
use mindstellar\api\controller\AlertsController;
use mindstellar\api\controller\AuthController;
use mindstellar\api\controller\CategoriesController;
use mindstellar\api\controller\CommentsController;
use mindstellar\api\controller\ListingsController;
use mindstellar\api\controller\ListingWritesController;
use mindstellar\api\controller\LocationsController;
use mindstellar\api\controller\PageTokenController;
use mindstellar\api\controller\PhotosController;
use mindstellar\api\controller\RegistrationController;
use mindstellar\api\controller\SiteController;
use mindstellar\api\controller\UsersController;
use mindstellar\api\read\ListingSort;
use mindstellar\api\RouteSpec;
use mindstellar\api\schema\OpenApi;
use mindstellar\api\schema\Schema;
use mindstellar\listing\ListingStatus;

/**
 * The v1 routes anyone, a user or a user's app calls: reads, sign-in, the account and
 * listing writes. AdminRoutes holds the admin ones.
 */
final class PublicRoutes
{
    private const LOCALE = ['type' => 'string', 'pattern' => '^[A-Za-z]{2,3}_[A-Za-z]{2}$'];

    private const INCLUDE = ['type' => 'string', 'maxLength' => 100, 'description' => 'Comma list: custom_fields, translations.'];

    private const COUNT = ['type' => 'boolean', 'description' => 'true: also count every match for meta.total; skipped otherwise, as it costs a query.'];

    private const CURSOR = ['type' => 'string', 'maxLength' => 1024];

    private function __construct()
    {
    }

    /**
     * 'METHOD path' => spec.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $view     = ['locale' => self::LOCALE, 'fields' => ['type' => 'string', 'maxLength' => 500]];
        $listings = self::listingFilters();
        $byUser   = array_diff_key($listings, ['user' => true]);
        $places   = [
            'q'      => ['type' => 'string', 'maxLength' => 100, 'description' => 'Names starting with this.'],
            'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => LocationsController::MAX_LIMIT],
            'cursor' => self::CURSOR,
        ];
        $paging   = ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'cursor' => self::CURSOR];

        return [
            'GET ' => RouteSpec::read(
                handler: [SiteController::class, 'show'],
                tag: 'Site',
                summary: 'The site, its locales and its collections',
                response: 'SiteDocument'
            ),
            'GET listings' => RouteSpec::read(
                handler: [ListingsController::class, 'index'],
                tag: 'Listings',
                summary: 'Search listings',
                response: 'ListingPage',
                query: $listings,
                errors: [400, 422]
            ),
            'GET listings/{id}' => RouteSpec::read(
                handler: [ListingsController::class, 'show'],
                tag: 'Listings',
                summary: 'One listing',
                response: 'ListingDocument',
                query: $view + ['include' => self::INCLUDE],
                errors: [404]
            ),
            'GET listings/{id}/photos' => RouteSpec::read(
                handler: [ListingsController::class, 'photos'],
                tag: 'Listings',
                summary: 'A listing\'s photos',
                response: 'PhotoList',
                errors: [404]
            ),
            'GET listings/{id}/photos/{photo}' => RouteSpec::read(
                handler: [ListingsController::class, 'photo'],
                tag: 'Listings',
                summary: 'One photo of a listing',
                response: 'PhotoDocument',
                errors: [404]
            ),
            'GET listings/{id}/comments' => RouteSpec::read(
                handler: [ListingsController::class, 'comments'],
                tag: 'Listings',
                summary: 'A listing\'s approved comments, oldest first',
                response: 'CommentPage',
                query: $paging + ['count' => self::COUNT],
                errors: [403, 404]
            ),
            'POST listings' => RouteSpec::write(
                handler: [ListingWritesController::class, 'create'],
                tag: 'Listings',
                summary: 'Post a listing; moderation, the listing limit and the posting wait apply as on the form',
                auth: RouteSpec::AUTH_USER,
                scope: 'listings:write',
                body: 'ListingInput',
                response: 'SavedListing',
                status: 201,
                errors: [403, 429]
            ),
            'PATCH listings/{id}' => RouteSpec::write(
                handler: [ListingWritesController::class, 'update'],
                tag: 'Listings',
                summary: 'Edit your listing; members not sent keep their values',
                auth: RouteSpec::AUTH_USER,
                scope: 'listings:write',
                body: 'ListingPatch',
                response: 'SavedListing',
                errors: [403, 404]
            ),
            'DELETE listings/{id}' => RouteSpec::write(
                handler: [ListingWritesController::class, 'delete'],
                tag: 'Listings',
                summary: 'Delete your listing',
                auth: RouteSpec::AUTH_USER,
                scope: 'listings:delete',
                status: 204,
                errors: [403, 404]
            ),
            'POST photos' => RouteSpec::write(
                handler: [PhotosController::class, 'stage'],
                tag: 'Listings',
                summary: 'Upload a photo for a listing you are about to post; its token lasts two hours. 200, not 201: the token names no resource to read',
                auth: RouteSpec::AUTH_USER,
                scope: 'listings:write',
                response: 'PhotoTokenDocument',
                upload: true
            ),
            'POST listings/{id}/photos' => RouteSpec::write(
                handler: [PhotosController::class, 'add'],
                tag: 'Listings',
                summary: 'Add a photo to your listing',
                auth: RouteSpec::AUTH_USER,
                scope: 'listings:write',
                response: 'PhotoDocument',
                status: 201,
                errors: [403, 404],
                upload: true
            ),
            'DELETE listings/{id}/photos/{photo}' => RouteSpec::write(
                handler: [PhotosController::class, 'remove'],
                tag: 'Listings',
                summary: 'Remove a photo from your listing',
                auth: RouteSpec::AUTH_USER,
                scope: 'listings:write',
                status: 204,
                errors: [403, 404]
            ),
            'POST listings/{id}/comments' => RouteSpec::write(
                handler: [CommentsController::class, 'create'],
                tag: 'Listings',
                summary: 'Comment on a listing; it may wait for approval',
                auth: RouteSpec::AUTH_USER,
                scope: 'comments:write',
                body: 'CommentInput',
                response: 'SavedComment',
                status: 201,
                errors: [403, 404, 429]
            ),
            'GET comments/{id}' => RouteSpec::read(
                handler: [CommentsController::class, 'show'],
                tag: 'Listings',
                summary: 'One comment: an approved one on a listing you can see, or your own',
                response: 'CommentDocument',
                errors: [404]
            ),
            'DELETE comments/{id}' => RouteSpec::write(
                handler: [CommentsController::class, 'delete'],
                tag: 'Listings',
                summary: 'Delete your own approved comment',
                auth: RouteSpec::AUTH_USER,
                scope: 'comments:write',
                status: 204,
                errors: [403, 404, 409]
            ),
            'GET categories' => RouteSpec::read(
                handler: [CategoriesController::class, 'index'],
                tag: 'Categories',
                summary: 'Every category, flat or as a tree',
                response: 'CategoryList',
                query: $view + ['tree' => ['type' => 'boolean']]
            ),
            'GET categories/{category}' => RouteSpec::read(
                handler: [CategoriesController::class, 'show'],
                tag: 'Categories',
                summary: 'One category by id or slug, with its custom fields',
                response: 'CategoryDocument',
                query: $view,
                errors: [404]
            ),
            'GET custom-fields' => RouteSpec::read(
                handler: [CategoriesController::class, 'fields'],
                tag: 'Categories',
                summary: 'Custom fields, all or a category\'s',
                response: 'CustomFieldList',
                query: ['locale' => self::LOCALE, 'category' => ['type' => 'string', 'maxLength' => 200]],
                errors: [422]
            ),
            'GET currencies' => RouteSpec::read(
                handler: [SiteController::class, 'currencies'],
                tag: 'Site',
                summary: 'The currencies listings are priced in',
                response: 'CurrencyList'
            ),
            'GET countries' => RouteSpec::read(
                handler: [LocationsController::class, 'countries'],
                tag: 'Locations',
                summary: 'Countries',
                response: 'CountryList',
                query: $places
            ),
            'GET countries/{code}/regions' => RouteSpec::read(
                handler: [LocationsController::class, 'regions'],
                tag: 'Locations',
                summary: 'A country\'s regions',
                response: 'RegionList',
                query: $places,
                errors: [404]
            ),
            'GET regions/{id}/cities' => RouteSpec::read(
                handler: [LocationsController::class, 'cities'],
                tag: 'Locations',
                summary: 'A region\'s cities',
                response: 'CityList',
                query: $places,
                errors: [404]
            ),
            'GET cities/{id}/areas' => RouteSpec::read(
                handler: [LocationsController::class, 'areas'],
                tag: 'Locations',
                summary: 'A city\'s areas',
                response: 'CityAreaList',
                query: $places,
                errors: [404]
            ),
            'GET users/{id}' => RouteSpec::read(
                handler: [UsersController::class, 'show'],
                tag: 'Users',
                summary: 'A user\'s public profile',
                response: 'UserDocument',
                query: $view,
                errors: [404]
            ),
            'GET users/{id}/listings' => RouteSpec::read(
                handler: [UsersController::class, 'listings'],
                tag: 'Users',
                summary: 'A user\'s live listings',
                response: 'ListingPage',
                query: $byUser,
                errors: [404]
            ),
            'POST auth/token' => RouteSpec::write(
                handler: [AuthController::class, 'token'],
                tag: 'Auth',
                summary: 'Sign in with a password, or swap a refresh token for new tokens',
                auth: RouteSpec::AUTH_NONE,
                scope: null,
                body: 'TokenRequest',
                response: 'TokenDocument',
                errors: [400, 403, 429],
                replayable: false,
                oauth: true
            ),
            'POST auth/sign-out' => RouteSpec::write(
                handler: [AuthController::class, 'signOut'],
                tag: 'Auth',
                summary: 'Sign out this sign-in, or every sign-in with all=true',
                auth: RouteSpec::AUTH_USER,
                scope: null,
                body: 'SignOutRequest',
                status: 204
            ),
            'GET auth/session' => RouteSpec::read(
                handler: [PageTokenController::class, 'show'],
                tag: 'Auth',
                summary: 'A fresh page token, for theme JavaScript on a page open longer than its token lives',
                response: 'SessionTokenDocument',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:read',
                errors: [403]
            ),
            'GET account' => RouteSpec::read(
                handler: [AccountController::class, 'show'],
                tag: 'Account',
                summary: 'The signed-in user\'s own profile',
                response: 'UserDocument',
                query: $view,
                auth: RouteSpec::AUTH_USER,
                scope: 'account:read'
            ),
            'PATCH account' => RouteSpec::write(
                handler: [AccountController::class, 'update'],
                tag: 'Account',
                summary: 'Edit the profile; a new e-mail address is confirmed by a link first',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:write',
                body: 'AccountInput',
                response: 'AccountDocument'
            ),
            'GET account/listings' => RouteSpec::read(
                handler: [AccountController::class, 'listings'],
                tag: 'Account',
                summary: 'Your own listings in any status, newest first',
                response: 'ListingPage',
                query: ['status' => Schema::listOf(ListingStatus::ALL)] + $paging + ['count' => self::COUNT, 'include' => self::INCLUDE] + $view,
                auth: RouteSpec::AUTH_USER,
                scope: 'account:read',
                errors: [400, 422]
            ),
            'POST account/password' => RouteSpec::write(
                handler: [AccountController::class, 'password'],
                tag: 'Account',
                summary: 'Change the password; every sign-in ends and this client gets a new one',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:write',
                body: 'PasswordChange',
                response: 'TokenDocument',
                errors: [429],
                replayable: false
            ),
            'POST account/sign-out-everywhere' => RouteSpec::write(
                handler: [AccountController::class, 'signOutEverywhere'],
                tag: 'Account',
                summary: 'Sign out of every device: web sign-ins, every API sign-in including this one, and personal keys',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:write',
                status: 204,
                errors: [403]
            ),
            'GET account/sessions' => RouteSpec::read(
                handler: [AccountController::class, 'sessions'],
                tag: 'Account',
                summary: 'The sign-ins and keys that act for this user',
                response: 'SessionList',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:read'
            ),
            'DELETE account/sessions/{session}' => RouteSpec::write(
                handler: [AccountController::class, 'endSession'],
                tag: 'Account',
                summary: 'End one sign-in, or revoke one key',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:write',
                status: 204,
                errors: [404]
            ),
            'GET account/keys' => RouteSpec::read(
                handler: [AccountKeysController::class, 'index'],
                tag: 'Account',
                summary: 'Personal API keys, when the site allows them',
                response: 'PersonalKeyList',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:write'
            ),
            'POST account/keys' => RouteSpec::write(
                handler: [AccountKeysController::class, 'create'],
                tag: 'Account',
                summary: 'Make a personal API key; its token is shown once',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:write',
                body: 'PersonalKeyInput',
                response: 'PersonalKeyDocument',
                status: 201,
                replayable: false
            ),
            'GET account/keys/{id}' => RouteSpec::read(
                handler: [AccountKeysController::class, 'show'],
                tag: 'Account',
                summary: 'One personal API key; never its secret',
                response: 'PersonalKeyDocument',
                errors: [404],
                auth: RouteSpec::AUTH_USER,
                scope: 'account:write'
            ),
            'DELETE account/keys/{id}' => RouteSpec::write(
                handler: [AccountKeysController::class, 'revoke'],
                tag: 'Account',
                summary: 'Revoke a personal API key',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:write',
                status: 204,
                errors: [404, 409]
            ),
            'GET account/alerts' => RouteSpec::read(
                handler: [AlertsController::class, 'index'],
                tag: 'Account',
                summary: 'Your saved searches',
                response: 'AlertList',
                auth: RouteSpec::AUTH_USER,
                scope: 'alerts:write'
            ),
            'POST account/alerts' => RouteSpec::write(
                handler: [AlertsController::class, 'create'],
                tag: 'Account',
                summary: 'Save a search, with the filters GET /listings takes; an existing one is answered as it is',
                auth: RouteSpec::AUTH_USER,
                scope: 'alerts:write',
                body: 'AlertInput',
                response: 'AlertDocument',
                status: 201,
                errors: [403],
                alsoStatuses: [200]
            ),
            'GET account/alerts/{id}' => RouteSpec::read(
                handler: [AlertsController::class, 'show'],
                tag: 'Account',
                summary: 'One of your saved searches',
                response: 'AlertDocument',
                errors: [404],
                auth: RouteSpec::AUTH_USER,
                scope: 'alerts:write'
            ),
            'DELETE account/alerts/{id}' => RouteSpec::write(
                handler: [AlertsController::class, 'delete'],
                tag: 'Account',
                summary: 'Stop a saved search',
                auth: RouteSpec::AUTH_USER,
                scope: 'alerts:write',
                status: 204,
                errors: [404]
            ),
            'POST users' => RouteSpec::write(
                handler: [RegistrationController::class, 'register'],
                tag: 'Users',
                summary: 'Sign up, when the site allows it; the activation e-mail goes out as from the form',
                auth: RouteSpec::AUTH_NONE,
                scope: null,
                body: 'Registration',
                response: 'NewAccountDocument',
                status: 201,
                errors: [403, 429]
            ),
            'GET openapi.json' => RouteSpec::read(
                handler: [OpenApi::class, 'show'],
                tag: 'Meta',
                summary: 'This API described in OpenAPI 3.1, with the site\'s plugin endpoints',
                response: 'OpenApiDocument',
                auth: RouteSpec::AUTH_NONE,
                scope: null
            ),
        ];
    }

    /**
     * The filters of a listing search.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function listingFilters(): array
    {
        $list = ['type' => ['string', 'array'], 'items' => ['type' => 'string'], 'description' => 'One value, a comma list, or repeated.'];

        return [
            'q'           => ['type' => 'string', 'maxLength' => 200],
            'category'    => $list + ['description' => 'Category ids or slugs; subcategories are included.'],
            'country'     => $list,
            'region'      => $list,
            'city'        => $list,
            'city_area'   => $list,
            'user'        => $list,
            'locale'      => self::LOCALE,
            'price_min'   => ['type' => 'integer', 'minimum' => 0],
            'price_max'   => ['type' => 'integer', 'minimum' => 0],
            'with_photos' => ['type' => 'boolean'],
            'premium'     => ['type' => 'boolean'],
            'custom_field' => ['type' => 'object', 'description' => 'custom_field[<id>]=<value>', 'additionalProperties' => ['type' => ['string', 'array'], 'items' => ['type' => 'string']]],
            'sort'        => ['type' => 'string', 'enum' => ListingSort::SORTS],
            'order'       => ['type' => 'string', 'enum' => ['asc', 'desc']],
            'limit'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'cursor'      => self::CURSOR,
            'count'       => self::COUNT,
            'fields'      => ['type' => 'string', 'maxLength' => 500],
            'include'     => self::INCLUDE,
        ];
    }
}
