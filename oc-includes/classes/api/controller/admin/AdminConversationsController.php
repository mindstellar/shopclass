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

namespace mindstellar\api\controller\admin;

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Pager;
use mindstellar\api\Response;
use mindstellar\api\serializer\ConversationSerializer;
use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\listing\ListingStore;
use mindstellar\moderation\MessageModeration;

/**
 * `/admin/conversations` and `/admin/messages`: the review screen's list of held messages and
 * reported threads, and its four actions, through the same MessageModeration. No admin reply and
 * no edit, as the screen has none.
 */
final class AdminConversationsController
{
    /** The most messages one thread document carries. */
    private const THREAD_LIMIT = 1000;

    private MessageModeration $moderation;

    private ConversationSerializer $serializer;

    public function __construct(private ApiServices $api)
    {
        $this->moderation = Container::root()->get(MessageModeration::class);
        $this->serializer = new ConversationSerializer();
    }

    public function index(ApiCall $call): Response
    {
        $request = $call->request();
        $actor   = $call->actor('admin:conversations');
        $pager   = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId(), 'admin/conversations');
        $filters = ['user' => $request->queryIds('user'), 'listing' => $request->queryIds('listing')];
        if ($request->queryString('status') !== '') {
            $filters['status'] = $request->queryString('status');
        }
        $filters = array_filter($filters, static fn ($value): bool => $value !== []);

        return $pager->respond(
            fn (): array => $this->moderation->conversations($filters, $pager->limit() + 1, $pager->afterId(), $actor),
            null,
            fn (array $rows): array => $this->threads($rows),
            $this->api->links()
        );
    }

    public function show(ApiCall $call): Response
    {
        $thread   = $this->moderation->thread($call->intArg(), $this->actor($call), self::THREAD_LIMIT);
        $row      = $thread['conversation'];
        $listing  = (int) $row['fk_i_item_id'];
        $document = $this->serializer->admin($row, $thread['names'], ListingStore::titles([$listing]));
        $document['messages'] = array_map(
            fn (array $message): array => $this->serializer->adminMessage($message, $listing, $thread['names'][(int) $message['fk_i_sender_id']] ?? null),
            $thread['messages']
        );

        return Response::ok($document);
    }

    public function close(ApiCall $call): Response
    {
        $this->moderation->close($call->intArg(), $this->actor($call));

        return $this->show($call);
    }

    public function clearReport(ApiCall $call): Response
    {
        $this->moderation->clearReport($call->intArg(), $this->actor($call));

        return $this->show($call);
    }

    public function messages(ApiCall $call): Response
    {
        $pager = Pager::fromRequest($call->request(), $this->api->cursor(), ListSpec::byId(), 'admin/messages');

        return $pager->respond(
            fn (): array => $this->moderation->held($this->actor($call), $pager->limit() + 1, $pager->afterId()),
            null,
            fn (array $rows): array => array_map(fn (array $row): array => $this->messageView($row), $rows),
            $this->api->links()
        );
    }

    public function message(ApiCall $call): Response
    {
        $row = ProblemException::found($this->moderation->message($call->intArg(), $this->actor($call)), 'message');

        return Response::ok($this->messageView($row));
    }

    public function release(ApiCall $call): Response
    {
        $this->moderation->release($call->intArg(), $this->actor($call));

        return $this->message($call);
    }

    public function remove(ApiCall $call): Response
    {
        $this->moderation->remove($call->intArg(), $this->actor($call));

        return $this->message($call);
    }

    /**
     * @param array<string,mixed> $row a message row with `conversation` and `sender_name`
     *
     * @return array<string,mixed>
     */
    private function messageView(array $row): array
    {
        return $this->serializer->adminMessage($row, (int) ($row['conversation']['fk_i_item_id'] ?? 0), $row['sender_name'] ?? null);
    }

    private function actor(ApiCall $call): Actor
    {
        return $call->actor('admin:conversations');
    }

    /**
     * @param array<int,array<string,mixed>> $rows threads with buyer_name and seller_name
     *
     * @return array<int,array<string,mixed>>
     */
    private function threads(array $rows): array
    {
        $titles = ListingStore::titles(array_map('intval', array_column($rows, 'fk_i_item_id')));

        return array_map(fn (array $row): array => $this->serializer->admin($row, [
            (int) $row['fk_i_buyer_id']  => (string) $row['buyer_name'],
            (int) $row['fk_i_seller_id'] => (string) $row['seller_name'],
        ], $titles), $rows);
    }
}
