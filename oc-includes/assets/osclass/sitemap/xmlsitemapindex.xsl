<?xml version="1.0" encoding="UTF-8"?>
<!--
  This file is part of Shopclass (Mindstellar).
  Copyright (c) 2021-2026 Mindstellar Community

  Distributed under the GNU General Public License v3.0 or later. See LICENSE.

  SPDX-License-Identifier: GPL-3.0-or-later
-->
<xsl:stylesheet version="1.0"
                xmlns:html="http://www.w3.org/TR/REC-html40"
                xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
                xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>
    <xsl:template match="/">
        <html xmlns="http://www.w3.org/1999/xhtml" lang="en">
            <head>
                <title>XML Sitemap Index</title>
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
                <meta name="viewport" content="width=device-width, initial-scale=1"/>
                <meta name="robots" content="noindex,follow"/>
                <style type="text/css">
                    :root {
                        --bg: #f7f9fb; --surface: #ffffff; --ink: #14181f; --muted: #5f6b7a;
                        --rule: #dde3ea; --brand: #0b7269; --brand-deep: #09625c; --brand-tint: #e6f6f4;
                        --shadow: 0 1px 2px rgba(15, 39, 66, .04), 0 6px 20px rgba(15, 39, 66, .06);
                    }
                    @media (prefers-color-scheme: dark) {
                        :root {
                            --bg: #0f141b; --surface: #171e27; --ink: #e7ebf0; --muted: #9aa6b4;
                            --rule: #2a333f; --brand: #34c7bb; --brand-deep: #5fd6cc; --brand-tint: #12312e;
                            --shadow: 0 1px 2px rgba(0, 0, 0, .3), 0 6px 20px rgba(0, 0, 0, .35);
                        }
                    }
                    * { box-sizing: border-box; }
                    body {
                        background: var(--bg); color: var(--ink); margin: 0;
                        font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                        font-size: 15px; line-height: 1.55; -webkit-font-smoothing: antialiased;
                    }
                    .wrap { max-width: 1080px; margin: 0 auto; padding: 40px 20px 64px; }
                    header { margin-bottom: 28px; }
                    .eyebrow {
                        display: inline-flex; align-items: center; gap: 8px;
                        color: var(--brand); font-weight: 600; font-size: 13px; letter-spacing: .04em; text-transform: uppercase;
                    }
                    .eyebrow .dot { width: 9px; height: 9px; border-radius: 999px; background: var(--brand); }
                    h1 { font-size: clamp(1.6rem, 4vw, 2.1rem); font-weight: 650; letter-spacing: -.02em; margin: .35rem 0 .5rem; }
                    .lede { color: var(--muted); max-width: 65ch; margin: 0; }
                    .lede a { color: var(--brand); text-decoration: none; }
                    .lede a:hover { text-decoration: underline; }
                    .count {
                        display: inline-block; margin-top: 18px; padding: 6px 14px; border-radius: 999px;
                        background: var(--brand-tint); color: var(--brand-deep); font-weight: 600; font-size: 13.5px;
                    }
                    .card { margin-top: 22px; background: var(--surface); border: 1px solid var(--rule); border-radius: 12px; box-shadow: var(--shadow); overflow: hidden; }
                    .scroll { overflow-x: auto; }
                    table { width: 100%; border-collapse: collapse; }
                    thead th {
                        text-align: left; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase;
                        color: var(--muted); background: var(--bg); padding: 12px 18px; border-bottom: 1px solid var(--rule); white-space: nowrap;
                    }
                    tbody td { padding: 13px 18px; border-bottom: 1px solid var(--rule); vertical-align: middle; }
                    tbody tr:last-child td { border-bottom: none; }
                    tbody tr:hover td { background: var(--brand-tint); }
                    td.url { width: 80%; }
                    td.url a {
                        color: var(--brand); text-decoration: none; font-weight: 500; word-break: break-all;
                    }
                    td.url a:hover { color: var(--brand-deep); text-decoration: underline; }
                    td.meta { color: var(--muted); font-size: 13.5px; white-space: nowrap; font-variant-numeric: tabular-nums; }
                    footer { margin-top: 26px; color: var(--muted); font-size: 13px; }
                    footer a { color: var(--brand); text-decoration: none; }
                    footer a:hover { text-decoration: underline; }
                    @media (max-width: 600px) {
                        .wrap { padding: 28px 14px 48px; }
                        thead th, tbody td { padding: 11px 12px; }
                        td.meta { font-size: 12.5px; }
                    }
                </style>
            </head>
            <body>
                <div class="wrap">
                    <header>
                        <span class="eyebrow"><span class="dot"></span> Sitemap index</span>
                        <h1>XML Sitemap Index</h1>
                        <p class="lede">
                            This is an XML sitemap index — a list of the individual sitemaps that together cover
                            this site, for search engines to crawl. Learn more at
                            <a href="https://www.sitemaps.org">sitemaps.org</a>.
                        </p>
                        <span class="count">
                            <xsl:value-of select="count(sitemap:sitemapindex/sitemap:sitemap)"/> sitemaps
                        </span>
                    </header>
                    <div class="card">
                        <div class="scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Sitemap</th>
                                        <th>Last modified</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <xsl:for-each select="sitemap:sitemapindex/sitemap:sitemap">
                                        <tr>
                                            <td class="url">
                                                <xsl:variable name="itemURL">
                                                    <xsl:value-of select="sitemap:loc"/>
                                                </xsl:variable>
                                                <a href="{$itemURL}">
                                                    <xsl:value-of select="sitemap:loc"/>
                                                </a>
                                            </td>
                                            <td class="meta">
                                                <xsl:value-of select="concat(substring(sitemap:lastmod,0,11),concat(' ', substring(sitemap:lastmod,12,5)))"/>
                                            </td>
                                        </tr>
                                    </xsl:for-each>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <footer>
                        Generated by <a href="https://mindstellar.com">Shopclass</a>.
                    </footer>
                </div>
            </body>
        </html>
    </xsl:template>
</xsl:stylesheet>
