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
        $tag   = 'Admin listings';
        $scope = 'admin:listings';
        $c     = AdminListingsController::class;

        return [
            'GET admin/listings' => self::read(
                handler: [$c, 'index'],
                tag: $tag,
                scope: $scope,
                summary: 'Every listing, whatever its status, newest first',
                response: 'ListingPage',
                query: [
                    'status'   => Schema::listOf(ListingStatus::ALL),
                    'user'     => self::IDS + ['description' => 'User ids: one, a comma list, or repeated.'],
                    'category' => self::IDS + ['description' => 'Category ids or slugs: one, a comma list, or repeated. Subcategories are included.'],
                    'q'        => ['type' => 'string', 'maxLength' => 100, 'description' => 'Titles containing this.'],
                    'include'  => self::INCLUDE,
                ] + self::PAGING + self::COUNT + self::VIEW,
                errors: [400, 422]
            ),
            'GET admin/listings/{id}' => self::read(
                handler: [$c, 'show'],
                tag: $tag,
                scope: $scope,
                summary: 'One listing in the admin view',
                response: 'ListingDocument',
                query: self::VIEW + ['include' => self::INCLUDE],
                errors: [404]
            ),
            'PATCH admin/listings/{id}' => self::write(
                handler: [$c, 'update'],
                tag: $tag,
                scope: $scope,
                summary: 'Edit any listing, its owner, expiry and status included; members not sent keep their values',
                body: 'AdminListingPatch',
                response: 'ListingDocument',
                errors: [404, 409]
            ),
            'DELETE admin/listings/{id}' => self::write(
                handler: [$c, 'delete'],
                tag: $tag,
                scope: $scope,
                summary: 'Delete a listing',
                status: 204,
                errors: [404]
            ),
            'POST admin/listings/{id}/bump' => self::write(
                handler: [$c, 'bump'],
                tag: $tag,
                scope: $scope,
                summary: ListingModeration::ACTIONS['bump'],
                response: 'ListingDocument',
                errors: [404]
            ),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function comments(): array
    {
        $tag   = 'Admin comments';
        $scope = 'admin:comments';
        $c     = AdminCommentsController::class;

        return [
            'GET admin/comments' => self::read(
                handler: [$c, 'index'],
                tag: $tag,
                scope: $scope,
                summary: 'Every comment, whatever its status, newest first',
                response: 'AdminCommentPage',
                query: [
                    'status'  => Schema::listOf(CommentStatus::ALL),
                    'listing' => self::IDS + ['description' => 'Listing ids: one, a comma list, or repeated.'],
                    'user'    => self::IDS + ['description' => 'Author user ids: one, a comma list, or repeated.'],
                ] + self::PAGING + self::COUNT,
                errors: [400, 422]
            ),
            'GET admin/comments/{id}' => self::read(
                handler: [$c, 'show'],
                tag: $tag,
                scope: $scope,
                summary: 'One comment',
                response: 'AdminCommentDocument',
                errors: [404]
            ),
            'PATCH admin/comments/{id}' => self::write(
                handler: [$c, 'update'],
                tag: $tag,
                scope: $scope,
                summary: 'Edit a comment\'s text or author, or approve or block it',
                body: 'AdminCommentPatch',
                response: 'AdminCommentDocument',
                errors: [404]
            ),
            'DELETE admin/comments/{id}' => self::write(
                handler: [$c, 'delete'],
                tag: $tag,
                scope: $scope,
                summary: 'Delete a comment',
                status: 204,
                errors: [404]
            ),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function users(): array
    {
        $tag   = 'Admin users';
        $scope = 'admin:users';
        $c     = AdminUsersController::class;

        return [
            'GET admin/users' => self::read(
                handler: [$c, 'index'],
                tag: $tag,
                scope: $scope,
                summary: 'Every user, newest first',
                response: 'UserPage',
                query: [
                    'q'         => ['type' => 'string', 'maxLength' => 100, 'description' => 'E-mail, username or name starting with this.'],
                    'confirmed' => ['type' => 'boolean'],
                    'blocked'   => ['type' => 'boolean'],
                ] + self::PAGING + self::COUNT + self::VIEW,
                errors: [400, 422]
            ),
            'GET admin/users/{id}' => self::read(
                handler: [$c, 'show'],
                tag: $tag,
                scope: $scope,
                summary: 'One user, every member',
                response: 'UserDocument',
                query: self::VIEW,
                errors: [404]
            ),
            'PATCH admin/users/{id}' => self::write(
                handler: [$c, 'update'],
                tag: $tag,
                scope: $scope,
                summary: 'Edit a user\'s profile, e-mail, username or password, or confirm or block them',
                body: 'AdminUserPatch',
                response: 'UserDocument',
                errors: [404]
            ),
            'DELETE admin/users/{id}' => self::write(
                handler: [$c, 'delete'],
                tag: $tag,
                scope: $scope,
                summary: 'Delete a user with their listings, comments and saved searches',
                status: 204,
                errors: [404]
            ),
            'POST admin/users/{id}/sign-out-everywhere' => self::write(
                handler: [$c, 'signOutEverywhere'],
                tag: $tag,
                scope: $scope,
                summary: 'Sign a user out of every device: web sign-ins, API tokens and personal keys',
                status: 204,
                errors: [404]
            ),
            'GET admin/users/{id}/sessions' => self::read(
                handler: [$c, 'sessions'],
                tag: $tag,
                scope: $scope,
                summary: 'A user\'s live sign-ins; their keys are at /admin/keys',
                response: 'SessionList',
                errors: [404]
            ),
            'DELETE admin/users/{id}/sessions/{session}' => self::write(
                handler: [$c, 'endSession'],
                tag: $tag,
                scope: $scope,
                summary: 'End one of a user\'s sign-ins',
                status: 204,
                errors: [404]
            ),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function taxonomy(): array
    {
        $tag    = 'Admin taxonomy';
        $scope  = 'admin:taxonomy';
        $c      = AdminCategoriesController::class;
        $m      = AdminCurrenciesController::class;
        $f      = AdminFieldsController::class;
        $delete = self::write(
            handler: [$c, 'delete'],
            tag: $tag,
            scope: $scope,
            summary: 'Delete a category, its subcategories and their listings; a large one is emptied in the background (202)',
            status: 204,
            errors: [404]
        );
        $delete['responses'][202] = ['type' => 'null'];

        return [
            'GET admin/categories' => self::read(
                handler: [$c, 'index'],
                tag: $tag,
                scope: $scope,
                summary: 'Every category, enabled or not, with each language\'s texts',
                response: 'AdminCategoryList'
            ),
            'GET admin/categories/{id}' => self::read(
                handler: [$c, 'show'],
                tag: $tag,
                scope: $scope,
                summary: 'One category with each language\'s texts',
                response: 'AdminCategoryDocument',
                errors: [404]
            ),
            'POST admin/categories' => self::write(
                handler: [$c, 'create'],
                tag: $tag,
                scope: $scope,
                summary: 'Add a category',
                body: 'AdminCategoryInput',
                response: 'AdminCategoryDocument',
                status: 201
            ),
            'PATCH admin/categories/{id}' => self::write(
                handler: [$c, 'update'],
                tag: $tag,
                scope: $scope,
                summary: 'Edit a category; a new slug keeps the old one redirecting',
                body: 'AdminCategoryPatch',
                response: 'AdminCategoryDocument',
                errors: [404, 409]
            ),
            'DELETE admin/categories/{id}' => $delete,
            'GET admin/currencies/{code}' => self::read(
                handler: [$m, 'show'],
                tag: $tag,
                scope: $scope,
                summary: 'One currency',
                response: 'CurrencyDocument',
                errors: [404]
            ),
            'POST admin/currencies' => self::write(
                handler: [$m, 'create'],
                tag: $tag,
                scope: $scope,
                summary: 'Add a currency',
                body: 'CurrencyInput',
                response: 'CurrencyDocument',
                status: 201,
                errors: [409]
            ),
            'PATCH admin/currencies/{code}' => self::write(
                handler: [$m, 'update'],
                tag: $tag,
                scope: $scope,
                summary: 'Rename a currency or change its symbol',
                body: 'CurrencyPatch',
                response: 'CurrencyDocument',
                errors: [404]
            ),
            'DELETE admin/currencies/{code}' => self::write(
                handler: [$m, 'delete'],
                tag: $tag,
                scope: $scope,
                summary: 'Delete a currency no listing uses and the site does not default to',
                status: 204,
                errors: [404, 409]
            ),
            'GET admin/custom-fields/{id}' => self::read(
                handler: [$f, 'show'],
                tag: $tag,
                scope: $scope,
                summary: 'One custom field',
                response: 'AdminCustomFieldDocument',
                errors: [404]
            ),
            'POST admin/custom-fields' => self::write(
                handler: [$f, 'create'],
                tag: $tag,
                scope: $scope,
                summary: 'Add a custom field',
                body: 'AdminCustomFieldInput',
                response: 'AdminCustomFieldDocument',
                status: 201
            ),
            'PATCH admin/custom-fields/{id}' => self::write(
                handler: [$f, 'update'],
                tag: $tag,
                scope: $scope,
                summary: 'Edit a custom field',
                body: 'AdminCustomFieldPatch',
                response: 'AdminCustomFieldDocument',
                errors: [404]
            ),
            'DELETE admin/custom-fields/{id}' => self::write(
                handler: [$f, 'delete'],
                tag: $tag,
                scope: $scope,
                summary: 'Delete a custom field and its values',
                status: 204,
                errors: [404]
            ),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function locations(): array
    {
        $tag   = 'Admin taxonomy';
        $scope = 'admin:taxonomy';
        $c     = AdminLocationsController::class;
        $gone  = ' and the listings in it';

        return [
            'GET admin/regions/{id}' => self::read(
                handler: [$c, 'showRegion'],
                tag: $tag,
                scope: $scope,
                summary: 'One region',
                response: 'RegionDocument',
                errors: [404]
            ),
            'POST admin/regions' => self::write(
                handler: [$c, 'createRegion'],
                tag: $tag,
                scope: $scope,
                summary: 'Add a region to a country',
                body: 'RegionInput',
                response: 'RegionDocument',
                status: 201,
                errors: [404]
            ),
            'PATCH admin/regions/{id}' => self::write(
                handler: [$c, 'updateRegion'],
                tag: $tag,
                scope: $scope,
                summary: 'Rename a region',
                body: 'RegionPatch',
                response: 'RegionDocument',
                errors: [404]
            ),
            'DELETE admin/regions/{id}' => self::write(
                handler: [$c, 'deleteRegion'],
                tag: $tag,
                scope: $scope,
                summary: 'Delete a region, its cities' . $gone,
                status: 204,
                errors: [404]
            ),
            'GET admin/cities/{id}' => self::read(
                handler: [$c, 'showCity'],
                tag: $tag,
                scope: $scope,
                summary: 'One city',
                response: 'CityDocument',
                errors: [404]
            ),
            'POST admin/cities' => self::write(
                handler: [$c, 'createCity'],
                tag: $tag,
                scope: $scope,
                summary: 'Add a city to a region',
                body: 'CityInput',
                response: 'CityDocument',
                status: 201,
                errors: [404]
            ),
            'PATCH admin/cities/{id}' => self::write(
                handler: [$c, 'updateCity'],
                tag: $tag,
                scope: $scope,
                summary: 'Rename a city',
                body: 'CityPatch',
                response: 'CityDocument',
                errors: [404]
            ),
            'DELETE admin/cities/{id}' => self::write(
                handler: [$c, 'deleteCity'],
                tag: $tag,
                scope: $scope,
                summary: 'Delete a city, its areas' . $gone,
                status: 204,
                errors: [404]
            ),
            'GET admin/areas/{id}' => self::read(
                handler: [$c, 'showArea'],
                tag: $tag,
                scope: $scope,
                summary: 'One city area',
                response: 'CityAreaDocument',
                errors: [404]
            ),
            'POST admin/areas' => self::write(
                handler: [$c, 'createArea'],
                tag: $tag,
                scope: $scope,
                summary: 'Add an area to a city',
                body: 'CityAreaInput',
                response: 'CityAreaDocument',
                status: 201,
                errors: [404]
            ),
            'PATCH admin/areas/{id}' => self::write(
                handler: [$c, 'updateArea'],
                tag: $tag,
                scope: $scope,
                summary: 'Rename a city area',
                body: 'CityAreaPatch',
                response: 'CityAreaDocument',
                errors: [404]
            ),
            'DELETE admin/areas/{id}' => self::write(
                handler: [$c, 'deleteArea'],
                tag: $tag,
                scope: $scope,
                summary: 'Delete a city area' . $gone,
                status: 204,
                errors: [404]
            ),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function settings(): array
    {
        $tag = 'Admin settings';
        $s   = AdminSettingsController::class;
        $k   = AdminKeysController::class;

        return [
            'GET admin/settings' => self::read(
                handler: [$s, 'show'],
                tag: $tag,
                scope: 'admin:settings',
                summary: 'The settings the API can change',
                response: 'SettingsDocument'
            ),
            'PATCH admin/settings' => self::write(
                handler: [$s, 'update'],
                tag: $tag,
                scope: 'admin:settings',
                summary: 'Change some settings, all or none, checked as on the settings screens',
                body: 'SettingsPatch',
                response: 'SettingsDocument'
            ),
            'GET admin/jobs' => self::read(
                handler: [$s, 'jobs'],
                tag: $tag,
                scope: 'admin:settings',
                summary: 'The background job queue: jobs per status and those that stopped retrying',
                response: 'JobsDocument',
                query: [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                ]
            ),
            'GET admin/keys' => self::read(
                handler: [$k, 'index'],
                tag: $tag,
                scope: 'admin:keys',
                summary: 'Every API key, newest first; never a secret',
                response: 'ApiKeyList'
            ),
            'GET admin/keys/{id}' => self::read(
                handler: [$k, 'show'],
                tag: $tag,
                scope: 'admin:keys',
                summary: 'One API key; never its secret',
                response: 'ApiKeyDocument',
                errors: [404]
            ),
            'POST admin/keys' => self::write(
                handler: [$k, 'create'],
                tag: $tag,
                scope: 'admin:keys',
                summary: 'Make an admin or public key; its token is shown once',
                body: 'ApiKeyInput',
                response: 'ApiKeyDocument',
                status: 201,
                errors: [403],
                replayable: false
            ),
            'DELETE admin/keys/{id}' => self::write(
                handler: [$k, 'revoke'],
                tag: $tag,
                scope: 'admin:keys',
                summary: 'Revoke a key; never another admin\'s own key',
                status: 204,
                errors: [403, 404, 409]
            ),
            'POST admin/keys/{id}/rotate' => self::write(
                handler: [$k, 'rotate'],
                tag: $tag,
                scope: 'admin:keys',
                summary: 'Make a new key in place of one of yours or a public key; the old one works until revoked',
                response: 'ApiKeyDocument',
                status: 201,
                errors: [403, 404, 409],
                replayable: false
            ),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function webhooks(): array
    {
        $tag   = 'Admin webhooks';
        $scope = 'admin:webhooks';
        $c     = AdminWebhooksController::class;
        $test  = self::write(
            handler: [$c, 'test'],
            tag: $tag,
            scope: $scope,
            summary: 'Queue a ping to the endpoint, sent once even while it is off',
            response: 'WebhookTestDocument',
            status: 202,
            errors: [404]
        );

        return [
            'GET admin/webhooks' => self::read(
                handler: [$c, 'index'],
                tag: $tag,
                scope: $scope,
                summary: 'Every webhook endpoint, newest first; never a secret',
                response: 'WebhookList'
            ),
            'POST admin/webhooks' => self::write(
                handler: [$c, 'create'],
                tag: $tag,
                scope: $scope,
                summary: 'Add an endpoint; its signing secret is shown once',
                body: 'WebhookInput',
                response: 'WebhookDocument',
                status: 201,
                replayable: false
            ),
            'GET admin/webhooks/{webhook}' => self::read(
                handler: [$c, 'show'],
                tag: $tag,
                scope: $scope,
                summary: 'One endpoint; never its secret',
                response: 'WebhookDocument',
                errors: [404]
            ),
            'PATCH admin/webhooks/{webhook}' => self::write(
                handler: [$c, 'update'],
                tag: $tag,
                scope: $scope,
                summary: 'Change an endpoint; switching it on clears a pause',
                body: 'WebhookPatch',
                response: 'WebhookDocument',
                errors: [404]
            ),
            'DELETE admin/webhooks/{webhook}' => self::write(
                handler: [$c, 'delete'],
                tag: $tag,
                scope: $scope,
                summary: 'Delete an endpoint and its waiting deliveries',
                status: 204,
                errors: [404]
            ),
            'POST admin/webhooks/{webhook}/rotate-secret' => self::write(
                handler: [$c, 'rotate'],
                tag: $tag,
                scope: $scope,
                summary: 'A new signing secret, shown once; the old one also signs for 24 hours',
                response: 'WebhookDocument',
                errors: [404],
                replayable: false
            ),
            'POST admin/webhooks/{webhook}/test' => $test,
            'GET admin/webhooks/{webhook}/deliveries' => self::read(
                handler: [$c, 'deliveries'],
                tag: $tag,
                scope: $scope,
                summary: 'The endpoint\'s state and its deliveries still on the job queue',
                response: 'WebhookDeliveriesDocument',
                query: [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
                errors: [404]
            ),
            'GET admin/webhook-events' => self::read(
                handler: [$c, 'events'],
                tag: $tag,
                scope: $scope,
                summary: 'The events an endpoint can subscribe to, plugin events included',
                response: 'WebhookEventList'
            ),
        ];
    }

    /**
     * An admin read.
     *
     * @param array{class-string,string}         $handler
     * @param array<string,array<string,mixed>> $query
     * @param int[]                             $errors
     *
     * @return array<string,mixed>
     */
    private static function read(array $handler, string $tag, string $scope, string $summary, string $response, array $query = [], array $errors = []): array
    {
        return RouteSpec::read(
            handler: $handler,
            tag: $tag,
            summary: $summary,
            response: $response,
            query: $query,
            errors: $errors,
            auth: RouteSpec::AUTH_ADMIN,
            scope: $scope
        );
    }

    /**
     * An admin write.
     *
     * @param array{class-string,string} $handler
     * @param int[]                      $errors
     *
     * @return array<string,mixed>
     */
    private static function write(
        array $handler,
        string $tag,
        string $scope,
        string $summary,
        ?string $body = null,
        ?string $response = null,
        int $status = 200,
        array $errors = [],
        bool $replayable = true
    ): array {
        return RouteSpec::write(
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
        );
    }
}
