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

namespace mindstellar\api\read;

use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\Links;

/**
 * Paging for one list request: sort, order, limit and where the cursor left off. Every list
 * endpoint pages through this, by keyset or by offset as Cursor::modeFor() picks, and asks
 * for one row more than the page so the next page is known without another query.
 */
final class Pager
{
    private function __construct(private ListSpec $spec, private Cursor $cursor, private int $limit, private string $hash, private ?CursorState $state, private bool $count)
    {
    }

    /**
     * @param array<string,mixed> $filters what the list is filtered by; a cursor works only for these
     *
     * @throws ProblemException 422 for a limit out of range, 400 for a cursor this request cannot use
     */
    public static function fromRequest(Request $request, Cursor $cursor, ListSpec $spec, array $filters): self
    {
        $limit = $request->queryInt('limit', $spec->defaultLimit());
        if ($limit < 1 || $limit > $spec->maxLimit()) {
            throw ProblemException::from(Problem::validation([
                ['pointer' => '/limit', 'code' => 'maximum', 'message' => 'must be between 1 and ' . $spec->maxLimit(), 'in' => 'query'],
            ]));
        }
        unset($filters['sort'], $filters['order'], $filters['cursor'], $filters['limit']);
        $hash  = Cursor::filterHash($filters + ['sort' => $spec->sort(), 'order' => $spec->direction()]);
        $state = null;
        $raw   = $request->queryString('cursor');
        if ($raw !== '') {
            $state = $cursor->decode($raw, $hash, $spec->sorts(), $spec->maxOffset());
            $fits  = $state !== null && $state->sort() === $spec->sort() && $state->direction() === $spec->direction()
                && ($state->kind() !== CursorState::KEYSET || $spec->keysetFits($state->after()));
            if (!$fits) {
                throw ProblemException::of('invalid_cursor', 'Follow links.next with the same filters it was made for.');
            }
        }

        return new self($spec, $cursor, $limit, $hash, $state, $request->queryBool('count'));
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * The row id a by-id cursor left off at, or null on the first page.
     */
    public function afterId(): ?int
    {
        $after = $this->after();

        return $after === null ? null : (int) $after[0];
    }

    /**
     * This page as the answer, with its next link and, when asked for, its total.
     *
     * @param callable(): array<int,array<string,mixed>>          $fetch reads up to limit() + 1 rows from where the cursor left off
     * @param (callable(): ?int)|null                             $total counts every match; called only when counts(), after $fetch
     * @param callable(array<int,array<string,mixed>>): array<int,mixed> $shape this page's rows as the answer's data
     * @param array<string,mixed>                                 $query the request's query, for the links
     */
    public function respond(callable $fetch, ?callable $total, callable $shape, Links $links, string $path, array $query): Response
    {
        $rows  = $fetch();
        $count = $total !== null && $this->counts() ? $total() : null;

        return (new Page($shape($this->page($rows)), $count, $this->limit, $this->next($rows), $this->truncated($rows)))->response($links, $path, $query);
    }

    public function offset(): int
    {
        return $this->state !== null && $this->state->kind() === CursorState::OFFSET ? $this->state->offsetValue() : 0;
    }

    /**
     * Where a keyset cursor left off, or null on the first page and for offset paging.
     *
     * @return array<int,int|string>|null
     */
    public function after(): ?array
    {
        return $this->state !== null && $this->state->kind() === CursorState::KEYSET ? $this->state->after() : null;
    }

    /**
     * Whether to count the total: only when the caller sent `count=true`, and not on a keyset
     * page after the first, which would count only what is left.
     */
    public function counts(): bool
    {
        return $this->count && $this->after() === null;
    }

    /**
     * The rows of this page, without the look-ahead row.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    public function page(array $rows): array
    {
        return array_slice(array_values($rows), 0, $this->limit);
    }

    /**
     * The cursor for the next page, or null on the last one.
     *
     * @param array<int,array<string,mixed>> $rows what the query returned, up to limit + 1
     */
    public function next(array $rows): ?string
    {
        if (count($rows) <= $this->limit) {
            return null;
        }
        $sort      = $this->spec->sort();
        $direction = $this->spec->direction();
        if (Cursor::modeFor($sort) === CursorState::KEYSET) {
            return $this->cursor->encode(CursorState::keyset($sort, $direction, $this->hash, $this->spec->keyset(array_values($rows)[$this->limit - 1])));
        }
        $offset = $this->offset() + $this->limit;

        return $offset > $this->spec->maxOffset() ? null : $this->cursor->encode(CursorState::offset($sort, $direction, $this->hash, $offset));
    }

    /**
     * Whether more rows exist but offset paging stops here, so there is no next page.
     *
     * @param array<int,array<string,mixed>> $rows what the query returned, up to limit + 1
     */
    public function truncated(array $rows): bool
    {
        return count($rows) > $this->limit && Cursor::modeFor($this->spec->sort()) !== CursorState::KEYSET
            && $this->offset() + $this->limit > $this->spec->maxOffset();
    }
}
