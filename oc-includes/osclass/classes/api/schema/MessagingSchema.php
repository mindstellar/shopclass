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

use mindstellar\messaging\MessagePolicy;
use mindstellar\push\PushSubscriptions;

/**
 * The component schemas of the account's conversations and messages. Schema lists them with its
 * own.
 */
final class MessagingSchema
{
    /** Every component name, known without building a schema. */
    public const NAMES = [
        'Conversation', 'ConversationInput', 'ConversationDocument', 'ConversationPage',
        'Message', 'MessageInput', 'MessageDocument', 'MessagePage', 'ReportInput',
        'PushSubscriptionInput', 'PushSubscriptionDocument',
        'AdminConversation', 'AdminConversationDocument', 'AdminConversationPage',
        'AdminMessage', 'AdminMessageDocument', 'AdminMessagePage',
    ];

    /** The message states an admin sees. */
    public const MESSAGE_STATES = ['sent', 'held', 'removed'];

    /** The thread states, as `state` names them. */
    public const STATES = ['open', 'held', 'closed', 'reported'];

    /**
     * The longest body the schema lets through. The site's own limit (5000 characters unless the
     * admin changes it) is checked by the server and answers 422.
     */
    public const MAX_BODY = 65535;

    private function __construct()
    {
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function components(): array
    {
        return self::user() + self::admin();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function user(): array
    {
        $input  = static fn (array $properties, array $required): array => Schema::object($properties, $required) + ['additionalProperties' => false];
        $person = Schema::object(['id' => ['type' => 'integer'], 'name' => Schema::nullable('string', 'Null once the account is gone.')], ['id', 'name']);
        $body   = ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_BODY, 'description' => 'Plain text. The site sets the longest it takes; a longer one is a 422.'];

        return [
            'Conversation' => Schema::object([
                'id'              => ['type' => 'integer'],
                'listing_id'      => ['type' => 'integer'],
                'listing_title'   => Schema::nullable('string', 'Null once the listing is gone.'),
                'side'            => ['type' => 'string', 'enum' => ['buyer', 'seller'], 'description' => 'Your side of the conversation.'],
                'buyer'           => $person,
                'seller'          => $person,
                'state'           => ['type' => 'string', 'enum' => self::STATES, 'description' => 'held: waiting for review, hidden from the seller. closed: nobody can write. reported: waiting for an admin.'],
                'unread'          => ['type' => 'integer', 'description' => 'Messages to you not read yet.'],
                'blocked_by_me'   => ['type' => 'boolean'],
                'blocked_by_them' => ['type' => 'boolean', 'description' => 'While either side blocks it, nobody can write.'],
                'reported_by_me'  => ['type' => 'boolean'],
                'last_message_at' => Schema::time(),
                'created_at'      => Schema::time(),
            ], ['id', 'listing_id', 'listing_title', 'side', 'buyer', 'seller', 'state', 'unread', 'blocked_by_me', 'blocked_by_them', 'reported_by_me', 'last_message_at', 'created_at']),
            'ConversationInput' => $input([
                'listing' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The listing to write to its seller about. A second message about it goes to the same conversation.'],
                'body'    => $body,
            ], ['listing', 'body']),
            'ConversationDocument' => Schema::object(['data' => Schema::ref('Conversation')], ['data']),
            'ConversationPage'     => Schema::object([
                'data'  => ['type' => 'array', 'items' => Schema::ref('Conversation')],
                'meta'  => Schema::object([
                    'total'     => Schema::nullable('integer', 'Always null: the list is not counted.'),
                    'limit'     => ['type' => 'integer'],
                    'unread'    => ['type' => 'integer', 'description' => 'Unread messages across all your conversations.'],
                    'latest_at' => Schema::time('The newest message in any of your conversations; null when there is none.'),
                ], ['limit', 'unread', 'latest_at']),
                'links' => Schema::ref('PageLinks'),
            ], ['data', 'meta', 'links']) + ['description' => 'Newest activity first. `limit=1` is a cheap poll for `meta.unread`.'],

            'Message' => Schema::object([
                'id'              => ['type' => 'integer'],
                'conversation_id' => ['type' => 'integer'],
                'sender_id'       => ['type' => 'integer'],
                'body'            => ['type' => 'string'],
                'held'            => ['type' => 'boolean', 'description' => 'True on your own message while it waits for review; the other person does not see it yet.'],
                'created_at'      => Schema::time(),
            ], ['id', 'conversation_id', 'sender_id', 'body', 'held', 'created_at']),
            'MessageInput'    => $input(['body' => $body], ['body']),
            'MessageDocument' => Schema::object([
                'data'     => Schema::ref('Message'),
                'warnings' => ['type' => 'array', 'items' => Schema::ref('Warning')],
            ], ['data']),
            'MessagePage' => Schema::wholeList('Message') + ['description' => 'Newest first, paged by cursor; with `after`, the messages after that id, oldest first, and no next page.'],
            'ReportInput' => $input([
                'reason' => ['type' => 'string', 'enum' => MessagePolicy::REPORT_REASONS],
            ], ['reason']),
            'PushSubscriptionInput' => $input([
                'endpoint' => ['type' => 'string', 'minLength' => 1, 'maxLength' => PushSubscriptions::MAX_ENDPOINT, 'description' => 'The https:// address the browser or app push service gave. A public address only; the push sent to it carries no text.'],
            ], ['endpoint']),
            'PushSubscriptionDocument' => Schema::object(['data' => Schema::object([
                'browser'    => ['type' => 'string', 'description' => 'Pass this to DELETE account/push-subscriptions/{browser}.'],
                'expires_at' => Schema::time('The endpoint is dropped then unless it is registered again.'),
            ], ['browser', 'expires_at'])], ['data']),
        ];
    }

    /**
     * The admin views: both parties, the counters of each side and the report, and messages with
     * their state.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function admin(): array
    {
        $doc    = static fn (string $name): array => Schema::object(['data' => Schema::ref($name)], ['data']);
        $person = Schema::object(['id' => ['type' => 'integer'], 'name' => Schema::nullable('string', 'Null once the account is gone.')], ['id', 'name']);
        $thread = [
            'id'             => ['type' => 'integer'],
            'listing_id'     => ['type' => 'integer'],
            'listing_title'  => Schema::nullable('string', 'Null once the listing is gone.'),
            'buyer'          => $person,
            'seller'         => $person,
            'state'          => ['type' => 'string', 'enum' => self::STATES],
            'buyer_unread'   => ['type' => 'integer'],
            'seller_unread'  => ['type' => 'integer'],
            'buyer_blocked'  => ['type' => 'boolean'],
            'seller_blocked' => ['type' => 'boolean'],
            'report'         => Schema::nullable('object', 'Who reported the thread and why; null when it is not reported.') + [
                'properties' => ['user_id' => ['type' => 'integer'], 'reason' => ['type' => 'string', 'enum' => MessagePolicy::REPORT_REASONS]],
            ],
            'last_message_at' => Schema::time(),
            'created_at'      => Schema::time(),
        ];
        $required = array_keys($thread);
        $message  = [
            'id'              => ['type' => 'integer'],
            'conversation_id' => ['type' => 'integer'],
            'listing_id'      => ['type' => 'integer'],
            'sender_id'       => ['type' => 'integer'],
            'sender'          => $person,
            'body'            => ['type' => 'string'],
            'held'            => ['type' => 'boolean'],
            'state'           => ['type' => 'string', 'enum' => self::MESSAGE_STATES],
            'created_at'      => Schema::time(),
        ];

        return [
            'AdminConversation'         => Schema::object($thread, $required),
            'AdminConversationDocument' => Schema::object([
                'data' => Schema::object($thread + ['messages' => ['type' => 'array', 'items' => Schema::ref('AdminMessage'), 'description' => 'Every message, held ones included, oldest first.']], [...$required, 'messages']),
            ], ['data']),
            'AdminConversationPage' => Schema::wholeList('AdminConversation'),
            'AdminMessage'          => Schema::object($message, array_keys($message)),
            'AdminMessageDocument'  => $doc('AdminMessage'),
            'AdminMessagePage'      => Schema::wholeList('AdminMessage'),
        ];
    }
}
