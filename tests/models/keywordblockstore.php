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

/**
 * KeywordBlockStore, the writes the keyword blocklist screen makes.
 *
 * Usage:  php tests/models/keywordblockstore.php      (standalone, own scratch database)
 *         php tests/run-models.php keywordblockstore  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\moderation\KeywordBlockStore;

$admin = scratchdb_session('osc_models_keywordblockstore');
$table = DB_TABLE_PREFIX . 't_keyword_block';

$row = static fn (int $id): ?array => $admin->query("SELECT * FROM $table WHERE pk_i_id = $id")->fetch_assoc();

harness_section('Save');

$id = KeywordBlockStore::save(null, 'spam', 'title', true);
check('an add returns the new id', $id > 0);
pin('...and writes the row', array('spam', 'title', '1'), array($row($id)['s_keyword'], $row($id)['s_scope'], (string) $row($id)['b_substring']));
check('...dated now', abs(strtotime((string) $row($id)['dt_date']) - time()) < 60);

pin('an edit returns the same id', $id, KeywordBlockStore::save($id, 'scam', 'all', false));
pin('...and rewrites the row', array('scam', 'all', '0'), array($row($id)['s_keyword'], $row($id)['s_scope'], (string) $row($id)['b_substring']));
pin('an unchanged edit is not an error', $id, KeywordBlockStore::save($id, 'scam', 'all', false));

harness_section('Delete');

$other = KeywordBlockStore::save(null, 'junk', 'meta', false);
check('a delete reports the row went', KeywordBlockStore::delete($id));
pin('...and only that row', array(null, 'junk'), array($row($id), $row($other)['s_keyword']));
check('an unknown id deletes nothing', !KeywordBlockStore::delete(999999));

$controller = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsKeywordBlock.php');
check(
    'the keyword screen writes only through KeywordBlockStore',
    !str_contains($controller, '->update(') && !str_contains($controller, '->insert(') && !str_contains($controller, 'deleteByPrimaryKey(')
);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
