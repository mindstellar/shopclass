<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 *
 */
class LogDatabase
{
    /**
     *
     * @var
     */
    private static $instance;
    /**
     *
     * @var
     */
    public $messages;
    /**
     *
     * @var
     */
    public $explain_messages;

    /**
     *
     */
    public function __construct()
    {
        $this->messages         = array();
        $this->explain_messages = array();
    }

    /**
     *
     * @return \LogDatabase
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     *
     * @param $sql
     * @param $time
     * @param $errorLevel
     * @param $errorDescription
     */
    public function addMessage($sql, $time, $errorLevel, $errorDescription)
    {
        $this->messages[] = array(
            'query'      => $sql,
            'query_time' => $time,
            'errno'      => $errorLevel,
            'error'      => $errorDescription
        );
    }

    /**
     *
     * @param      $sql
     * @param      $results
     */
    public function addExplainMessage($sql, $results)
    {
        $this->explain_messages[] = array(
            'query'   => $sql,
            'explain' => $results
        );
    }

    /**
     * Render the request's query log as a self-contained, collapsible panel docked
     * to the bottom of the page. Styles are inlined and namespaced so the panel looks
     * the same over any theme, light or dark, and never inherits or leaks CSS.
     */
    public function printMessages()
    {
        $total     = $this->getTotalNumberQueries();
        $totalMs   = (float) $this->getTotalQueriesTime() * 1000;
        $errors    = 0;
        $slow      = 0;
        $slowMs    = 50.0; // a query over this many ms is flagged
        $slowestMs = 0.0;

        // Count how often each normalised query runs, to surface duplicates (the
        // usual tell for an N+1). Whitespace is collapsed so formatting differences
        // do not split otherwise-identical queries.
        $seen = array();
        foreach ($this->messages as $msg) {
            $key        = preg_replace('/\s+/', ' ', trim((string) $msg['query']));
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ((int) $msg['errno'] !== 0) {
                $errors++;
            }
            $ms = (float) $msg['query_time'] * 1000;
            if ($ms >= $slowMs) {
                $slow++;
            }
            if ($ms > $slowestMs) {
                $slowestMs = $ms;
            }
        }
        $dupes = count(array_filter($seen, static function ($n) {
            return $n > 1;
        }));

        // Index EXPLAIN plans by their normalised query (dropping any leading
        // EXPLAIN the legacy DAO stored) so each can be shown under its query row.
        $explains = array();
        foreach ($this->explain_messages as $em) {
            $q            = preg_replace('/^\s*EXPLAIN\s+/i', '', (string) $em['query']);
            $q            = preg_replace('/\s+/', ' ', trim($q));
            $explains[$q] = $em['explain'];
        }

        echo $this->panelStyles();
        echo '<div id="osc-qdbg" class="osc-qdbg">';
        echo '<details class="osc-qdbg__wrap">';

        // -- summary bar (always visible) --
        echo '<summary class="osc-qdbg__bar">';
        echo '<span class="osc-qdbg__brand">DB</span>';
        echo '<span class="osc-qdbg__stat"><b>' . $total . '</b> queries</span>';
        echo '<span class="osc-qdbg__stat"><b>' . $this->fmtMs($totalMs) . '</b> total</span>';
        if ($slowestMs > 0) {
            echo '<span class="osc-qdbg__stat">slowest <b>' . $this->fmtMs($slowestMs) . '</b></span>';
        }
        if ($dupes > 0) {
            echo '<span class="osc-qdbg__stat osc-qdbg--warn">&#9888; ' . $dupes . ' duplicated</span>';
        }
        if ($slow > 0) {
            echo '<span class="osc-qdbg__stat osc-qdbg--slow">' . $slow . ' slow</span>';
        }
        if ($errors > 0) {
            echo '<span class="osc-qdbg__stat osc-qdbg--err">&#10007; ' . $errors . ' errors</span>';
        }
        echo '<span class="osc-qdbg__hint">click to expand</span>';
        echo '</summary>';

        // -- query list --
        echo '<div class="osc-qdbg__list">';
        if ($total === 0) {
            echo '<div class="osc-qdbg__empty">No queries ran this request.</div>';
        } else {
            $i = 0;
            foreach ($this->messages as $msg) {
                $i++;
                $ms      = (float) $msg['query_time'] * 1000;
                $isErr   = (int) $msg['errno'] !== 0;
                $key     = preg_replace('/\s+/', ' ', trim((string) $msg['query']));
                $dupN    = $seen[$key] ?? 1;
                $tier    = $ms >= $slowMs ? 'slow' : ($ms >= 10 ? 'mid' : 'fast');
                $rowCls  = 'osc-qdbg__row' . ($isErr ? ' osc-qdbg__row--err' : '');

                echo '<div class="' . $rowCls . '">';
                echo '<span class="osc-qdbg__idx">' . $i . '</span>';
                echo '<span class="osc-qdbg__time osc-qdbg__time--' . $tier . '">' . $this->fmtMs($ms) . '</span>';
                echo '<div class="osc-qdbg__sql">';
                if ($dupN > 1) {
                    echo '<span class="osc-qdbg__dupe" title="This exact query ran '
                        . $dupN . ' times">&#8635; &times;' . $dupN . '</span>';
                }
                if ($isErr) {
                    echo '<div class="osc-qdbg__errline">#' . (int) $msg['errno'] . ' '
                        . htmlspecialchars((string) $msg['error'], ENT_QUOTES) . '</div>';
                }
                echo '<code>' . $this->highlightSql((string) $msg['query']) . '</code>';
                if (isset($explains[$key]) && is_array($explains[$key]) && $explains[$key] !== array()) {
                    echo $this->renderExplain($explains[$key]);
                }
                echo '</div>';
                echo '</div>';
            }
        }
        echo '</div>'; // list
        echo '</details>';
        echo '</div>'; // panel
    }

