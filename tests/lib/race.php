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
 * Race helper: makes a lock clash with a read view happen on purpose.
 *
 * MariaDB 11.6+ (innodb_snapshot_isolation ON) fails a locking read or write with error 1020
 * when the row changed after the transaction's first plain SELECT. To test a code path:
 *   1. race_hold() locks the contended row on a connection of the test and keeps the lock.
 *   2. race_run() starts the code path in a child PHP process (a script that prints a result).
 *   3. race_wait_for() polls information_schema.PROCESSLIST until the children wait on the lock.
 *   4. race_release() commits the held lock and returns what the children printed.
 * Code that reads before it locks has its view open by the time it waits, so the commit makes it
 * fail with 1020 (the child prints "threw"). Code that locks first waits with no view and succeeds.
 * On MySQL and on MariaDB with the setting OFF the same test passes either way, so the guard only
 * bites on MariaDB 11.6+; race_snapshot_isolation() tells a test which case it is in.
 *
 * race() does all four steps in one call. Needs the constants set by scratchdb_bootstrap().
 */

if (!function_exists('race_hold')) {
    /**
     * Open a transaction on $conn and take the lock, keeping it until race_release().
     *
     * @param mysqli $conn    Connection of the test
     * @param string $lockSql Locking statement, such as "SELECT ... FOR UPDATE" or an UPDATE
     */
    function race_hold(mysqli $conn, string $lockSql): void
    {
        $conn->begin_transaction();
        $conn->query($lockSql);
    }

    /**
     * Start a child PHP process. The child gets the database name first, then $args.
     *
     * @param string   $script Path of the child script
     * @param string[] $args   Arguments after the database name
     * @param string   $db     Database the child works in
     *
     * @return array{proc: resource, out: resource, err: resource}
     */
    function race_run(string $script, string $db, array $args = []): array
    {
        $proc = proc_open(
            array_merge([PHP_BINARY, '-d', 'display_errors=stderr', $script, $db], array_map('strval', $args)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        return ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
    }

    /**
     * Wait until $count queries matching $like (an SQL LIKE pattern on the statement text) run in
     * $db, or the tries run out. A statement blocked on a lock shows as COMMAND = 'Query'.
     *
     * @return int How many were waiting on the last look
     */
    function race_wait_for(string $db, string $like, int $count = 1, int $tries = 200): int
    {
        $watch   = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $db, (int) ini_get('mysqli.default_port'));
        $stmt    = $watch->prepare(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND DB = ? AND COMMAND = 'Query' AND INFO LIKE ?"
        );
        $waiting = 0;
        for ($try = 0; $try < $tries && $waiting < $count; $try++) {
            usleep(50000);
            $stmt->bind_param('ss', $db, $like);
            $stmt->execute();
            $waiting = (int) $stmt->get_result()->fetch_row()[0];
        }
        $watch->close();

        return $waiting;
    }

    /**
     * Commit the held lock, then collect what each child printed (stdout and stderr) and close it.
     *
     * @param mysqli $conn     Connection passed to race_hold()
     * @param array  $children Handles from race_run()
     *
     * @return string[] Output of each child, same keys as $children
     */
    function race_release(mysqli $conn, array $children): array
    {
        $conn->commit();
        $out = [];
        foreach ($children as $i => $child) {
            $out[$i] = stream_get_contents($child['out']) . stream_get_contents($child['err']);
            proc_close($child['proc']);
        }

        return $out;
    }

    /**
     * Hold a lock, run the children against it, wait until all of them wait, then release.
     *
     * @param mysqli   $conn     Connection of the test
     * @param string   $lockSql  Locking statement for race_hold()
     * @param string   $db       Database the children work in
     * @param array    $runs     List of [script, args] pairs, one child each
     * @param string   $waitLike LIKE pattern of the statement the children wait in
     *
     * @return array{0: int, 1: string[]} How many children waited, and each child's output
     */
    function race(mysqli $conn, string $lockSql, string $db, array $runs, string $waitLike): array
    {
        race_hold($conn, $lockSql);
        $children = [];
        foreach ($runs as $i => [$script, $args]) {
            $children[$i] = race_run($script, $db, $args);
        }
        $waiting = race_wait_for($db, $waitLike, count($runs));
        $out     = race_release($conn, $children);

        return [$waiting, $out];
    }

    /**
     * Whether another connection could lock the index rows of a listing now, within a second.
     * False while a writer holds them; a test checks the writer's lock order with it.
     */
    function race_facet_free(string $db, int $itemId): bool
    {
        $probe = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $db, (int) ini_get('mysqli.default_port'));
        $probe->query('SET SESSION innodb_lock_wait_timeout = 1');
        $probe->begin_transaction();
        try {
            $probe->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_item_facet WHERE fk_i_item_id = ' . $itemId);
            $free = true;
        } catch (mysqli_sql_exception $e) {
            $free = false;
        }
        $probe->rollback();
        $probe->close();

        return $free;
    }

    /**
     * Whether the server fails a stale locking read with error 1020 (MariaDB 11.6+, setting ON).
     */
    function race_snapshot_isolation(mysqli $conn): bool
    {
        $res = $conn->query("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'");
        $row = $res ? $res->fetch_row() : null;

        return $row !== null && strtoupper((string) $row[1]) === 'ON';
    }
}
