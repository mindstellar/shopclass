<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The stylesheet of the printable receipt, invoice and credit note pages. Expects $accent.
 *
 * @var string $accent
 */
?>
<style>
:root{color-scheme:light;--accent:<?php echo osc_esc_html($accent); ?>;--bg:#f3f4f2;--paper:#fff;--ink:#16191d;--muted:#5d646c;--line:#e3e5e1;--soft:#f6f7f5;--warn:#9a3412;--warn-bg:#fff1e6}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 "Inter","Segoe UI Variable Text","Segoe UI",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;-webkit-font-smoothing:antialiased}
.rc-wrap{max-width:760px;margin:0 auto;padding:24px 16px 56px}
.rc-tools{display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;align-items:center;margin-bottom:16px}
.rc-tools a{color:var(--ink);text-underline-offset:3px}
.rc-print{font:inherit;font-weight:600;padding:8px 18px;border:0;border-radius:999px;background:var(--accent);color:#fff;cursor:pointer}
.rc-print:focus-visible,.rc-tools a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.rc-paper{background:var(--paper);border:1px solid var(--line);border-top:4px solid var(--accent);border-radius:10px;padding:36px 36px 32px}
.rc-head{display:flex;flex-wrap:wrap;gap:16px 32px;justify-content:space-between;align-items:flex-start}
.rc-brand{min-width:0}
.rc-logo{display:block;max-height:44px;max-width:220px;width:auto}
.rc-site{margin:0;font-size:1.35rem;font-weight:700;letter-spacing:-.01em;color:var(--accent)}
.rc-business{margin:6px 0 0;color:var(--muted);font-size:.875rem}
.rc-extra{margin:6px 0 0;padding:0;list-style:none;color:var(--muted);font-size:.875rem}
.rc-titlebox{text-align:right}
.rc-title{margin:0;font-size:1.5rem;line-height:1.2;letter-spacing:-.01em}
.rc-date{margin:4px 0 0;color:var(--muted);font-size:.9rem}
.rc-status{display:inline-block;margin:8px 0 0;padding:3px 10px;border-radius:999px;background:var(--warn-bg);color:var(--warn);font-weight:600;font-size:.85rem}
.rc-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px 28px;margin:28px 0 0;padding:18px 20px;background:var(--soft);border-radius:8px}
.rc-meta div{min-width:0}
.rc-meta dt{color:var(--muted);font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.rc-meta dd{margin:2px 0 0;overflow-wrap:anywhere}
.rc-rows{width:100%;margin-top:24px;border-collapse:collapse}
.rc-rows th,.rc-rows td{padding:10px 0;border-top:1px solid var(--line);text-align:left;vertical-align:top}
.rc-rows th{width:42%;padding-right:16px;color:var(--muted);font-weight:400}
.rc-rows td{overflow-wrap:anywhere}
.rc-items{width:100%;margin-top:28px;border-collapse:collapse;font-variant-numeric:tabular-nums}
.rc-items thead th{padding:0 0 8px;border-bottom:2px solid var(--ink);color:var(--muted);font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;text-align:left}
.rc-items td{padding:12px 0;border-bottom:1px solid var(--line);vertical-align:top}
.rc-items .rc-num{text-align:right;white-space:nowrap;padding-left:16px}
.rc-items small{display:block;color:var(--muted);font-size:.825rem}
.rc-name{font-weight:600}
.rc-totals{width:min(100%,340px);margin:12px 0 0 auto;border-collapse:collapse;font-variant-numeric:tabular-nums}
.rc-totals th,.rc-totals td{padding:6px 0;text-align:left;font-weight:400}
.rc-totals th{color:var(--muted);padding-right:16px}
.rc-totals td{text-align:right;white-space:nowrap}
.rc-totals .rc-total th,.rc-totals .rc-total td{padding-top:12px;border-top:2px solid var(--ink);color:var(--ink);font-weight:700;font-size:1.1rem}
.rc-summary{width:100%;margin-top:8px}
.rc-summary th,.rc-summary td{font-size:.875rem}
.rc-summary th{width:1%;white-space:nowrap}
.rc-summary td{white-space:normal;overflow-wrap:anywhere}
.rc-ship{margin-top:28px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px 28px}
.rc-ship h2{margin:0 0 4px;color:var(--muted);font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.rc-ship p{margin:0}
.rc-ref{margin:6px 0 0;color:var(--muted);font-size:.9rem}
.rc-note{margin:32px 0 0;padding-top:16px;border-top:1px solid var(--line);color:var(--muted);font-size:.875rem}
@media (max-width:560px){.rc-paper{padding:24px 18px}.rc-titlebox{text-align:left}.rc-rows th,.rc-rows td{display:block;width:auto;padding:0}.rc-rows th{padding-top:10px}.rc-rows td{border-top:0;padding-bottom:10px}.rc-items .rc-price{display:none}.rc-summary th,.rc-summary td{display:block;width:auto;padding:0;white-space:normal;text-align:left}.rc-summary th{padding-top:10px}.rc-summary td{padding-bottom:4px}}
@media print{:root{--bg:#fff;--soft:#fff;--line:#bbb;--muted:#444}body{font-size:11pt}.rc-wrap{max-width:none;padding:0}.rc-tools{display:none}.rc-paper{border:0;border-radius:0;padding:0}.rc-meta{padding:0}.rc-status{border:1px solid currentColor}}
</style>
