<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\migration\MigrationInterface;

/**
 * User-avatar preferences.
 *
 * Seeds the two preferences the avatar feature reads: enabled_user_avatars (the
 * on/off switch) and avatar_dimensions (the stored size of the main avatar
 * image). Both are INSERT IGNORE under the osclass section, so the step is
 * idempotent and never overwrites a value an operator has already set. The same
 * preferences are declared in installer/basic_data.sql for a fresh install,
 * which the runner baselines rather than replays; this migration brings an
 * existing install up to the same state.
 */
return new class implements MigrationInterface {
    /** Section the core avatar preferences live under. */
    private const SECTION = 'osclass';

    /**
     * key => [default value, e_type]. Mirrors installer/basic_data.sql.
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const PREFERENCES = array(
        'enabled_user_avatars' => array('1', 'BOOLEAN'),
        'avatar_dimensions'    => array('200x200', 'STRING'),
    );

    public function up(DBCommandClass $comm): void
    {
        $table = DB_TABLE_PREFIX . 't_preference';

        foreach (self::PREFERENCES as $name => $spec) {
            [$default, $type] = $spec;

            $sql = 'INSERT IGNORE INTO ' . $table
                . ' (s_section, s_name, s_value, e_type) VALUES ('
                . $comm->escape(self::SECTION) . ', '
                . $comm->escape($name) . ', '
                . $comm->escape($default) . ', '
                . $comm->escape($type) . ')';

            if ($comm->query($sql) === false) {
                throw new RuntimeException('user avatars migration: failed to seed ' . $name);
            }
        }
    }
};
