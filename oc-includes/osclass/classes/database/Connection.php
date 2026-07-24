<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\database;

use mysqli;
use Throwable;

/**
 * Class Connection
 *
 * Injection-safe query API over the singleton mysqli connection managed by
 * DBConnectionClass. New code and plugins should use this instead of building
 * SQL strings by hand: every value in $params is sent as a bound parameter, so
 * it can never be interpreted as SQL.
 *
 * $params bind VALUES only. Table/column names can never be bound parameters —
 * validate any dynamic identifier against an allowlist before interpolating it
 * into $sql.
 *
 * This sits BESIDE the legacy DBCommandClass, which is untouched.
 *
 * @package mindstellar\database
 */
class Connection
{
    /**
     * Shared instance.
     *
     * @var Connection|null
     */
    private static $shared;

    /**
     * The underlying mysqli connection.
     *
     * @var mysqli
     */
    private $conn;

    /**
     * Bind to a specific mysqli handle, or to the shared Shopclass one when none
     * is given. Passing a handle is what lets a caller work against a database
     * other than the configured one -- the upgrade tooling builds scratch
     * databases and drives them through the same code paths.
     *
     * @param \mysqli|null $conn
     *
     * @throws DbException when no mysqli connection is available
     */
    public function __construct(?\mysqli $conn = null)
    {
        if ($conn === null) {
            $conn = \DBConnectionClass::newInstance()->getOsclassDb();
        }
        if (!$conn instanceof mysqli) {
            throw new DbException('No database connection available');
        }
        $this->conn = $conn;
    }

    /**
     * Shared Connection instance bound to the singleton mysqli.
     *
     * @return Connection
     * @throws DbException when no mysqli connection is available
     */
    public static function instance(): self
    {
        if (!self::$shared instanceof self) {
            self::$shared = new self();
        }

        return self::$shared;
    }

    /**
     * Run a SELECT and return every row as an associative array.
     *
     * @param string $sql
     * @param array  $params Positional values for the '?' placeholders in $sql
     *
     * @return array List of rows (empty when none match)
     * @throws DbException
     */
    public function select(string $sql, array $params = []): array
    {
        if ($params === []) {
            $result = $this->run(function () use ($sql) {
                return $this->conn->query($sql);
            }, $sql);
            if (!$result instanceof \mysqli_result) {
                throw new DbException('Database query failed');
            }

            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();

            return $rows;
        }

        return $this->withStatement($sql, $params, static function (\mysqli_stmt $stmt): array {
            $result = $stmt->get_result();
            if (!$result instanceof \mysqli_result) {
                throw new DbException('Database query failed');
            }
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();

            return $rows;
        });
    }

    /**
     * Run a SELECT and return the first row, or null when there are no rows.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return array|null
     * @throws DbException
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $rows = $this->select($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * Run a SELECT and return the first column of the first row, or null.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return mixed
     * @throws DbException
     */
    public function scalar(string $sql, array $params = [])
    {
        $row = $this->selectOne($sql, $params);
        if ($row === null) {
            return null;
        }

        foreach ($row as $value) {
            return $value;
        }

        return null;
    }

    /**
     * Run an INSERT/UPDATE/DELETE and return the number of affected rows.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return int
     * @throws DbException
     */
    public function execute(string $sql, array $params = []): int
    {
        if ($params === []) {
            $this->run(function () use ($sql) {
                return $this->conn->query($sql);
            }, $sql);

            return (int) $this->conn->affected_rows;
        }

        return $this->withStatement($sql, $params, static function (\mysqli_stmt $stmt): int {
            return (int) $stmt->affected_rows;
        });
    }

    /**
     * Run a multi-statement script -- a schema file, a locale template, an
     * uploaded import -- and return how many statements were executed.
     *
     * Statements run one at a time rather than through multi_query, so a failure
     * names the statement that caused it and no result set is left undrained.
     * Execution stops at the first failure; MySQL auto-commits DDL, so a script
     * that fails part way leaves the statements before it applied.
     *
     * @param string $sql
     *
     * @return int
     * @throws DbException on the first failing statement
     */
    public function executeScript(string $sql): int
    {
        $executed = 0;
        foreach (SqlScript::statements($sql) as $statement) {
            $this->execute($statement);
            $executed++;
        }

        return $executed;
    }

