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

use mindstellar\api\controller\admin\AdminCategoriesController;
use mindstellar\api\controller\admin\AdminCommentsController;
use mindstellar\api\controller\admin\AdminCurrenciesController;
use mindstellar\api\controller\admin\AdminFieldsController;
use mindstellar\api\controller\admin\AdminKeysController;
use mindstellar\api\controller\admin\AdminListingsController;
use mindstellar\api\controller\admin\AdminLocationsController;
use mindstellar\api\controller\admin\AdminSettingsController;
use mindstellar\api\controller\admin\AdminUsersController;
use mindstellar\api\controller\admin\AdminWebhooksController;
use mindstellar\api\RouteSpec;
use mindstellar\api\schema\Schema;
use mindstellar\comment\CommentStatus;
use mindstellar\listing\ListingStatus;
use mindstellar\moderation\ListingModeration;

/**
 * The admin routes of the v1 table. Every one needs an admin key and names its scope;
 * moderators' keys hold only `admin:listings` and `admin:comments`.
 */
final class AdminRoutes
{
    private const PAGING = [
        'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        'cursor' => ['type' => 'string', 'maxLength' => 1024],
    ];

    /** A list filter, as public search takes them. */
    private const IDS = ['type' => ['string', 'array'], 'items' => ['type' => 'string']];

    private const COUNT = ['count' => ['type' => 'boolean', 'description' => 'true: also count every match for meta.total; skipped otherwise, as it costs a query.']];

