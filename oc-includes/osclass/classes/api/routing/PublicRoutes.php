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
use mindstellar\api\controller\AccountPushController;
use mindstellar\api\controller\AlertsController;
use mindstellar\api\controller\AuthController;
use mindstellar\api\controller\CartController;
use mindstellar\api\controller\CategoriesController;
use mindstellar\api\controller\CommentsController;
use mindstellar\api\controller\ConversationsController;
use mindstellar\api\controller\ListingsController;
use mindstellar\api\controller\ListingWritesController;
use mindstellar\api\controller\LocationsController;
use mindstellar\api\controller\OrdersController;
use mindstellar\api\controller\PagesController;
use mindstellar\api\controller\PhotosController;
use mindstellar\api\controller\RegistrationController;
use mindstellar\api\controller\SearchController;
use mindstellar\api\controller\SeoController;
use mindstellar\api\controller\SiteController;
use mindstellar\api\controller\UsersController;
use mindstellar\api\controller\WishlistController;
use mindstellar\api\read\ListingSort;
use mindstellar\api\read\ListSpec;
use mindstellar\api\RouteSpec;
use mindstellar\api\schema\OpenApi;
use mindstellar\api\schema\Schema;
use mindstellar\listing\ListingStatus;
use mindstellar\listing\RelatedListings;
use mindstellar\search\SearchSuggestions;

/**
 * The v1 routes anyone, a user or a user's app calls: reads, sign-in, the account and
 * listing writes. AdminRoutes holds the admin ones.
 */
final class PublicRoutes
{
    private const LOCALE = ['type' => 'string', 'pattern' => '^[A-Za-z]{2,3}_[A-Za-z]{2}$'];