    /**
     * Run an INSERT and return the generated AUTO_INCREMENT id.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return int
     * @throws DbException
     */
    public function insertGetId(string $sql, array $params = []): int
    {
        if ($params === []) {
            $this->run(function () use ($sql) {
                return $this->conn->query($sql);
            }, $sql);

            return (int) $this->conn->insert_id;
        }

        $conn = $this->conn;

        return $this->withStatement($sql, $params, static function (\mysqli_stmt $stmt) use ($conn): int {
            return (int) $conn->insert_id;
        });
    }

    /**
     * Begin a transaction. Delegates to Db so Connection is a one-stop entry point.
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return Db::beginTransaction();
    }

    /**
     * Commit the current transaction.
     *
     * @return bool
     */
    public function commit(): bool
    {
        return Db::commit();
    }

    /**
     * Roll back the current transaction.
     *
     * @return bool
     */
    public function rollBack(): bool
    {
        return Db::rollBack();
    }

    /**
     * Run $fn inside a transaction (or savepoint when nested).
     *
     * @param callable $fn
     *
     * @return mixed
     * @throws Throwable
     */
    public function transaction(callable $fn)
    {
        return Db::transaction($fn);
    }

    /**
     * Prepare $sql, bind $params as positional parameters, execute, hand the
     * live statement to $consume, and always close the statement afterwards.
     *
     * @param string   $sql
     * @param array    $params
     * @param callable $consume Receives the executed mysqli_stmt, returns the result
     *
     * @return mixed Whatever $consume returns
     * @throws DbException
     */
    private function withStatement(string $sql, array $params, callable $consume)
    {
        $stmt = $this->run(function () use ($sql) {
            return $this->conn->prepare($sql);
        }, $sql);

        try {
            $this->bindParams($stmt, $params);
            $this->run(static function () use ($stmt) {
                return $stmt->execute();
            }, $sql);

            return $consume($stmt);
        } finally {
            $stmt->close();
        }
    }

    /**
     * Bind $params to $stmt, inferring the mysqli type of each value.
     *
     * mysqli_stmt::bind_param takes its arguments by reference, and spreading a
     * plain array does not preserve references on PHP 8.0, so bind_param is
     * invoked through call_user_func_array over an array of references into a
     * local values array.
     *
     * @param \mysqli_stmt $stmt
     * @param array        $params
     */
    private function bindParams(\mysqli_stmt $stmt, array $params): void
    {
        $types = '';
        $values = [];
        foreach (array_values($params) as $param) {
            if (is_int($param)) {
                $types .= 'i';
                $values[] = $param;
            } elseif (is_float($param)) {
                $types .= 'd';
                $values[] = $param;
            } elseif (is_bool($param)) {
                $types .= 'i';
                $values[] = (int) $param;
            } elseif ($param === null) {
                $types .= 's';
                $values[] = null;
            } elseif (is_scalar($param)) {
                $types .= 's';
                $values[] = (string) $param;
            } else {
                // An array/object param would (string)-cast to 'Array' or throw a
                // raw \Error; fail through the same generic-exception discipline.
                error_log('Db bind_param: unsupported param type ' . gettype($param));
                throw new DbException('Database query failed');
            }
        }

        $refs = [$types];
        foreach ($values as $i => $value) {
            $refs[] = &$values[$i];
        }

        $this->run(static function () use ($stmt, $refs) {
            return call_user_func_array([$stmt, 'bind_param'], $refs);
        }, 'bind_param');
    }

    /**
     * Execute $fn, translating any failure into a generic DbException while
     * logging the real detail (sql + mysqli error, never the param values)
     * server-side. mysqli_report is in strict/exception mode, so prepare,
     * execute and query throw mysqli_sql_exception on error.
     *
     * @param callable $fn
     * @param string   $sql Context for the server-side log only
     *
     * @return mixed The truthy return value of $fn
     * @throws DbException
     */
    private function run(callable $fn, string $sql)
    {
        try {
            $result = $fn();
            if ($result === false) {
                throw new DbException('Database query failed', (int) $this->conn->errno);
            }

            return $result;
        } catch (Throwable $e) {
            error_log('Db query failed: ' . $sql . ' -- ' . $e->getMessage());
            if ($e instanceof DbException) {
                throw $e;
            }
            // The message stays generic so no SQL or schema detail reaches an end
            // user, but the driver's error number is carried through: it is only a
            // category, and callers such as the installer map it to their own
            // wording ("cannot reach the server", "access denied", ...).
            throw new DbException('Database query failed', (int) $e->getCode());
        }
    }
}

/* file end: ./oc-includes/osclass/classes/database/Connection.php */
