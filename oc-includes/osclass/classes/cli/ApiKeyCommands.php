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

namespace mindstellar\cli;

use InvalidArgumentException;
use mindstellar\apiaccess\ApiAccess;
use mindstellar\apiaccess\ApiKeyService;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\KeyOwner;

/**
 * The api:key:* commands. Shell access is the authority here, so no password is asked; the
 * key is printed once and never again.
 */
final class ApiKeyCommands
{
    /** @var callable fn(string $text): void */
    private $out;

    /** @var callable fn(string $text): void */
    private $err;

    private ?ApiKeyService $keys;

    /**
     * @param callable $out fn(string $text): void
     * @param callable $err fn(string $text): void
     */
    public function __construct(callable $out, callable $err, ?ApiKeyService $keys = null)
    {
        $this->out  = $out;
        $this->err  = $err;
        $this->keys = $keys;
    }

    /**
     * api:key:create --admin=<username>|id:<n> --name=<label> [--kind=admin|public]
     * [--scopes=a,b] [--expires=YYYY-MM-DD|<days>d]
     *
     * @param array<string,mixed> $args
     */
    public function create(array $args): int
    {
        $who  = trim((string) ($args['admin'] ?? ''));
        $kind = (string) ($args['kind'] ?? 'admin');
        if ($who === '' || !is_string($args['name'] ?? null) || !in_array($kind, ['admin', 'public'], true)) {
            ($this->err)("Usage: api:key:create --admin=<username>|id:<n> --name=<label> [--kind=admin|public] [--scopes=listings:read,admin:listings] [--expires=YYYY-MM-DD|90d]\n");

            return 2;
        }
        $admin = self::findAdmin($who);
        if ($admin === null) {
            ($this->err)(sprintf("No admin '%s'.\n", $who));

            return 1;
        }
        $scopes = is_string($args['scopes'] ?? null) ? (preg_split('/[\s,]+/', trim($args['scopes']), -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];

        try {
            $issued = $this->keys()->create(
                KeyOwner::admin((int) $admin['pk_i_id'], (int) $admin['b_moderator'] === 1),
                (string) $args['name'],
                $kind === 'public' ? CredentialKind::PUBLIC : CredentialKind::KEY,
                $scopes,
                is_string($args['expires'] ?? null) ? $args['expires'] : ''
            );
        } catch (InvalidArgumentException $e) {
            ($this->err)($e->getMessage() . "\n");

            return 1;
        }

        ($this->out)(sprintf("Key %d made for admin '%s' (%s).\n", $issued->id(), $admin['s_username'], implode(' ', $issued->scopes())));
        ($this->out)($issued->token() . "\n");
        ($this->out)("Copy it now: it is not shown again.\n");

        return 0;
    }

    /**
     * api:key:list
     *
     * @param array<string,mixed> $args
     */
    public function list(array $args): int
    {
        $rows = $this->keys()->rows();
        if ($rows === []) {
            ($this->out)("No API keys.\n");

            return 0;
        }
        $date = static fn (?int $t): string => $t === null ? '-' : date('Y-m-d H:i', $t);
        $line = "%-5s %-7s %-9s %-24s %-16s %-16s %-16s %s\n";
        ($this->out)(sprintf($line, 'ID', 'TYPE', 'STATUS', 'NAME', 'OWNER', 'LAST USED', 'EXPIRES', 'SCOPES'));
        foreach ($rows as $row) {
            ($this->out)(sprintf(
                $line,
                $row['id'],
                $row['kind'],
                $row['status'],
                mb_strimwidth($row['name'], 0, 24, '…'),
                mb_strimwidth($row['owner'] !== '' ? $row['owner'] : '-', 0, 16, '…'),
                $date($row['last_used']),
                $date($row['expires']),
                implode(' ', $row['scopes'])
            ));
        }

        return 0;
    }

    /**
     * api:key:revoke <id>
     *
     * @param array<string,mixed> $args
     */
    public function revoke(array $args): int
    {
        $id = (string) ($args['_'][0] ?? '');
        if (!ctype_digit($id)) {
            ($this->err)("Usage: api:key:revoke <id>\n");

            return 2;
        }
        try {
            $this->keys()->revoke((int) $id);
        } catch (InvalidArgumentException $e) {
            ($this->err)($e->getMessage() . "\n");

            return 1;
        }
        ($this->out)(sprintf("Key %s revoked.\n", $id));

        return 0;
    }

    private function keys(): ApiKeyService
    {
        return $this->keys ??= ApiAccess::site()->keyService();
    }

    /**
     * The admin named as `id:<n>` or by username. A bare number is a username, so an admin
     * called "7" can never be mistaken for admin 7.
     *
     * @return array{pk_i_id:int|string,s_username:string,b_moderator:int|string}|null
     */
    private static function findAdmin(string $who): ?array
    {
        $byId = preg_match('/^id:(\d+)$/D', $who, $m) === 1;

        return osc_db_select_one(
            'SELECT pk_i_id, s_username, b_moderator FROM ' . DB_TABLE_PREFIX . 't_admin WHERE ' . ($byId ? 'pk_i_id' : 's_username') . ' = ?',
            [$byId ? (int) $m[1] : $who]
        );
    }
}
