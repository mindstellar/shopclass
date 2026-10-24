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

namespace mindstellar\api\controller;

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Page;
use mindstellar\api\read\Pager;
use mindstellar\api\Response;
use mindstellar\api\serializer\ConversationSerializer;
use mindstellar\api\serializer\Format;
use mindstellar\api\Warning;
use mindstellar\container\Container;
use mindstellar\listing\ListingStore;
use mindstellar\messaging\MessagingService;
use mindstellar\messaging\SentMessage;
use mindstellar\user\UserStore;

/**
 * The signed-in user's conversations at `/account/conversations`. Every route goes through
 * MessagingService with the caller as the actor, as the messages pages do, so a thread the caller
 * is not part of, or a held one for its seller, is a 404.
 */
final class ConversationsController
{
    /** The page size of a message list that names none. */
    public const MESSAGES_LIMIT = 50;

    private const DATETIME = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D';

    private MessagingService $service;

    private ConversationSerializer $serializer;

    public function __construct(private ApiServices $api)
    {
        $this->service    = Container::root()->get(MessagingService::class);
        $this->serializer = new ConversationSerializer();
    }

    /**
     * `GET account/conversations`: newest activity first, keyed on the last message time and the id.
     */
    public function index(ApiCall $call): Response
    {
        $actor  = $call->actor();
        $userId = $call->userId();
        $spec   = new ListSpec(
            ['activity'],
            'activity',
            'desc',
            ListSpec::DEFAULT_LIMIT,
            ListSpec::MAX_LIMIT,
            static fn (array $after): bool => count($after) === 2 && is_string($after[0]) && preg_match(self::DATETIME, $after[0]) === 1 && is_int($after[1]),
            static fn (array $row): array => [(string) $row['dt_last_message'], (int) $row['pk_i_id']]
        );
        $pager    = Pager::fromRequest($call->request(), $this->api->cursor(), $spec, 'account/conversations');
        $after    = $pager->after();
        $activity = $this->service->activity($userId);

        return $pager->respond(
            fn (): array => $this->service->inbox($actor, $pager->limit() + 1, $after === null ? null : (string) $after[0], $after === null ? null : (int) $after[1]),
            null,
            fn (array $rows): array => $this->conversations($rows, $userId),
            $this->api->links(),
            ['unread' => $activity['unread'], 'latest_at' => Format::time($activity['latest'])]
        );
    }

    /**
     * `POST account/conversations`: write to a listing's seller. A second message about the same
     * listing goes to the thread already there, and Location names it.
     */
    public function create(ApiCall $call): Response
    {
        $input = $call->input();
        $sent  = $this->service->start((int) $input['listing'], (string) $input['body'], $call->actor());

        return $this->sent($call, $sent);
    }

    public function show(ApiCall $call): Response
    {
        return Response::ok($this->document($call));
    }

    /**
     * `GET account/conversations/{id}/messages`: newest first by cursor, or with `after` the
     * messages after that id, oldest first, for a client that polls. Reading marks nothing read.
     */
    public function messages(ApiCall $call): Response
    {
        $id      = $call->intArg();
        $userId  = $call->userId();
        $request = $call->request();
        $path    = 'account/conversations/' . $id . '/messages';
        $spec    = ListSpec::byId('desc', self::MESSAGES_LIMIT);

        if ($request->queryString('after') !== '') {
            $limit = $request->queryInt('limit', $spec->defaultLimit());
            if ($limit < 1 || $limit > $spec->maxLimit()) {
                throw ProblemException::field('/limit', 'maximum', 'must be between 1 and ' . $spec->maxLimit(), 'query');
            }
            $rows = $this->service->since($id, $request->queryInt('after'), $call->actor(), $limit, false)['messages'];

            return (new Page($this->serializer->messages($rows, $userId), null, $limit, null))->response($this->api->links(), $path, $request->query(), $request->version());
        }

        $pager = Pager::fromRequest($request, $this->api->cursor(), $spec, $path);
        $actor = $call->actor();

        return $pager->respond(
            fn (): array => array_reverse($this->service->thread($id, $actor, $pager->limit() + 1, $pager->afterId())['messages']),
            null,
            fn (array $rows): array => $this->serializer->messages($rows, $userId),
            $this->api->links()
        );
    }

    /**
     * `POST account/conversations/{id}/messages`: reply in the thread.
     */
    public function send(ApiCall $call): Response
    {
        $sent = $this->service->reply($call->intArg(), (string) $call->input()['body'], $call->actor());

        return $this->sent($call, $sent);
    }

    public function read(ApiCall $call): Response
    {
        $this->service->markRead($call->intArg(), $call->actor());

        return $this->show($call);
    }

    public function block(ApiCall $call): Response
    {
        $this->service->block($call->intArg(), $call->actor());

        return $this->show($call);
    }

    public function unblock(ApiCall $call): Response
    {
        $this->service->unblock($call->intArg(), $call->actor());

        return $this->show($call);
    }

    public function report(ApiCall $call): Response
    {
        $this->service->report($call->intArg(), (string) $call->input()['reason'], $call->actor());

        return $this->show($call);
    }

    /**
     * The 201 for a message just written, with the `message_held` warning while it waits for review.
     */
    private function sent(ApiCall $call, SentMessage $sent): Response
    {
        $warnings = Warning::member($sent->held() ? [Warning::MESSAGE_HELD => 'The message shows to the other person once it is reviewed.'] : []);

        return $this->api->created($call, $this->serializer->sent($sent), 'account/conversations/' . $sent->conversationId(), $warnings);
    }

    /**
     * The caller's view of the thread named in the path.
     *
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function document(ApiCall $call): array
    {
        $row = ProblemException::found($this->service->find($call->intArg(), $call->actor()), 'conversation');

        return $this->conversations([$row], $call->userId())[0];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function conversations(array $rows, int $userId): array
    {
        $people = [];
        foreach ($rows as $row) {
            $people[] = (int) $row['fk_i_buyer_id'];
            $people[] = (int) $row['fk_i_seller_id'];
        }
        $names  = UserStore::displayNames($people);
        $titles = ListingStore::titles(array_map('intval', array_column($rows, 'fk_i_item_id')));

        return array_map(fn (array $row): array => $this->serializer->one($row, $userId, $names, $titles), $rows);
    }
}