    private const VIEW = [
        'locale' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2,3}_[A-Za-z]{2}$'],
        'fields' => ['type' => 'string', 'maxLength' => 500],
    ];

    private const INCLUDE = ['type' => 'string', 'maxLength' => 100];

    private function __construct()
    {
    }

    /**
     * @return array<string,array<string,mixed>> 'METHOD path' => spec
     */
    public static function all(): array
    {
        return self::listings() + self::comments() + self::users() + self::taxonomy() + self::locations() + self::settings() + self::webhooks();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function listings(): array
    {
        [$read, $write] = self::group('Admin listings', 'admin:listings');
        $c              = AdminListingsController::class;

        return [
            'GET admin/listings' => $read([$c, 'index'], 'Every listing, whatever its status, newest first', 'ListingPage', query: [
                'status'   => Schema::listOf(ListingStatus::ALL),
                'user'     => self::IDS + ['description' => 'User ids: one, a comma list, or repeated.'],
                'category' => self::IDS + ['description' => 'Category ids or slugs: one, a comma list, or repeated. Subcategories are included.'],
                'q'        => ['type' => 'string', 'maxLength' => 100, 'description' => 'Titles containing this.'],
                'include'  => self::INCLUDE,
            ] + self::PAGING + self::COUNT + self::VIEW, errors: [400, 422]),
            'GET admin/listings/{id}'       => $read([$c, 'show'], 'One listing in the admin view', 'ListingDocument', query: self::VIEW + ['include' => self::INCLUDE], errors: [404]),
            'PATCH admin/listings/{id}'     => $write([$c, 'update'], 'Edit any listing, its owner, expiry and status included; members not sent keep their values', body: 'AdminListingPatch', response: 'ListingDocument', errors: [404, 409]),
            'DELETE admin/listings/{id}'    => $write([$c, 'delete'], 'Delete a listing', status: 204, errors: [404]),
            'POST admin/listings/{id}/bump' => $write([$c, 'bump'], ListingModeration::ACTIONS['bump'], response: 'ListingDocument', errors: [404]),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function comments(): array
    {
        [$read, $write] = self::group('Admin comments', 'admin:comments');
        $c              = AdminCommentsController::class;

        return [
            'GET admin/comments' => $read([$c, 'index'], 'Every comment, whatever its status, newest first', 'AdminCommentPage', query: [
                'status'  => Schema::listOf(CommentStatus::ALL),
                'listing' => self::IDS + ['description' => 'Listing ids: one, a comma list, or repeated.'],
                'user'    => self::IDS + ['description' => 'Author user ids: one, a comma list, or repeated.'],
            ] + self::PAGING + self::COUNT, errors: [400, 422]),
            'GET admin/comments/{id}'    => $read([$c, 'show'], 'One comment', 'AdminCommentDocument', errors: [404]),
            'PATCH admin/comments/{id}'  => $write([$c, 'update'], 'Edit a comment\'s text or author, or approve or block it', body: 'AdminCommentPatch', response: 'AdminCommentDocument', errors: [404]),
            'DELETE admin/comments/{id}' => $write([$c, 'delete'], 'Delete a comment', status: 204, errors: [404]),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function users(): array
    {
        [$read, $write] = self::group('Admin users', 'admin:users');
        $c              = AdminUsersController::class;

        return [
            'GET admin/users' => $read([$c, 'index'], 'Every user, newest first', 'UserPage', query: [
                'q'         => ['type' => 'string', 'maxLength' => 100, 'description' => 'E-mail, username or name starting with this.'],
                'confirmed' => ['type' => 'boolean'],
                'blocked'   => ['type' => 'boolean'],
            ] + self::PAGING + self::COUNT + self::VIEW, errors: [400, 422]),
            'GET admin/users/{id}'                       => $read([$c, 'show'], 'One user, every member', 'UserDocument', query: self::VIEW, errors: [404]),
            'PATCH admin/users/{id}'                     => $write([$c, 'update'], 'Edit a user\'s profile, e-mail, username or password, or confirm or block them', body: 'AdminUserPatch', response: 'UserDocument', errors: [404]),
            'DELETE admin/users/{id}'                    => $write([$c, 'delete'], 'Delete a user with their listings, comments and saved searches', status: 204, errors: [404]),
            'POST admin/users/{id}/sign-out-everywhere'  => $write([$c, 'signOutEverywhere'], 'Sign a user out of every device: web sign-ins, API tokens and personal keys', status: 204, errors: [404]),
            'GET admin/users/{id}/sessions'              => $read([$c, 'sessions'], 'A user\'s live sign-ins; their keys are at /admin/keys', 'SessionList', errors: [404]),
            'DELETE admin/users/{id}/sessions/{session}' => $write([$c, 'endSession'], 'End one of a user\'s sign-ins', status: 204, errors: [404]),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function taxonomy(): array
    {
        [$read, $write] = self::group('Admin taxonomy', 'admin:taxonomy');
        $c              = AdminCategoriesController::class;
        $m              = AdminCurrenciesController::class;
        $f              = AdminFieldsController::class;
        $delete         = $write([$c, 'delete'], 'Delete a category, its subcategories and their listings; a large one is emptied in the background (202)', status: 204, errors: [404]);
        $delete['responses'][202] = ['type' => 'null'];

        return [
            'GET admin/categories'            => $read([$c, 'index'], 'Every category, enabled or not, with each language\'s texts', 'AdminCategoryList'),
            'GET admin/categories/{id}'       => $read([$c, 'show'], 'One category with each language\'s texts', 'AdminCategoryDocument', errors: [404]),
            'POST admin/categories'           => $write([$c, 'create'], 'Add a category', body: 'AdminCategoryInput', response: 'AdminCategoryDocument', status: 201),
            'PATCH admin/categories/{id}'     => $write([$c, 'update'], 'Edit a category; a new slug keeps the old one redirecting', body: 'AdminCategoryPatch', response: 'AdminCategoryDocument', errors: [404, 409]),
            'DELETE admin/categories/{id}'    => $delete,
            'GET admin/currencies/{code}'     => $read([$m, 'show'], 'One currency', 'CurrencyDocument', errors: [404]),
            'POST admin/currencies'           => $write([$m, 'create'], 'Add a currency', body: 'CurrencyInput', response: 'CurrencyDocument', status: 201, errors: [409]),
            'PATCH admin/currencies/{code}'   => $write([$m, 'update'], 'Rename a currency or change its symbol', body: 'CurrencyPatch', response: 'CurrencyDocument', errors: [404]),
            'DELETE admin/currencies/{code}'  => $write([$m, 'delete'], 'Delete a currency no listing uses and the site does not default to', status: 204, errors: [404, 409]),
            'GET admin/custom-fields/{id}'    => $read([$f, 'show'], 'One custom field', 'AdminCustomFieldDocument', errors: [404]),
            'POST admin/custom-fields'        => $write([$f, 'create'], 'Add a custom field', body: 'AdminCustomFieldInput', response: 'AdminCustomFieldDocument', status: 201),
            'PATCH admin/custom-fields/{id}'  => $write([$f, 'update'], 'Edit a custom field', body: 'AdminCustomFieldPatch', response: 'AdminCustomFieldDocument', errors: [404]),
            'DELETE admin/custom-fields/{id}' => $write([$f, 'delete'], 'Delete a custom field and its values', status: 204, errors: [404]),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function locations(): array
    {
        [$read, $write] = self::group('Admin taxonomy', 'admin:taxonomy');
        $c              = AdminLocationsController::class;
        $gone           = ' and the listings in it';

        return [
            'GET admin/regions/{id}'    => $read([$c, 'showRegion'], 'One region', 'RegionDocument', errors: [404]),
            'POST admin/regions'        => $write([$c, 'createRegion'], 'Add a region to a country', body: 'RegionInput', response: 'RegionDocument', status: 201, errors: [404]),
            'PATCH admin/regions/{id}'  => $write([$c, 'updateRegion'], 'Rename a region', body: 'RegionPatch', response: 'RegionDocument', errors: [404]),
            'DELETE admin/regions/{id}' => $write([$c, 'deleteRegion'], 'Delete a region, its cities' . $gone, status: 204, errors: [404]),
            'GET admin/cities/{id}'     => $read([$c, 'showCity'], 'One city', 'CityDocument', errors: [404]),
            'POST admin/cities'         => $write([$c, 'createCity'], 'Add a city to a region', body: 'CityInput', response: 'CityDocument', status: 201, errors: [404]),
            'PATCH admin/cities/{id}'   => $write([$c, 'updateCity'], 'Rename a city', body: 'CityPatch', response: 'CityDocument', errors: [404]),
            'DELETE admin/cities/{id}'  => $write([$c, 'deleteCity'], 'Delete a city, its areas' . $gone, status: 204, errors: [404]),
            'GET admin/areas/{id}'      => $read([$c, 'showArea'], 'One city area', 'CityAreaDocument', errors: [404]),
            'POST admin/areas'          => $write([$c, 'createArea'], 'Add an area to a city', body: 'CityAreaInput', response: 'CityAreaDocument', status: 201, errors: [404]),
            'PATCH admin/areas/{id}'    => $write([$c, 'updateArea'], 'Rename a city area', body: 'CityAreaPatch', response: 'CityAreaDocument', errors: [404]),
            'DELETE admin/areas/{id}'   => $write([$c, 'deleteArea'], 'Delete a city area' . $gone, status: 204, errors: [404]),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function settings(): array
    {
        [$read, $write]       = self::group('Admin settings', 'admin:settings');
        [$keyRead, $keyWrite] = self::group('Admin settings', 'admin:keys');
        $s                    = AdminSettingsController::class;
        $k                    = AdminKeysController::class;

        return [
            'GET admin/settings'   => $read([$s, 'show'], 'The settings the API can change', 'SettingsDocument'),
            'PATCH admin/settings' => $write([$s, 'update'], 'Change some settings, all or none, checked as on the settings screens', body: 'SettingsPatch', response: 'SettingsDocument'),
            'GET admin/jobs'       => $read([$s, 'jobs'], 'The background job queue: jobs per status and those that stopped retrying', 'JobsDocument', query: [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            ]),
            'GET admin/keys'              => $keyRead([$k, 'index'], 'Every API key, newest first; never a secret', 'ApiKeyList'),
            'GET admin/keys/{id}'         => $keyRead([$k, 'show'], 'One API key; never its secret', 'ApiKeyDocument', errors: [404]),
            'POST admin/keys'             => $keyWrite([$k, 'create'], 'Make an admin or public key; its token is shown once', body: 'ApiKeyInput', response: 'ApiKeyDocument', status: 201, errors: [403], replayable: false),
            'DELETE admin/keys/{id}'      => $keyWrite([$k, 'revoke'], 'Revoke a key; never another admin\'s own key', status: 204, errors: [403, 404, 409]),
            'POST admin/keys/{id}/rotate' => $keyWrite([$k, 'rotate'], 'Make a new key in place of one of yours or a public key; the old one works until revoked', response: 'ApiKeyDocument', status: 201, errors: [403, 404, 409], replayable: false),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function webhooks(): array
    {
        [$read, $write] = self::group('Admin webhooks', 'admin:webhooks');
        $c              = AdminWebhooksController::class;

        return [
            'GET admin/webhooks'                          => $read([$c, 'index'], 'Every webhook endpoint, newest first; never a secret', 'WebhookList'),
            'POST admin/webhooks'                         => $write([$c, 'create'], 'Add an endpoint; its signing secret is shown once', body: 'WebhookInput', response: 'WebhookDocument', status: 201, replayable: false),
            'GET admin/webhooks/{webhook}'                => $read([$c, 'show'], 'One endpoint; never its secret', 'WebhookDocument', errors: [404]),
            'PATCH admin/webhooks/{webhook}'              => $write([$c, 'update'], 'Change an endpoint; switching it on clears a pause', body: 'WebhookPatch', response: 'WebhookDocument', errors: [404]),
            'DELETE admin/webhooks/{webhook}'             => $write([$c, 'delete'], 'Delete an endpoint and its waiting deliveries', status: 204, errors: [404]),
            'POST admin/webhooks/{webhook}/rotate-secret' => $write([$c, 'rotate'], 'A new signing secret, shown once; the old one also signs for 24 hours', response: 'WebhookDocument', errors: [404], replayable: false),
            'POST admin/webhooks/{webhook}/test'          => $write([$c, 'test'], 'Queue a ping to the endpoint, sent once even while it is off', response: 'WebhookTestDocument', status: 202, errors: [404]),
            'GET admin/webhooks/{webhook}/deliveries'     => $read([$c, 'deliveries'], 'The endpoint\'s state and its deliveries still on the job queue', 'WebhookDeliveriesDocument', query: [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ], errors: [404]),
            'GET admin/webhook-events' => $read([$c, 'events'], 'The events an endpoint can subscribe to, plugin events included', 'WebhookEventList'),
        ];
    }

    /**
     * A read and a write builder for one group of admin routes, sharing its tag and scope.
     *
     * @return array{\Closure, \Closure}
     */
    private static function group(string $tag, string $scope): array
    {
        return [
            /**
             * @param array{class-string,string}         $handler
             * @param array<string,array<string,mixed>> $query
             * @param int[]                             $errors
             */
            static fn (array $handler, string $summary, string $response, array $query = [], array $errors = []): array => RouteSpec::read(
                handler: $handler,
                tag: $tag,
                summary: $summary,
                response: $response,
                query: $query,
                errors: $errors,
                auth: RouteSpec::AUTH_ADMIN,
                scope: $scope
            ),
            /**
             * @param array{class-string,string} $handler
             * @param int[]                      $errors
             */
            static fn (
                array $handler,
                string $summary,
                ?string $body = null,
                ?string $response = null,
                int $status = 200,
                array $errors = [],
                bool $replayable = true
            ): array => RouteSpec::write(
                handler: $handler,
                tag: $tag,
                summary: $summary,
                auth: RouteSpec::AUTH_ADMIN,
                scope: $scope,
                body: $body,
                response: $response,
                status: $status,
                errors: $errors,
                replayable: $replayable
            ),
        ];
    }
}