    private const INCLUDE = ['type' => 'string', 'maxLength' => 100, 'description' => 'Comma list: custom_fields, translations, rating (the approved ratings: average, count and, on one listing, the count of each star).'];

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
            'GET search/suggest' => RouteSpec::read(
                handler: [SearchController::class, 'suggest'],
                tag: 'Search',
                summary: 'Listings for a search box while someone types, best first; a search plugin may add searches',
                response: 'SearchSuggestionsDocument',
                query: [
                    'q'      => ['type' => 'string', 'maxLength' => 100, 'description' => 'What was typed. Titles are matched by word start; input with no word of three letters or more suggests nothing.'],
                    'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => SearchSuggestions::MAX_LIMIT, 'default' => SearchController::DEFAULT_LIMIT],
                    'locale' => self::LOCALE,
                ]
            ),
            'GET search/categories' => RouteSpec::read(
                handler: [SearchController::class, 'categories'],
                tag: 'Search',
                summary: 'Up to three categories for a new listing\'s title, best first',
                response: 'CategorySuggestionsDocument',
                query: ['title' => ['type' => 'string', 'maxLength' => 200], 'locale' => self::LOCALE]
            ),
            'GET listings/{id}' => RouteSpec::read(
                handler: [ListingsController::class, 'show'],
                tag: 'Listings',
                summary: 'One listing',
                response: 'ListingDocument',
                query: $view + ['include' => self::INCLUDE],
                errors: [404]
            ),
            'GET listings/{id}/related' => RouteSpec::read(
                handler: [ListingsController::class, 'related'],
                tag: 'Listings',
                summary: 'Live listings like this one, best first: same category and shared title words, or what a search plugin picks',
                response: 'ListingPage',
                query: $view + ['include' => self::INCLUDE] + self::relatedFilters(),
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
                summary: 'A listing\'s approved comments, oldest first or newest first',
                response: 'CommentPage',
                query: $paging + ['count' => self::COUNT, 'sort' => ['type' => 'string', 'enum' => ['oldest', 'newest'], 'description' => 'Default oldest.']],
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
            ) + ['prepare' => [ListingWritesController::class, 'prepareCreate']],
            'PATCH listings/{id}' => RouteSpec::write(
                handler: [ListingWritesController::class, 'update'],
                tag: 'Listings',
                summary: 'Edit your listing; members not sent keep their values',
                auth: RouteSpec::AUTH_USER,
                scope: 'listings:write',
                body: 'ListingPatch',
                response: 'SavedListing',
                errors: [403, 404]
            ) + ['prepare' => [ListingWritesController::class, 'prepareUpdate']],
            'DELETE listings/{id}' => RouteSpec::write(
                handler: [ListingWritesController::class, 'delete'],
                tag: 'Listings',
                summary: 'Delete your listing',
                auth: RouteSpec::AUTH_USER,
                scope: 'listings:delete',
                status: 204,
                errors: [403, 404]
            ),
            'POST listings/{id}/publish' => RouteSpec::write(
                handler: [ListingWritesController::class, 'publish'],
                tag: 'Listings',
                summary: 'Publish a listing that waits for payment: with your credits, or by starting an order for the credits it is short of. Needs orders:write too',
                auth: RouteSpec::AUTH_USER,
                scope: 'listings:write',
                body: 'ListingPublishInput',
                response: 'ListingPublished',
                errors: [403, 404, 429]
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
                errors: [403, 404, 409, 429]
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
                query: $view + ['tree' => ['type' => 'boolean'], 'roots' => ['type' => 'boolean', 'description' => 'Only the top-level categories, flat; ignored with tree.'], 'include' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Comma list: cover, the first photo of the newest live listing in the category or below it; null when there is none.']]
            ),
            'GET categories/{category}' => RouteSpec::read(
                handler: [CategoriesController::class, 'show'],
                tag: 'Categories',
                summary: 'One category by id or slug, with its custom fields',
                response: 'CategoryDocument',
                query: $view,
                errors: [404]
            ),
            'GET pages' => RouteSpec::read(
                handler: [PagesController::class, 'index'],
                tag: 'Pages',
                summary: 'The pages shown as links, in order, in the asked language',
                response: 'PageSummaryList',
                query: ['locale' => self::LOCALE],
                errors: [422]
            ),
            'GET pages/{page}' => RouteSpec::read(
                handler: [PagesController::class, 'show'],
                tag: 'Pages',
                summary: 'One page by id or internal name, linked or not, with its text and meta',
                response: 'PageDocument',
                query: ['locale' => self::LOCALE],
                errors: [404, 422]
            ),
            'GET menus' => RouteSpec::read(
                handler: [PagesController::class, 'menus'],
                tag: 'Pages',
                summary: 'The site\'s menus: main (top categories) and footer (linked pages); a plugin may add more',
                response: 'MenuList',
                query: ['locale' => self::LOCALE],
                errors: [422]
            ),
            'GET seo' => RouteSpec::read(
                handler: [SeoController::class, 'show'],
                tag: 'Pages',
                summary: 'The head data of a public page: canonical URL, title, description, robots, breadcrumbs, hreflang and Open Graph',
                response: 'SeoDocument',
                query: [
                    'for'    => ['type' => 'string', 'enum' => ['home', 'listing', 'category', 'page', 'search'], 'description' => 'The kind of page.'],
                    'id'     => ['type' => 'string', 'maxLength' => 200, 'description' => 'A listing id, a category id or slug, or a page id or internal name. Not used for home and search.'],
                    'q'      => ['type' => 'string', 'maxLength' => 200, 'description' => 'Search only: the search words.'],
                    'locale' => self::LOCALE,
                ],
                errors: [404, 422]
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
                summary: 'Sign out this sign-in; POST /account/sign-out-everywhere ends them all',
                auth: RouteSpec::AUTH_USER,
                scope: null,
                status: 204
            ),
            'GET auth/session' => RouteSpec::read(
                handler: [AuthController::class, 'pageToken'],
                tag: 'Auth',
                summary: 'A fresh page token, for theme JavaScript on a page open longer than its token lives',
                response: 'SessionTokenDocument',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:read',
                errors: [403]
            ) + ['session_only' => true],
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
                summary: 'Your live sign-ins; personal keys are at /account/keys',
                response: 'SessionList',
                auth: RouteSpec::AUTH_USER,
                scope: 'account:read'
            ),
            'DELETE account/sessions/{session}' => RouteSpec::write(
                handler: [AccountController::class, 'endSession'],
                tag: 'Account',
                summary: 'End one sign-in',
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
                errors: [403],
                replayable: false
            ) + ['needs_user_keys' => true],
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
                scope: 'alerts:read'
            ),
            'POST account/alerts' => RouteSpec::write(
                handler: [AlertsController::class, 'create'],
                tag: 'Account',
                summary: 'Save a search, with the filters GET /listings takes, or a back-in-stock or price-drop alert on a product; an existing one is answered as it is',
                auth: RouteSpec::AUTH_USER,
                scope: 'alerts:write',
                body: 'AlertInput',
                response: 'AlertDocument',
                status: 201,
                errors: [403, 404],
                alsoStatuses: [200]
            ),
            'GET account/alerts/{id}' => RouteSpec::read(
                handler: [AlertsController::class, 'show'],
                tag: 'Account',
                summary: 'One of your saved searches',
                response: 'AlertDocument',
                errors: [404],
                auth: RouteSpec::AUTH_USER,
                scope: 'alerts:read'
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
            // C4: the wishlist.
            'GET account/wishlist' => RouteSpec::read(
                handler: [WishlistController::class, 'show'],
                tag: 'Account',
                summary: 'Your wishlist: listing ids, newest first',
                response: 'WishlistDocument',
                auth: RouteSpec::AUTH_USER,
                scope: 'wishlist:read'
            ),
            'PUT account/wishlist' => RouteSpec::write(
                handler: [WishlistController::class, 'replace'],
                tag: 'Account',
                summary: 'Replace your wishlist; listings that are not live are dropped',
                auth: RouteSpec::AUTH_USER,
                scope: 'wishlist:write',
                body: 'WishlistInput',
                status: 204
            ),
            'POST account/wishlist/{listing}' => RouteSpec::write(
                handler: [WishlistController::class, 'add'],
                tag: 'Account',
                summary: 'Put a live listing at the top of your wishlist',
                auth: RouteSpec::AUTH_USER,
                scope: 'wishlist:write',
                status: 204,
                errors: [404]
            ),
            'DELETE account/wishlist/{listing}' => RouteSpec::write(
                handler: [WishlistController::class, 'remove'],
                tag: 'Account',
                summary: 'Take a listing off your wishlist',
                auth: RouteSpec::AUTH_USER,
                scope: 'wishlist:write',
                status: 204
            ),
            'GET cart/quote' => RouteSpec::read(
                handler: [OrdersController::class, 'quote'],
                tag: 'Orders',
                summary: 'Price a cart: lines, shipping, fees and totals, worked out from the database',
                response: 'QuoteDocument',
                query: [
                    'lines'    => ['type' => 'string', 'maxLength' => 1000, 'pattern' => '^(?:[0-9]{1,12}:[0-9]{1,6}(?:,[0-9]{1,12}:[0-9]{1,6})*)?$', 'description' => 'variant:qty pairs, comma separated, at most 50. A quantity over 20 is capped with a problem.'],
                    'shipping' => ['type' => 'string', 'maxLength' => 64, 'description' => 'A shipping method id from shipping_methods; the first one when left out.'],
                    'gateway'  => ['type' => 'string', 'maxLength' => 64, 'description' => 'A payment method id; its fee is added to the totals.'],
                    'country'  => ['type' => 'string', 'maxLength' => 200, 'description' => 'The delivery country\'s two-letter code, for delivery and tax plugins; anything else is ignored.'],
                    'state'    => ['type' => 'string', 'maxLength' => 200, 'description' => 'The delivery state, up to 100 characters; a longer one is ignored.'],
                    'postcode' => ['type' => 'string', 'maxLength' => 200, 'description' => 'The delivery postcode, up to 20 characters; a longer one is ignored.'],
                    'coupon'   => ['type' => 'string', 'maxLength' => 200, 'description' => 'A coupon code: trimmed and upper-cased, 1-40 of A-Z, 0-9 and -. Anything else is ignored, not refused.'],
                    'locale'   => self::LOCALE,
                ]
            ),
            'GET account/orders' => RouteSpec::read(
                handler: [OrdersController::class, 'index'],
                tag: 'Orders',
                summary: 'Your orders, newest first',
                response: 'OrderPage',
                query: $paging,
                auth: RouteSpec::AUTH_USER,
                scope: 'orders:read'
            ),
            'POST account/orders' => RouteSpec::write(
                handler: [OrdersController::class, 'create'],
                tag: 'Orders',
                summary: 'Place an order for a cart; the server prices it again and answers how to pay',
                auth: RouteSpec::AUTH_USER,
                scope: 'orders:write',
                body: 'OrderInput',
                response: 'OrderPlaced',
                status: 201,
                errors: [429]
            ),
            'GET account/orders/{id}' => RouteSpec::read(
                handler: [OrdersController::class, 'show'],
                tag: 'Orders',
                summary: 'One of your orders',
                response: 'OrderDocument',
                errors: [404],
                auth: RouteSpec::AUTH_USER,
                scope: 'orders:read'
            ),
            'POST account/orders/{id}/pay' => RouteSpec::write(
                handler: [OrdersController::class, 'pay'],
                tag: 'Orders',
                summary: 'Start paying one of your pending card orders again',
                auth: RouteSpec::AUTH_USER,
                scope: 'orders:write',
                response: 'OrderPlaced',
                errors: [404, 409, 429]
            ),
            'POST account/orders/{id}/cancel' => RouteSpec::write(
                handler: [OrdersController::class, 'cancel'],
                tag: 'Orders',
                summary: 'Cancel one of your pending bank transfer or cash on delivery orders',
                auth: RouteSpec::AUTH_USER,
                scope: 'orders:write',
                response: 'OrderDocument',
                errors: [404, 409]
            ),
            'GET account/cart' => RouteSpec::read(
                handler: [CartController::class, 'show'],
                tag: 'Orders',
                summary: 'Your saved cart: variant ids and quantities, never prices',
                response: 'CartDocument',
                auth: RouteSpec::AUTH_USER,
                scope: 'cart:read'
            ),
            'PUT account/cart' => RouteSpec::write(
                handler: [CartController::class, 'replace'],
                tag: 'Orders',
                summary: 'Replace your saved cart; variants that cannot be bought are dropped',
                auth: RouteSpec::AUTH_USER,
                scope: 'cart:write',
                body: 'CartInput',
                status: 204
            ),
            'GET account/conversations' => RouteSpec::read(
                handler: [ConversationsController::class, 'index'],
                tag: 'Conversations',
                summary: 'Your conversations, newest activity first; meta carries your unread total',
                response: 'ConversationPage',
                query: $paging,
                errors: [403],
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:read'
            ),
            'POST account/conversations' => RouteSpec::write(
                handler: [ConversationsController::class, 'create'],
                tag: 'Conversations',
                summary: 'Write to a listing\'s seller; a second message about the same listing goes to the conversation already there',
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:write',
                body: 'ConversationInput',
                response: 'MessageDocument',
                status: 201,
                errors: [403, 404, 409, 429]
            ),
            'GET account/conversations/{id}' => RouteSpec::read(
                handler: [ConversationsController::class, 'show'],
                tag: 'Conversations',
                summary: 'One of your conversations',
                response: 'ConversationDocument',
                errors: [403, 404],
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:read'
            ),
            'GET account/conversations/{id}/messages' => RouteSpec::read(
                handler: [ConversationsController::class, 'messages'],
                tag: 'Conversations',
                summary: 'A conversation\'s messages, newest first; with after, the newer ones oldest first. Reading marks nothing read',
                response: 'MessagePage',
                query: ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ListSpec::MAX_LIMIT, 'default' => ConversationsController::MESSAGES_LIMIT], 'cursor' => self::CURSOR, 'after' => ['type' => 'integer', 'minimum' => 1, 'description' => 'A message id: answer the messages after it, oldest first, with no next page. cursor is then ignored.']],
                errors: [403, 404],
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:read'
            ),
            'POST account/conversations/{id}/messages' => RouteSpec::write(
                handler: [ConversationsController::class, 'send'],
                tag: 'Conversations',
                summary: 'Reply in one of your conversations',
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:write',
                body: 'MessageInput',
                response: 'MessageDocument',
                status: 201,
                errors: [403, 404, 409, 429]
            ),
            'POST account/conversations/{id}/read' => RouteSpec::write(
                handler: [ConversationsController::class, 'read'],
                tag: 'Conversations',
                summary: 'Mark a conversation read',
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:write',
                response: 'ConversationDocument',
                errors: [403, 404]
            ),
            'POST account/conversations/{id}/block' => RouteSpec::write(
                handler: [ConversationsController::class, 'block'],
                tag: 'Conversations',
                summary: 'Block a conversation: nobody can write in it until you unblock it',
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:write',
                response: 'ConversationDocument',
                errors: [403, 404]
            ),
            'DELETE account/conversations/{id}/block' => RouteSpec::write(
                handler: [ConversationsController::class, 'unblock'],
                tag: 'Conversations',
                summary: 'Lift your own block; a block the other person set stays',
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:write',
                response: 'ConversationDocument',
                errors: [403, 404]
            ),
            'POST account/conversations/{id}/report' => RouteSpec::write(
                handler: [ConversationsController::class, 'report'],
                tag: 'Conversations',
                summary: 'Report a conversation to the admins; it is also blocked for you',
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:write',
                body: 'ReportInput',
                response: 'ConversationDocument',
                errors: [403, 404, 409]
            ),
            'PUT account/push-subscriptions' => RouteSpec::write(
                handler: [AccountPushController::class, 'put'],
                tag: 'Conversations',
                summary: 'Register a push endpoint to wake this device for new messages; the push carries no text',
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:write',
                body: 'PushSubscriptionInput',
                response: 'PushSubscriptionDocument',
                errors: [404]
            ),
            'DELETE account/push-subscriptions/{browser}' => RouteSpec::write(
                handler: [AccountPushController::class, 'delete'],
                tag: 'Conversations',
                summary: 'Stop waking a device; the browser id is the one PUT answered',
                auth: RouteSpec::AUTH_USER,
                scope: 'conversations:write',
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
     * The query of GET /listings/{id}/related.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function relatedFilters(): array
    {
        $query = ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => RelatedListings::MAX_LIMIT, 'default' => 6]];
        foreach (RelatedListings::FILTERS as $name => $default) {
            $query[$name] = ['type' => 'boolean', 'default' => $default];
        }

        return $query;
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
            'in_stock'    => ['type' => 'boolean', 'description' => 'true: only listings with a variant in stock (stock not tracked, or above 0).'],
            'on_sale'     => ['type' => 'boolean', 'description' => 'true: only listings with a variant whose compare_at is above its price. With in_stock, one variant must be both.'],
            'lat'         => ['type' => 'number', 'minimum' => -90, 'maximum' => 90, 'description' => 'Measure distances from this point, with lng. Needs distance search switched on.'],
            'lng'         => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
            'radius'      => ['type' => 'number', 'minimum' => 1, 'maximum' => 1000, 'description' => 'Only listings within this many km of lat/lng.'],
            'custom_field' => ['type' => 'object', 'description' => 'custom_field[<id>]=<value>, or custom_field[<id>][]=A&custom_field[<id>][]=B for a dropdown or radio field: any of up to 20 values. Needs a category filter; each id must be a searchable field of that category, else 422.', 'additionalProperties' => ['type' => ['string', 'array'], 'items' => ['type' => 'string']]],
            'option'      => ['type' => 'object', 'description' => 'option[<name>][]=<value>: only listings with one variant that has, for every name given, one of its values (OR within a name, AND across names). At most 3 names and 10 values each, names up to 30 and values up to 40 characters; anything over is ignored. With in_stock or on_sale, the same variant must meet them too.', 'additionalProperties' => ['type' => ['string', 'array'], 'items' => ['type' => 'string']]],
            'ids'         => $list + ['description' => 'Comma list of listing ids, the first 50 used. Hidden and unavailable listings are left out; any sort.'],
            'sort'        => ['type' => 'string', 'enum' => ListingSort::SORTS],
            'order'       => ['type' => 'string', 'enum' => ['asc', 'desc']],
            'limit'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'cursor'      => self::CURSOR,
            'page'        => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number, for numbered pages. Any sort; not with cursor. Past the deepest page: 422 page_too_deep. With count=true, meta.page and meta.pages say where you are.'],
            'count'       => self::COUNT,
            'fields'      => ['type' => 'string', 'maxLength' => 500],
            'include'     => self::INCLUDE,
        ];
    }
}
