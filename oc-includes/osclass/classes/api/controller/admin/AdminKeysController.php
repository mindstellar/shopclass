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

use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\KeySerializer;
use mindstellar\api\serializer\Links;
use mindstellar\apiaccess\ApiKeyService;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;

/**
 * `/admin/keys`: the keys of Settings -> API, through the same ApiKeyService. A key made
 * here belongs to the calling key's admin and cannot hold a scope the calling key lacks;
 * its token is shown once.
 */
final class AdminKeysController
{
    private KeySerializer $serializer;

    private ApiKeyService $keys;
    private Links $links;

    public function __construct(private ApiServices $api)
    {
        $this->keys = $api->keyService();
        $this->links = $api->links();
        $this->serializer = new KeySerializer();
    }

    /**
     * @param array<string,string> $args
     */
    public function index(Request $request, Credential $credential, array $args): Response
    {
        return Response::collection(array_map([$this->serializer, 'admin'], $this->keys->rows()));
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok($this->serializer->admin($this->key((int) $args['id'])));
    }

    /**
     * POST /admin/keys
     *
     * @param array<string,string> $args
     */
    public function create(Request $request, Credential $credential, array $args): Response
    {
        $input  = $request->input();
        $kind   = ($input['kind'] ?? 'admin') === 'public' ? CredentialKind::PUBLIC : CredentialKind::KEY;
        $scopes = array_values(array_map('strval', (array) ($input['scopes'] ?? [])));
        if ($scopes === [] && $kind === CredentialKind::PUBLIC) {
            $scopes = [Scopes::PUBLIC_READ];
        }
        self::checkGrant($credential, $scopes);
        $issued = $this->keys->create(
            KeyOwner::admin((int) $credential->adminId(), $credential->isModerator()),
            (string) $input['name'],
            $kind,
            $scopes,
            (string) ($input['expires_at'] ?? '')
        );

        return Response::created($this->serializer->admin($this->key($issued->id()), $issued->token()), $this->links->api('admin/keys/' . $issued->id()));
    }

    /**
     * DELETE /admin/keys/{id}
     *
     * @param array<string,string> $args
     */
    public function revoke(Request $request, Credential $credential, array $args): Response
    {
        $id = (int) $this->key((int) $args['id'])['id'];
        $this->keys->revoke($id);

        return Response::noContent();
    }

    /**
     * POST /admin/keys/{id}/rotate: a new key with the old one's name, scopes and expiry.
     * The old key works until it is revoked.
     *
     * @param array<string,string> $args
     */
    public function rotate(Request $request, Credential $credential, array $args): Response
    {
        $old = $this->key((int) $args['id']);
        if ($old['kind'] !== 'public' && $old['owner_admin'] !== $credential->adminId()) {
            throw ProblemException::of('forbidden', 'Only your own keys and public keys can be rotated. Revoke this one and make a new key instead.');
        }
        self::checkGrant($credential, $old['scopes']);
        $issued = $this->keys->rotate((int) $old['id'], (int) $credential->adminId());

        return Response::created($this->serializer->admin($this->key($issued->id()), $issued->token()), $this->links->api('admin/keys/' . $issued->id()));
    }

    /**
     * A key may only pass on scopes the calling key holds.
     *
     * @param string[] $scopes
     *
     * @throws ProblemException 403
     */
    private static function checkGrant(Credential $credential, array $scopes): void
    {
        $refused = array_values(array_filter($scopes, static fn (string $scope): bool => !$credential->has($scope)));
        if ($refused !== []) {
            throw ProblemException::of('forbidden', 'This key cannot grant scopes it does not hold: ' . implode(', ', $refused) . '.');
        }
    }

    /**
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function key(int $id): array
    {
        $row = $this->keys->row($id);
        if ($row === null) {
            throw ProblemException::of('not_found', 'No such key.');
        }

        return $row;
    }
}
