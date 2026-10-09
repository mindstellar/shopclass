<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the public delete_comment action. It used to call add_comment() on every delete,
 * so a delete request that also carried comment fields inserted a comment. CommentService
 * has to fire `delete_comment` with the comment id, as an int, after the delete, as the admin delete does.
 *
 * DB-free and source-level.  Usage: php tests/item-comment-delete.php
 */

require_once __DIR__ . '/lib/harness.php';

$body = harness_method_source(__DIR__ . '/../oc-includes/osclass/classes/controller/CWebItem.php', 'deleteComment');

harness_section('delete_comment');
check('the case was parsed', $body !== '');
check('it checks CSRF', strpos($body, 'osc_csrf_check()') !== false);
check('it inserts nothing: no add_comment() call', $body !== '' && strpos($body, 'add_comment') === false);
check('it builds no ItemActions', $body !== '' && strpos($body, 'ItemActions') === false);
check('it reads the comment id as an int', strpos($body, "\$commentId = Params::getParamInt('comment')") !== false);
check('it deletes through CommentService', strpos($body, '(new CommentService())->delete($commentId') !== false);

$service = file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/comment/CommentService.php');
preg_match('/public function delete\(int \$commentId.*?\n    }\n/s', $service, $d);
$delete = $d[0] ?? '';

harness_section('the delete_comment hook');
$row  = strpos($delete, 'deleteByPrimaryKey($commentId)');
$hook = strpos($delete, "osc_run_hook('delete_comment', \$commentId)");
check('the service delete was parsed', $delete !== '');
check('it fires delete_comment with the comment id', $hook !== false);
check('the hook fires after the delete', $row !== false && $hook !== false && $hook > $row);

$admin = file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/moderation/CommentModeration.php');
check('the admin delete still fires it too', (bool) preg_match("/osc_run_hook\\('delete_comment', \\\$/", $admin));

harness_section('who may delete');
check('a signed-out visitor is refused', strpos($delete, '$actor->userId() === null') !== false);
check('only an active comment', strpos($delete, "(int) \$comment['b_active'] !== 1") !== false);
check('only the comment\'s own author', strpos($delete, 'CommentPolicy::isAuthor($comment, $actor)') !== false);

exit(harness_result());
