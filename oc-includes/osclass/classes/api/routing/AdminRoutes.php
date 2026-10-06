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

    private const COUNT = ['count' => ['type' => 'boolean', 'description' => 'true: also count every match for meta.total; skipped otherwise, as it costs a query.']];

    private const VIEW = [
        'locale' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2,3}_[A-Za-z]{2}$'],
        'fields' => ['type' => 'string', 'maxLength' => 500],
    ];

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
        $tag    = 'Admin listings';
        $scope  = 'admin:listings';
        $status = Schema::listOf(ListingStatus::ALL);
        return [
            'GET admin/listings' => self::read(AdminListingsController::class, 'index', $tag, $scope, 'Every listing, whatever its status, newest first', 'ListingPage', [
                'status'   => $status,
                'user'     => ['type' => 'integer', 'minimum' => 1],
                'category' => ['type' => 'integer', 'minimum' => 1],
                'q'        => ['type' => 'string', 'maxLength' => 100, 'description' => 'Titles containing this.'],
                'include'  => ['type' => 'string', 'maxLength' => 100],
            ] + self::PAGING + self::COUNT + self::VIEW, [400, 422]),
            'GET admin/listings/{id}' => self::read(AdminListingsController::class, 'show', $tag, $scope, 'One listing in the admin view', 'ListingDocument', self::VIEW + ['include' => ['type' => 'string', 'maxLength' => 100]], [404]),
            'PATCH admin/listings/{id}' => self::write(AdminListingsController::class, 'update', $tag, $scope, 'Edit any listing, its owner, expiry and status included; members not sent keep their values', 'AdminListingPatch', 'ListingDocument', 200, [404, 409]),
            'DELETE admin/listings/{id}' => self::write(AdminListingsController::class, 'delete', $tag, $scope, 'Delete a listing', null, null, 204, [404]),
            'POST admin/listings/{id}/bump' => self::write(AdminListingsController::class, 'bump', $tag, $scope, ListingModeration::ACTIONS['bump'], null, 'ListingDocument', 200, [404]),
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
            'GET admin/comments' => self::read($c, 'index', $tag, $scope, 'Every comment, whatever its status, newest first', 'AdminCommentPage', [
                'status'  => Schema::listOf(CommentStatus::ALL),
                'listing' => ['type' => 'integer', 'minimum' => 1],
                'user'    => ['type' => 'integer', 'minimum' => 1],
            ] + self::PAGING + self::COUNT, [400, 422]),
            'GET admin/comments/{id}'             => self::read($c, 'show', $tag, $scope, 'One comment', 'AdminCommentDocument', [], [404]),
            'PATCH admin/comments/{id}'           => self::write($c, 'update', $tag, $scope, 'Edit a comment\'s text or author, or approve or block it', 'AdminCommentPatch', 'AdminCommentDocument', 200, [404]),
            'DELETE admin/comments/{id}'          => self::write($c, 'delete', $tag, $scope, 'Delete a comment', null, null, 204, [404]),
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
            'GET admin/users' => self::read($c, 'index', $tag, $scope, 'Every user, newest first', 'UserPage', [
                'q'       => ['type' => 'string', 'maxLength' => 100, 'description' => 'E-mail, username or name starting with this.'],
                'confirmed' => ['type' => 'boolean'],
                'blocked'   => ['type' => 'boolean'],
            ] + self::PAGING + self::COUNT + self::VIEW, [400, 422]),
            'GET admin/users/{id}'                        => self::read($c, 'show', $tag, $scope, 'One user, every member', 'UserDocument', self::VIEW, [404]),
            'PATCH admin/users/{id}'                      => self::write($c, 'update', $tag, $scope, 'Edit a user\'s profile, e-mail, username or password, or confirm or block them', 'AdminUserPatch', 'UserDocument', 200, [404]),
            'DELETE admin/users/{id}'                     => self::write($c, 'delete', $tag, $scope, 'Delete a user with their listings, comments and saved searches', null, null, 204, [404]),
            'POST admin/users/{id}/sign-out-everywhere'   => self::write($c, 'signOutEverywhere', $tag, $scope, 'Sign a user out of every device: web sign-ins, API tokens and personal keys', null, null, 204, [404]),
            'GET admin/users/{id}/sessions'               => self::read($c, 'sessions', $tag, $scope, 'A user\'s sign-ins and API keys', 'SessionList', [], [404]),
            'DELETE admin/users/{id}/sessions/{session}'  => self::write($c, 'endSession', $tag, $scope, 'End one of a user\'s sign-ins, or revoke one of their keys', null, null, 204, [404]),
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
        $delete = self::write($c, 'delete', $tag, $scope, 'Delete a category, its subcategories and their listings; a large one is emptied in the background (202)', null, null, 204, [404]);
        $delete['responses'][202] = ['type' => 'null'];

        return [
            'GET admin/categories'           => self::read($c, 'index', $tag, $scope, 'Every category, enabled or not, with each language\'s texts', 'AdminCategoryList'),
            'GET admin/categories/{id}'      => self::read($c, 'show', $tag, $scope, 'One category with each language\'s texts', 'AdminCategoryDocument', [], [404]),
            'POST admin/categories'          => self::write($c, 'create', $tag, $scope, 'Add a category', 'AdminCategoryInput', 'AdminCategoryDocument', 201),
            'PATCH admin/categories/{id}'    => self::write($c, 'update', $tag, $scope, 'Edit a category; a new slug keeps the old one redirecting', 'AdminCategoryPatch', 'AdminCategoryDocument', 200, [404, 409]),
            'DELETE admin/categories/{id}'   => $delete,
            'GET admin/currencies/{code}'    => self::read($m, 'show', $tag, $scope, 'One currency', 'CurrencyDocument', [], [404]),
            'POST admin/currencies'          => self::write($m, 'create', $tag, $scope, 'Add a currency', 'CurrencyInput', 'CurrencyDocument', 201, [409]),
            'PATCH admin/currencies/{code}'  => self::write($m, 'update', $tag, $scope, 'Rename a currency or change its symbol', 'CurrencyPatch', 'CurrencyDocument', 200, [404]),
            'DELETE admin/currencies/{code}' => self::write($m, 'delete', $tag, $scope, 'Delete a currency no listing uses and the site does not default to', null, null, 204, [404, 409]),
            'GET admin/custom-fields/{id}'          => self::read($f, 'show', $tag, $scope, 'One custom field', 'AdminCustomFieldDocument', [], [404]),
            'POST admin/custom-fields'              => self::write($f, 'create', $tag, $scope, 'Add a custom field', 'AdminCustomFieldInput', 'AdminCustomFieldDocument', 201),
            'PATCH admin/custom-fields/{id}'        => self::write($f, 'update', $tag, $scope, 'Edit a custom field', 'AdminCustomFieldPatch', 'AdminCustomFieldDocument', 200, [404]),
            'DELETE admin/custom-fields/{id}'       => self::write($f, 'delete', $tag, $scope, 'Delete a custom field and its values', null, null, 204, [404]),
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
            'GET admin/regions/{id}'    => self::read($c, 'showRegion', $tag, $scope, 'One region', 'RegionDocument', [], [404]),
            'POST admin/regions'        => self::write($c, 'createRegion', $tag, $scope, 'Add a region to a country', 'RegionInput', 'RegionDocument', 201, [404]),
            'PATCH admin/regions/{id}'  => self::write($c, 'updateRegion', $tag, $scope, 'Rename a region', 'RegionPatch', 'RegionDocument', 200, [404]),
            'DELETE admin/regions/{id}' => self::write($c, 'deleteRegion', $tag, $scope, 'Delete a region, its cities' . $gone, null, null, 204, [404]),
            'GET admin/cities/{id}'     => self::read($c, 'showCity', $tag, $scope, 'One city', 'CityDocument', [], [404]),
            'POST admin/cities'         => self::write($c, 'createCity', $tag, $scope, 'Add a city to a region', 'CityInput', 'CityDocument', 201, [404]),
            'PATCH admin/cities/{id}'   => self::write($c, 'updateCity', $tag, $scope, 'Rename a city', 'CityPatch', 'CityDocument', 200, [404]),
            'DELETE admin/cities/{id}'  => self::write($c, 'deleteCity', $tag, $scope, 'Delete a city, its areas' . $gone, null, null, 204, [404]),
            'GET admin/areas/{id}'      => self::read($c, 'showArea', $tag, $scope, 'One city area', 'CityAreaDocument', [], [404]),
            'POST admin/areas'          => self::write($c, 'createArea', $tag, $scope, 'Add an area to a city', 'CityAreaInput', 'CityAreaDocument', 201, [404]),
            'PATCH admin/areas/{id}'    => self::write($c, 'updateArea', $tag, $scope, 'Rename a city area', 'CityAreaPatch', 'CityAreaDocument', 200, [404]),
            'DELETE admin/areas/{id}'   => self::write($c, 'deleteArea', $tag, $scope, 'Delete a city area' . $gone, null, null, 204, [404]),
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
            'GET admin/settings'           => self::read($s, 'show', $tag, 'admin:settings', 'The settings the API can change', 'SettingsDocument'),
            'PATCH admin/settings'         => self::write($s, 'update', $tag, 'admin:settings', 'Change some settings, all or none, checked as on the settings screens', 'SettingsPatch', 'SettingsDocument'),
            'GET admin/jobs'               => self::read($s, 'jobs', $tag, 'admin:settings', 'The background job queue: jobs per status and those that stopped retrying', 'JobsDocument', [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            ]),
            'GET admin/keys'               => self::read($k, 'index', $tag, 'admin:keys', 'Every API key, newest first; never a secret', 'ApiKeyList'),
            'GET admin/keys/{id}'          => self::read($k, 'show', $tag, 'admin:keys', 'One API key; never its secret', 'ApiKeyDocument', [], [404]),
            'POST admin/keys'              => self::write($k, 'create', $tag, 'admin:keys', 'Make an admin or public key; its token is shown once', 'ApiKeyInput', 'ApiKeyDocument', 201, [403], false),
            'DELETE admin/keys/{id}'       => self::write($k, 'revoke', $tag, 'admin:keys', 'Revoke a key', null, null, 204, [404, 409]),
            'POST admin/keys/{id}/rotate'  => self::write($k, 'rotate', $tag, 'admin:keys', 'Make a new key in place of one of yours or a public key; the old one works until revoked', null, 'ApiKeyDocument', 201, [403, 404, 409], false),
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
        $test  = self::write($c, 'test', $tag, $scope, 'Queue a ping to the endpoint, sent once even while it is off', null, 'WebhookTestDocument', 202, [404]);

        return [
            'GET admin/webhooks'                            => self::read($c, 'index', $tag, $scope, 'Every webhook endpoint, newest first; never a secret', 'WebhookList'),
            'POST admin/webhooks'                           => self::write($c, 'create', $tag, $scope, 'Add an endpoint; its signing secret is shown once', 'WebhookInput', 'WebhookDocument', 201, [], false),
            'GET admin/webhooks/{webhook}'                  => self::read($c, 'show', $tag, $scope, 'One endpoint; never its secret', 'WebhookDocument', [], [404]),
            'PATCH admin/webhooks/{webhook}'                => self::write($c, 'update', $tag, $scope, 'Change an endpoint; switching it on clears a pause', 'WebhookPatch', 'WebhookDocument', 200, [404]),
            'DELETE admin/webhooks/{webhook}'               => self::write($c, 'delete', $tag, $scope, 'Delete an endpoint and its waiting deliveries', null, null, 204, [404]),
            'POST admin/webhooks/{webhook}/rotate-secret'   => self::write($c, 'rotate', $tag, $scope, 'A new signing secret, shown once; the old one also signs for 24 hours', null, 'WebhookDocument', 200, [404], false),
            'POST admin/webhooks/{webhook}/test'            => $test,
            'GET admin/webhooks/{webhook}/deliveries'       => self::read($c, 'deliveries', $tag, $scope, 'The endpoint\'s state and its deliveries still on the job queue', 'WebhookDeliveriesDocument', [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ], [404]),
            'GET admin/webhook-events'                      => self::read($c, 'events', $tag, $scope, 'The events an endpoint can subscribe to, plugin events included', 'WebhookEventList'),
        ];
    }

    /**
     * An admin read.
     *
     * @param class-string                      $class
     * @param array<string,array<string,mixed>> $query
     * @param int[]                             $errors
     *
     * @return array<string,mixed>
     */
    private static function read(string $class, string $method, string $tag, string $scope, string $summary, string $response, array $query = [], array $errors = []): array
    {
        return RouteSpec::read(
            handler: [$class, $method],
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
     * @param class-string $class
     * @param int[]        $errors
     *
     * @return array<string,mixed>
     */
    private static function write(
        string $class,
        string $method,
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
            handler: [$class, $method],
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
