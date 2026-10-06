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

namespace mindstellar\api\schema;

use mindstellar\admin\ExposedSettings;

use mindstellar\api\serializer\CustomFieldSerializer;
use mindstellar\apiaccess\ApiKeyService;
use mindstellar\comment\CommentStatus;
use mindstellar\webhook\Endpoint;
use mindstellar\webhook\Events;
use mindstellar\webhook\WebhookService;

/**
 * The component schemas of the admin endpoints: what they answer with and the bodies their
 * writes take. Schema lists them with its own.
 */
final class AdminSchema
{
    /** Every component name, known without building a schema. */
    public const NAMES = [
        'AdminListingPatch',
        'AdminComment', 'AdminCommentPatch', 'AdminCommentPage', 'AdminCommentDocument',
        'AdminUserPatch', 'UserPage',
        'AdminCategory', 'AdminCategoryInput', 'AdminCategoryPatch', 'AdminCategoryList', 'AdminCategoryDocument',
        'RegionInput', 'RegionPatch', 'RegionDocument', 'CityInput', 'CityPatch', 'CityDocument',
        'CityAreaInput', 'CityAreaPatch', 'CityAreaDocument', 'CurrencyInput', 'CurrencyPatch', 'CurrencyDocument',
        'AdminField', 'AdminFieldInput', 'AdminFieldPatch', 'AdminFieldDocument',
        'Settings', 'SettingsPatch', 'SettingsDocument',
        'ApiKey', 'ApiKeyInput', 'ApiKeyList', 'ApiKeyDocument',
        'Job', 'Jobs', 'JobsDocument',
        'Webhook', 'WebhookInput', 'WebhookPatch', 'WebhookList', 'WebhookDocument', 'WebhookTest', 'WebhookTestDocument',
        'WebhookDelivery', 'WebhookDeliveries', 'WebhookDeliveriesDocument', 'WebhookEvent', 'WebhookEventList',
        'WebhookMessage', 'WebhookDeleted', 'WebhookPing',
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function components(): array
    {
        $text   = static fn (int $max, int $min = 0): array => ['type' => 'string', 'minLength' => $min, 'maxLength' => $max];
        $input  = static fn (array $properties, array $required = []): array => Schema::object($properties, $required) + ['additionalProperties' => false];
        $doc    = static fn (string $name): array => Schema::object(['data' => Schema::ref($name)], ['data']);
        $list   = static fn (string $name): array => Schema::object(['data' => ['type' => 'array', 'items' => Schema::ref($name)]], ['data']);
        $page   = static fn (string $name): array => Schema::object([
            'data'  => ['type' => 'array', 'items' => Schema::ref($name)],
            'meta'  => Schema::ref('PageMeta'),
            'links' => Schema::ref('PageLinks'),
        ], ['data', 'meta', 'links']);
        $id     = ['type' => 'integer', 'minimum' => 1];
        $name   = $text(100, 1);
        $places = static fn (string $parent, array $parentSchema): array => [
            $input([$parent => $parentSchema, 'name' => $name], [$parent, 'name']),
            $input(['name' => $name, 'slug' => $text(255) + ['description' => 'Made from the name when empty or taken.']]),
        ];

        [$regionInput, $regionPatch] = $places('country', ['type' => 'string', 'pattern' => '^[A-Za-z]{2}$']);
        [$cityInput, $cityPatch]     = $places('region_id', $id);

        return [
            'AdminListingPatch'     => $input(array_diff_key(Schema::listingMembers(), ['photo_tokens' => true, 'photo_urls' => true]) + [
                'owner_id'      => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'The user the listing belongs to; null for none.'],
                'contact_name'  => $text(100) + ['description' => 'Used when the listing has no owner.'],
                'contact_email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 100, 'description' => 'Used when the listing has no owner.'],
                'expires_at'    => ['type' => ['string', 'null'], 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$', 'description' => 'The last day it shows; null for never.'],
                'approved'      => ['type' => 'boolean', 'description' => 'true approves a listing that waits for moderation; false sends it back.'],
                'blocked'       => ['type' => 'boolean', 'description' => 'true blocks it; false unblocks it.'],
                'spam'          => ['type' => 'boolean', 'description' => 'true marks it as spam; false clears the mark.'],
                'premium'       => ['type' => 'boolean', 'description' => 'true makes it premium, with no end date; false ends it.'],
            ]),
            'AdminComment'          => Schema::object([
                'id'           => ['type' => 'integer'],
                'listing_id'   => ['type' => 'integer'],
                'status'       => ['type' => 'string', 'enum' => CommentStatus::ALL],
                'approved'     => ['type' => 'boolean', 'description' => 'false while it waits for moderation.'],
                'blocked'      => ['type' => 'boolean'],
                'title'        => Schema::nullable('string'),
                'body'         => ['type' => 'string'],
                'author'       => Schema::object([
                    'name'    => Schema::nullable('string'),
                    'email'   => Schema::nullable('string'),
                    'user_id' => Schema::nullable('integer'),
                ], ['name', 'email', 'user_id']),
                'published_at' => Schema::time(),
            ], ['id', 'listing_id', 'status', 'approved', 'blocked', 'title', 'body', 'author', 'published_at']),
            'AdminCommentPatch'     => $input([
                'title'        => $text(200),
                'body'         => $text(5000, 1),
                'author_name'  => $text(100),
                'author_email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 100],
                'approved'     => ['type' => 'boolean', 'description' => 'true approves it, and its author is told; false holds it back.'],
                'blocked'      => ['type' => 'boolean', 'description' => 'true blocks it; false unblocks it.'],
            ]),
            'AdminCommentPage'      => $page('AdminComment'),
            'AdminCommentDocument'  => $doc('AdminComment'),
            'AdminUserPatch'        => $input([
                'email'     => ['type' => 'string', 'format' => 'email', 'maxLength' => 100],
                'username'  => $text(100, 1),
                'password'  => $text(4096, 1) + ['description' => 'A new password; every sign-in and key of the user ends.'],
                'confirmed' => ['type' => 'boolean', 'description' => 'Whether the account is confirmed.'],
                'blocked'   => ['type' => 'boolean', 'description' => 'true blocks the user, and their sign-ins and keys stop working.'],
            ] + array_diff_key(Schema::profileMembers(), ['email' => true])),
            'UserPage'              => $page('User'),
            'AdminCategory'         => Schema::object([
                'id'              => ['type' => 'integer'],
                'parent_id'       => Schema::nullable('integer'),
                'enabled'         => ['type' => 'boolean'],
                'position'        => ['type' => 'integer'],
                'expiration_days' => ['type' => 'integer', 'description' => '0 for never.'],
                'price_enabled'   => ['type' => 'boolean'],
                'listings_count'  => ['type' => 'integer'],
                'translations'    => [
                    'type'                 => 'object',
                    'description'          => 'Locale => {name, slug, description}.',
                    'additionalProperties' => Schema::object([
                        'name'        => Schema::nullable('string'),
                        'slug'        => ['type' => 'string'],
                        'description' => Schema::nullable('string'),
                    ], ['name', 'slug', 'description']),
                ],
            ], ['id', 'parent_id', 'enabled', 'position', 'expiration_days', 'price_enabled', 'translations']),
            'AdminCategoryInput'    => $input([
                'parent_id'       => ['type' => ['integer', 'null'], 'minimum' => 1],
                'translations'    => [
                    'type'                 => 'object',
                    'description'          => 'Locale => {name, description}; the slug is made from the name.',
                    'additionalProperties' => $input(['name' => $name, 'description' => $text(5000)], ['name']),
                ],
                'expiration_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999],
                'price_enabled'   => ['type' => 'boolean'],
                'enabled'         => ['type' => 'boolean'],
            ], ['translations']),
            'AdminCategoryPatch'    => $input([
                'translations'           => [
                    'type'                 => 'object',
                    'description'          => 'Locale => {name, slug, description}; a changed slug keeps redirecting from the old one.',
                    'additionalProperties' => $input(['name' => $name, 'slug' => $text(255, 1), 'description' => $text(5000)]),
                ],
                'expiration_days'        => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999],
                'price_enabled'          => ['type' => 'boolean'],
                'apply_to_subcategories' => ['type' => 'boolean', 'description' => 'Give subcategories the same expiry and price setting.'],
                'enabled'                => ['type' => 'boolean', 'description' => 'A top category takes its subcategories and their listings with it.'],
            ]),
            'AdminCategoryList'     => $list('AdminCategory'),
            'AdminCategoryDocument' => $doc('AdminCategory'),
            'RegionInput'           => $regionInput,
            'RegionPatch'           => $regionPatch,
            'RegionDocument'        => $doc('Region'),
            'CityInput'             => $cityInput,
            'CityPatch'             => $cityPatch,
            'CityDocument'          => $doc('City'),
            'CityAreaInput'         => $input(['city_id' => $id, 'name' => $name], ['city_id', 'name']),
            'CityAreaPatch'         => $input(['name' => $name], ['name']),
            'CityAreaDocument'      => $doc('CityArea'),
            'CurrencyInput'         => $input([
                'code'   => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                'name'   => $text(40, 1),
                'symbol' => $text(80),
            ], ['code', 'name']),
            'CurrencyPatch'         => $input(['name' => $text(40, 1), 'symbol' => $text(80)]),
            'CurrencyDocument'      => $doc('Currency'),
            'AdminField'            => Schema::object([
                'id'         => ['type' => 'integer'],
                'slug'       => ['type' => 'string'],
                'name'       => ['type' => 'string'],
                'type'       => ['type' => 'string', 'enum' => CustomFieldSerializer::TYPES],
                'required'   => ['type' => 'boolean'],
                'searchable' => ['type' => 'boolean'],
                'options'    => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                'categories' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ], ['id', 'slug', 'name', 'type', 'required', 'searchable', 'options', 'categories']),
            'AdminFieldInput'       => $input(self::fieldMembers($text), ['name', 'type']),
            'AdminFieldPatch'       => $input(self::fieldMembers($text)),
            'AdminFieldDocument'    => $doc('AdminField'),
            'Settings'              => Schema::object(ExposedSettings::schemas(), array_keys(ExposedSettings::FIELDS)),
            'SettingsPatch'         => $input(ExposedSettings::schemas()),
            'SettingsDocument'      => $doc('Settings'),
            'ApiKey'                => Schema::object([
                'id'           => ['type' => 'integer'],
                'name'         => ['type' => 'string'],
                'kind'         => ['type' => 'string', 'enum' => ['admin', 'public', 'user']],
                'prefix'       => ['type' => 'string'],
                'scopes'       => ['type' => 'array', 'items' => ['type' => 'string']],
                'owner'        => Schema::nullable('string', 'The admin or user it acts for.'),
                'status'       => ['type' => 'string', 'enum' => [
                    ApiKeyService::STATUS_ACTIVE, ApiKeyService::STATUS_REVOKED, ApiKeyService::STATUS_EXPIRED,
                    ApiKeyService::STATUS_DISABLED, ApiKeyService::STATUS_ORPHANED,
                ]],
                'created_at'   => Schema::time(),
                'last_used_at' => Schema::time(),
                'expires_at'   => Schema::time(),
                'token'        => ['type' => 'string', 'description' => 'Only in the answer that makes the key. It is not stored, so keep it.'],
            ], ['id', 'name', 'kind', 'prefix', 'scopes', 'owner', 'status']),
            'ApiKeyInput'           => $input([
                'name'       => $text(100, 1),
                'kind'       => ['type' => 'string', 'enum' => ['admin', 'public'], 'description' => 'admin by default; a public key only reads public data.'],
                'scopes'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only scopes the calling key holds.'],
                'expires_at' => ['type' => 'string', 'pattern' => '^([0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{1,4}d)$', 'description' => 'The last day it works, or a number of days such as 90d; never when left out.'],
            ], ['name']),
            'ApiKeyList'            => $list('ApiKey'),
            'ApiKeyDocument'        => $doc('ApiKey'),
            'Job'                   => Schema::object([
                'id'          => ['type' => 'integer'],
                'type'        => ['type' => 'string'],
                'status'      => ['type' => 'string'],
                'attempts'    => ['type' => 'integer'],
                'last_error'  => Schema::nullable('string'),
                'created_at'  => Schema::time(),
                'next_run_at' => Schema::time(),
            ], ['id', 'type', 'status', 'attempts', 'last_error']),
            'Jobs'                  => Schema::object([
                'counts'       => ['type' => 'object', 'description' => 'Jobs per status.', 'additionalProperties' => ['type' => 'integer']],
                'dead_letters' => ['type' => 'array', 'items' => Schema::ref('Job'), 'description' => 'Jobs that stopped retrying, newest first.'],
            ], ['counts', 'dead_letters']),
            'JobsDocument'          => $doc('Jobs'),
        ] + self::webhooks($text, $input, $doc, $list);
    }