    /**
     * Format a millisecond duration compactly: sub-millisecond as µs, otherwise ms.
     *
     * @param float $ms
     *
     * @return string
     */
    private function fmtMs($ms)
    {
        if ($ms < 1) {
            return round($ms * 1000) . '&#181;s';
        }

        return ($ms < 10 ? round($ms, 2) : round($ms, 1)) . 'ms';
    }

    /**
     * Escape a SQL string and wrap its keywords/strings/numbers so the panel can
     * colour them. Tokenises the raw SQL and escapes each token individually, so a
     * digit inside a quoted literal can never corrupt an HTML entity.
     *
     * @param string $sql
     *
     * @return string
     */
    private function highlightSql($sql)
    {
        static $keywords = array(
            'SELECT', 'INSERT', 'INTO', 'UPDATE', 'DELETE', 'REPLACE', 'FROM', 'WHERE', 'AND', 'OR',
            'NOT', 'NULL', 'IS', 'IN', 'LIKE', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'JOIN', 'ON', 'AS',
            'ORDER', 'GROUP', 'BY', 'HAVING', 'LIMIT', 'OFFSET', 'SET', 'VALUES', 'DISTINCT', 'COUNT',
            'SUM', 'AVG', 'MIN', 'MAX', 'ASC', 'DESC', 'UNION', 'BETWEEN', 'EXISTS', 'CASE', 'WHEN',
            'THEN', 'ELSE', 'END', 'DUPLICATE', 'KEY', 'CREATE', 'TABLE', 'ALTER', 'DROP', 'INDEX',
            'PRIMARY',
        );
        $kw = array_flip($keywords);

        // Token classes: 'single'/"double" string, integer, identifier/word, run of
        // anything else (whitespace, punctuation). Each is escaped on output.
        $re = "/('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")|(\\b\\d+\\b)|([A-Za-z_][A-Za-z0-9_]*)|([^'\"A-Za-z0-9_]+)/s";

        return preg_replace_callback($re, static function ($m) use ($kw) {
            if (($m[1] ?? '') !== '') {
                return '<span class="osc-qdbg__str">' . htmlspecialchars($m[1], ENT_QUOTES) . '</span>';
            }
            if (($m[2] ?? '') !== '') {
                return '<span class="osc-qdbg__num">' . $m[2] . '</span>';
            }
            if (($m[3] ?? '') !== '') {
                if (isset($kw[strtoupper($m[3])])) {
                    return '<span class="osc-qdbg__kw">' . $m[3] . '</span>';
                }

                return htmlspecialchars($m[3], ENT_QUOTES);
            }

            return htmlspecialchars($m[4] ?? '', ENT_QUOTES);
        }, trim($sql));
    }

    /**
     * Render an EXPLAIN plan as a collapsible table beneath its query. Full table
     * scans (type=ALL), missing keys and filesort/temporary in Extra are flagged so
     * the expensive rows stand out.
     *
     * @param array $rows EXPLAIN output rows (associative)
     *
     * @return string
     */
    private function renderExplain(array $rows)
    {
        $cols = array_keys($rows[0]);

        $out  = '<details class="osc-qdbg__explain"><summary>EXPLAIN</summary>';
        $out .= '<table><thead><tr>';
        foreach ($cols as $col) {
            $out .= '<th>' . htmlspecialchars((string) $col, ENT_QUOTES) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $out .= '<tr>';
            foreach ($cols as $col) {
                $val   = $row[$col] ?? null;
                $text  = $val === null ? 'NULL' : (string) $val;
                $flag  = '';
                if ($col === 'type' && strtoupper($text) === 'ALL') {
                    $flag = ' class="osc-qdbg--err"';
                } elseif ($col === 'key' && ($val === null || $text === '')) {
                    $flag = ' class="osc-qdbg--warn"';
                } elseif ($col === 'Extra' && preg_match('/Using (filesort|temporary)/i', $text)) {
                    $flag = ' class="osc-qdbg--warn"';
                }
                $out .= '<td' . $flag . '>' . htmlspecialchars($text, ENT_QUOTES) . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table></details>';

        return $out;
    }

    /**
     * The panel's inlined, namespaced stylesheet. Printed once.
     *
     * @return string
     */
    private function panelStyles()
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;

        return <<<CSS
<style>
#osc-qdbg{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#e6edf3}
#osc-qdbg *{box-sizing:border-box}
#osc-qdbg .osc-qdbg__wrap{background:#0d1117;border-top:2px solid #c8804f;box-shadow:0 -8px 24px rgba(0,0,0,.35)}
#osc-qdbg .osc-qdbg__bar{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:9px 16px;cursor:pointer;list-style:none;user-select:none}
#osc-qdbg .osc-qdbg__bar::-webkit-details-marker{display:none}
#osc-qdbg .osc-qdbg__bar:hover{background:#161b22}
#osc-qdbg .osc-qdbg__brand{font-weight:700;color:#c8804f;letter-spacing:.02em}
#osc-qdbg .osc-qdbg__stat{color:#9da7b3}
#osc-qdbg .osc-qdbg__stat b{color:#e6edf3;font-weight:700}
#osc-qdbg .osc-qdbg--warn b,#osc-qdbg .osc-qdbg--warn{color:#e3b341}
#osc-qdbg .osc-qdbg--slow{color:#e3b341}
#osc-qdbg .osc-qdbg--err{color:#f85149}
#osc-qdbg .osc-qdbg__hint{margin-left:auto;font-size:11px;color:#6e7781}
#osc-qdbg .osc-qdbg__list{max-height:45vh;overflow:auto;border-top:1px solid #21262d}
#osc-qdbg .osc-qdbg__empty{padding:18px 16px;color:#6e7781}
#osc-qdbg .osc-qdbg__row{display:flex;gap:12px;align-items:flex-start;padding:8px 16px;border-bottom:1px solid #161b22}
#osc-qdbg .osc-qdbg__row:hover{background:#11161d}
#osc-qdbg .osc-qdbg__row--err{background:rgba(248,81,73,.08)}
#osc-qdbg .osc-qdbg__idx{color:#6e7781;min-width:2.5ch;text-align:right;flex:none}
#osc-qdbg .osc-qdbg__time{flex:none;min-width:8ch;text-align:right;font-variant-numeric:tabular-nums;padding:1px 8px;border-radius:6px;font-size:12px}
#osc-qdbg .osc-qdbg__time--fast{background:rgba(63,185,80,.16);color:#3fb950}
#osc-qdbg .osc-qdbg__time--mid{background:rgba(227,179,65,.16);color:#e3b341}
#osc-qdbg .osc-qdbg__time--slow{background:rgba(248,81,73,.18);color:#f85149}
#osc-qdbg .osc-qdbg__sql{min-width:0;flex:1}
#osc-qdbg .osc-qdbg__sql code{white-space:pre-wrap;word-break:break-word;color:#c9d1d9}
#osc-qdbg .osc-qdbg__dupe{display:inline-block;margin:0 8px 4px 0;padding:0 7px;border-radius:6px;background:rgba(227,179,65,.16);color:#e3b341;font-size:11px;font-weight:700}
#osc-qdbg .osc-qdbg__errline{color:#f85149;margin-bottom:4px}
#osc-qdbg .osc-qdbg__kw{color:#ff7b72;font-weight:600}
#osc-qdbg .osc-qdbg__str{color:#a5d6ff}
#osc-qdbg .osc-qdbg__num{color:#79c0ff}
#osc-qdbg .osc-qdbg__explain{margin-top:8px}
#osc-qdbg .osc-qdbg__explain summary{cursor:pointer;color:#8b949e;font-size:11px;letter-spacing:.06em;text-transform:uppercase}
#osc-qdbg .osc-qdbg__explain summary:hover{color:#c8804f}
#osc-qdbg .osc-qdbg__explain table{margin-top:6px;border-collapse:collapse;font-size:12px;width:auto}
#osc-qdbg .osc-qdbg__explain th,#osc-qdbg .osc-qdbg__explain td{border:1px solid #21262d;padding:3px 8px;text-align:left;white-space:nowrap}
#osc-qdbg .osc-qdbg__explain th{color:#8b949e;font-weight:600;background:#11161d}
#osc-qdbg .osc-qdbg__explain td{color:#c9d1d9}
#osc-qdbg .osc-qdbg__explain td.osc-qdbg--err{color:#f85149;font-weight:700}
#osc-qdbg .osc-qdbg__explain td.osc-qdbg--warn{color:#e3b341;font-weight:600}
@media (prefers-color-scheme:light){
#osc-qdbg{color:#1f2328}
#osc-qdbg .osc-qdbg__wrap{background:#fff}
#osc-qdbg .osc-qdbg__bar:hover{background:#f6f8fa}
#osc-qdbg .osc-qdbg__stat{color:#57606a}
#osc-qdbg .osc-qdbg__stat b{color:#1f2328}
#osc-qdbg .osc-qdbg__list{border-top-color:#d0d7de}
#osc-qdbg .osc-qdbg__row{border-bottom-color:#eaeef2}
#osc-qdbg .osc-qdbg__row:hover{background:#f6f8fa}
#osc-qdbg .osc-qdbg__sql code{color:#1f2328}
#osc-qdbg .osc-qdbg__kw{color:#cf222e}
#osc-qdbg .osc-qdbg__str{color:#0a3069}
#osc-qdbg .osc-qdbg__num{color:#0550ae}
#osc-qdbg .osc-qdbg__explain summary{color:#57606a}
#osc-qdbg .osc-qdbg__explain th,#osc-qdbg .osc-qdbg__explain td{border-color:#d0d7de}
#osc-qdbg .osc-qdbg__explain th{color:#57606a;background:#f6f8fa}
#osc-qdbg .osc-qdbg__explain td{color:#1f2328}
}
</style>
CSS;
    }

    /**
     * @return int
     */
    public function getTotalNumberQueries()
    {
        return count($this->messages);
    }

    /**
     * @return int
     */
    public function getTotalQueriesTime()
    {
        $time = 0;
        foreach ($this->messages as $m) {
            $time += $m['query_time'];
        }

        return $time;
    }

    /**
     * @return bool
     */
    public function writeMessages()
    {
        $filename = CONTENT_PATH . 'queries.log';

        if ($this->isFileWritableExists($filename)
        ) {
            trigger_error('Can not write explain_queries.log file in "' . CONTENT_PATH
                . '", please check directory/file permissions.', E_USER_WARNING);

            return false;
        }

        $fp = fopen($filename, 'ab');

        if ($fp === false) {
            return false;
        }

        fwrite($fp, '==================================================' . PHP_EOL);

        fwrite($fp, '=' . str_pad('Date: ' . date(osc_date_format() ?: 'Y-m-d') . ' '
                    . date(osc_time_format() ?: 'H:i:s'), 48, ' ', STR_PAD_BOTH) . '='
                . PHP_EOL);

        fwrite(
            $fp,
            '=' . str_pad('Total queries: ' . $this->getTotalNumberQueries(), 48, ' ', STR_PAD_BOTH) . '=' . PHP_EOL
        );
        fwrite($fp, '=' . str_pad('Total queries time: ' . $this->getTotalQueriesTime(), 48, ' ', STR_PAD_BOTH) . '='
            . PHP_EOL);
        fwrite($fp, '==================================================' . PHP_EOL . PHP_EOL);

        foreach ($this->messages as $msg) {
            fwrite($fp, 'QUERY TIME' . ' ' . $msg['query_time'] . PHP_EOL);
            if ($msg['errno'] != 0) {
                fwrite($fp, 'Error number: ' . $msg['errno'] . PHP_EOL);
                fwrite($fp, 'Error description: ' . $msg['error'] . PHP_EOL);
            }
            fwrite($fp, '**************************************************' . PHP_EOL);
            fwrite($fp, $msg['query'] . PHP_EOL);
            fwrite($fp, '--------------------------------------------------' . PHP_EOL);
        }

        fwrite($fp, PHP_EOL . PHP_EOL . PHP_EOL);
        fclose($fp);

        return true;
    }

    /**
     * @param $filename
     *
     * @return bool
     */
    private function isFileWritableExists($filename)
    {
        return (!file_exists($filename) && !is_writable(CONTENT_PATH))
            || (file_exists($filename)
                && !is_writable($filename));
    }

    /**
     * @return bool
     */
    public function writeExplainMessages()
    {
        $filename = CONTENT_PATH . 'explain_queries.log';

        if ($this->isFileWritableExists($filename)
        ) {
            error_log('Can not write explain_queries.log file in "' . CONTENT_PATH
                . '", please check directory/file permissions.');

            return false;
        }

        $fp = fopen($filename, 'ab');

        if ($fp == false) {
            return false;
        }

        fwrite($fp, '==================================================' . PHP_EOL);

        fwrite(
                $fp,
                '=' . str_pad(
                    'Date: ' . date(osc_date_format() ?: 'Y-m-d') . ' ' . date(osc_time_format() ?: 'H:i:s'),
                    48,
                    ' ',
                    STR_PAD_BOTH
                ) . '=' . PHP_EOL
        );
        fwrite($fp, '==================================================' . PHP_EOL . PHP_EOL);

        $title = '|' . str_pad('id', 3, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('select_type', 20, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('table', 20, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('type', 8, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('possible_keys', 28, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('key', 18, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('key_len', 9, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('ref', 48, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('rows', 8, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('Extra', 38, ' ', STR_PAD_BOTH) . '|';

        foreach ($this->explain_messages as $i => $iValue) {
            fwrite($fp, $iValue['query'] . PHP_EOL);
            fwrite($fp, str_pad('', 211, '-', STR_PAD_BOTH) . PHP_EOL);
            fwrite($fp, $title . PHP_EOL);
            fwrite($fp, str_pad('', 211, '-', STR_PAD_BOTH) . PHP_EOL);
            foreach ($iValue['explain'] as $explain) {
                // EXPLAIN returns NULL for columns like possible_keys/key/ref/Extra
                // on a full scan; str_pad() rejects null on PHP 8.1+, so coalesce.
                $col = static function ($v) {
                    return (string) ($v ?? 'NULL');
                };
                $row = '|' . str_pad($col($explain['id'] ?? null), 3, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($col($explain['select_type'] ?? null), 20, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($col($explain['table'] ?? null), 20, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($col($explain['type'] ?? null), 8, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($col($explain['possible_keys'] ?? null), 28, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($col($explain['key'] ?? null), 18, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($col($explain['key_len'] ?? null), 9, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($col($explain['ref'] ?? null), 48, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($col($explain['rows'] ?? null), 8, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($col($explain['Extra'] ?? null), 38, ' ', STR_PAD_BOTH) . '|';
                fwrite($fp, $row . PHP_EOL);
                fwrite($fp, str_pad('', 211, '-', STR_PAD_BOTH) . PHP_EOL);
            }
            if ($i != (count($this->explain_messages) - 1)) {
                fwrite($fp, PHP_EOL . PHP_EOL);
            }
        }

        fwrite($fp, PHP_EOL . PHP_EOL);
        fclose($fp);

        return true;
    }
}

/* file end: ./oc-includes/osclass/logger/LogDatabase.php */
