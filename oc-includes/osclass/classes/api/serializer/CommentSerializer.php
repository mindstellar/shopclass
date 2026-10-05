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

namespace mindstellar\api\serializer;

use mindstellar\api\read\CommentStatus;

/**
 * A listing comment. The author's e-mail is never sent, except to moderators (admin()).
 */
final class CommentSerializer
{
    /**
     * @param array<string,mixed> $row a t_item_comment row
     *
     * @return array<string,mixed>
     */
    public function one(array $row): array
    {
        return [
            'id'           => Format::int($row['pk_i_id'] ?? 0),
            'listing_id'   => Format::int($row['fk_i_item_id'] ?? 0),
            'title'        => Format::text($row['s_title'] ?? null),
            'body'         => (string) ($row['s_body'] ?? ''),
            'author'       => [
                'name'    => Format::text($row['s_author_name'] ?? null),
                'user_id' => Format::id($row['fk_i_user_id'] ?? null),
            ],
            'published_at' => Format::time($row['dt_pub_date'] ?? null),
        ];
    }

    /**
     * A comment as moderators see it: its status and the author's e-mail too.
     *
     * @param array<string,mixed> $row a t_item_comment row
     *
     * @return array<string,mixed>
     */
    public function admin(array $row): array
    {
        $out = $this->one($row);

        return [
            'id'           => $out['id'],
            'listing_id'   => $out['listing_id'],
            'status'       => CommentStatus::of($row),
            'title'        => $out['title'],
            'body'         => $out['body'],
            'author'       => [
                'name'    => $out['author']['name'],
                'email'   => Format::text($row['s_author_email'] ?? null),
                'user_id' => $out['author']['user_id'],
            ],
            'published_at' => $out['published_at'],
        ];
    }
}