    /**
     * @param \Closure(int, int=): array<string,mixed>                         $text
     * @param \Closure(array<string,mixed>, string[]=): array<string,mixed>   $input
     * @param \Closure(string): array<string,mixed>                           $doc
     * @param \Closure(string): array<string,mixed>                           $list
     *
     * @return array<string,array<string,mixed>>
     */
    private static function webhooks(\Closure $text, \Closure $input, \Closure $doc, \Closure $list): array
    {
        $url    = ['type' => 'string', 'format' => 'uri', 'maxLength' => WebhookService::MAX_URL, 'pattern' => '^https?://', 'description' => 'http or https, on port 80 or 443. A private network address only while Settings -> API allows it.'];
        $events = ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string'], 'description' => 'Event types, from GET /admin/webhook-events.'];

        return [
            'Webhook'                   => Schema::object([
                'id'                    => ['type' => 'string'],
                'url'                   => ['type' => 'string'],
                'description'           => ['type' => 'string'],
                'events'                => ['type' => 'array', 'items' => ['type' => 'string']],
                'enabled'               => ['type' => 'boolean'],
                'status'                => ['type' => 'string', 'enum' => Endpoint::STATUSES, 'description' => 'paused: switched off after ' . Endpoint::PAUSE_AFTER . ' failed deliveries in a row.'],
                'failures'              => ['type' => 'integer', 'description' => 'Failed deliveries in a row.'],
                'last_status'           => Schema::nullable('string', 'The last delivery\'s answer: "HTTP <code>", "Timed out", "Could not connect", or why the address was refused.'),
                'last_attempt_at'       => Schema::time(),
                'last_success_at'       => Schema::time(),
                'last_failure_at'       => Schema::time(),
                'paused_at'             => Schema::time(),
                'paused_reason'         => Schema::nullable('string'),
                'previous_secret_until' => Schema::time('While set, deliveries carry a second signature with the secret a rotation replaced.'),
                'created_by'            => Schema::nullable('integer', 'The admin who added it.'),
                'created_at'            => Schema::time(),
                'updated_at'            => Schema::time(),
                'secret'                => ['type' => 'string', 'description' => 'Only in the answer that makes the endpoint or rotates its secret. Keep it: it is not shown again.'],
            ], ['id', 'url', 'description', 'events', 'enabled', 'status', 'failures']),
            'WebhookInput'              => $input([
                'url'         => $url,
                'events'      => $events,
                'description' => $text(WebhookService::MAX_DESCRIPTION),
                'enabled'     => ['type' => 'boolean', 'description' => 'true when left out.'],
            ], ['url', 'events']),
            'WebhookPatch'              => $input([
                'url'         => $url,
                'events'      => $events,
                'description' => $text(WebhookService::MAX_DESCRIPTION),
                'enabled'     => ['type' => 'boolean', 'description' => 'true also clears a pause and the failure count.'],
            ]),
            'WebhookList'               => $list('Webhook'),
            'WebhookDocument'           => $doc('Webhook'),
            'WebhookTest'               => Schema::object([
                'message_id' => ['type' => 'string', 'description' => 'The webhook-id the ping is sent with.'],
                'type'       => ['type' => 'string', 'enum' => [Events::PING]],
            ], ['message_id', 'type']),
            'WebhookTestDocument'       => $doc('WebhookTest'),
            'WebhookDelivery'           => Schema::object([
                'job_id'      => ['type' => 'integer'],
                'message_id'  => ['type' => 'string'],
                'type'        => ['type' => 'string'],
                'status'      => ['type' => 'string', 'enum' => ['pending', 'running', 'error'], 'description' => 'error: gave up after the last try.'],
                'attempts'    => ['type' => 'integer', 'description' => 'Failed tries so far.'],
                'last_error'  => Schema::nullable('string'),
                'test'        => ['type' => 'boolean'],
                'created_at'  => Schema::time(),
                'next_run_at' => Schema::time(),
            ], ['job_id', 'message_id', 'type', 'status', 'attempts', 'last_error', 'test']),
            'WebhookDeliveries'         => Schema::object([
                'endpoint'   => Schema::ref('Webhook'),
                'deliveries' => ['type' => 'array', 'items' => Schema::ref('WebhookDelivery'), 'description' => 'Newest first. A delivery that got a 2xx answer leaves the queue.'],
            ], ['endpoint', 'deliveries']),
            'WebhookDeliveriesDocument' => $doc('WebhookDeliveries'),
            'WebhookEvent'              => Schema::object([
                'type'        => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'schema'      => Schema::nullable('string', 'The component schema of the event\'s data.'),
            ], ['type', 'description', 'schema']),
            'WebhookEventList'          => $list('WebhookEvent'),
            'WebhookMessage'            => Schema::object([
                'type'      => ['type' => 'string'],
                'id'        => ['type' => 'string', 'description' => 'The same as the webhook-id header.'],
                'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                'thin'      => ['type' => 'boolean', 'description' => 'Present and true when the body was too big, or the listing or comment is not live: data then holds only id and url, and live: false when it is not live.'],
                'data'      => ['type' => 'object'],
            ], ['type', 'id', 'timestamp', 'data']),
            'WebhookDeleted'            => Schema::object(['id' => ['type' => 'integer']], ['id']),
            'WebhookPing'               => Schema::object([
                'endpoint_id' => ['type' => 'string'],
                'message'     => ['type' => 'string'],
            ], ['endpoint_id', 'message']),
        ];
    }

    /**
     * @param \Closure(int, int=): array<string,mixed> $text
     *
     * @return array<string,array<string,mixed>>
     */
    private static function fieldMembers(\Closure $text): array
    {
        return [
            'name'       => $text(255, 1),
            'type'       => ['type' => 'string', 'enum' => CustomFieldSerializer::TYPES],
            'slug'       => $text(255) + ['description' => 'Made from the name when empty; a taken one gets a number.'],
            'required'   => ['type' => 'boolean'],
            'searchable' => ['type' => 'boolean'],
            'options'    => ['type' => 'array', 'maxItems' => 500, 'items' => $text(255, 1), 'description' => 'The choices of a dropdown or radio field, cleaned of tags. A choice may not contain a comma: the field stores them comma-separated.'],
            'categories' => ['type' => 'array', 'maxItems' => 2000, 'items' => ['type' => 'integer', 'minimum' => 1], 'description' => 'The categories that ask for it; replaces the list.'],
        ];
    }
}
