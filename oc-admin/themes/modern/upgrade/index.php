<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
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

osc_admin_page(array(
    'section' => __('Tools'),
));

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return __('Upgrade');
}

osc_add_filter('admin_title', 'customPageTitle');

//customize Head
function customHead()
{
    // The screen reports back in the owner's terms, not the database's. Two things
    // can happen during an upgrade and both are worth naming: updates that bring the
    // data in line with the new version, and repairs where the database did not match
    // what Shopclass expected. Neither is a normal thing for a site owner to reason
    // about, so each gets a plain sentence saying what it means for them, and the
    // statements themselves sit behind a disclosure for whoever does want them.
    $strings = array(
        'titleDone'     => __('Your database is up to date'),
        'titleFailed'   => __('The upgrade did not finish'),
        'nothing'       => __('Nothing needed changing.'),
        'nothingNote'   => __('Your database already matched this version of Shopclass.'),
        // Both forms are handed over rather than a "%s update(s)" fudge: the count is
        // only known once the request comes back, so _n() cannot pick here.
        'updatesOne'    => __('Applied one update.'),
        'updatesMany'   => __('Applied %s updates.'),
        'updatesNote'   => __('These bring your existing data in line with the new version.'),
        'repairsOne'    => __('Repaired one difference.'),
        'repairsMany'   => __('Repaired %s differences.'),
        'repairsNote'   => __('Parts of your database did not match what Shopclass expected, and have been put right. This usually follows a plugin change or an upgrade that was interrupted.'),
        'failedNote'    => __('Your site has not been changed. Nothing was left half-done.'),
        'detail'        => __('Show technical detail'),
        'runningTitle'  => __('Updating your database'),
        'runningNote'   => __('This can take a minute on a large site. Please leave this page open until it finishes.'),
    );
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            <?php if (Params::getParam('confirm') === 'true') { ?>
            var T = <?php echo json_encode($strings, JSON_UNESCAPED_UNICODE); ?>;

            var output = document.getElementById('output');
            if (output) { output.style.display = ''; }
            var tohide = document.getElementById('tohide');
            if (tohide) { tohide.style.display = 'none'; }

            function el(tag, cls, text) {
                var n = document.createElement(tag);
                if (cls) { n.className = cls; }
                if (text !== undefined && text !== null) { n.textContent = text; }
                return n;
            }

            /* One reported outcome: a sentence, what it means, and the raw statements
               folded away. `state` picks the tint and the icon shape. */
            function row(state, line, note, detail) {
                var li = el('li', 'upgrade-report-item upgrade-report-item-' + state);
                li.appendChild(el('span', 'upgrade-report-icon'))
                    .setAttribute('aria-hidden', 'true');

                var body = el('div', 'upgrade-report-body');
                body.appendChild(el('p', 'upgrade-report-line', line));
                if (note) { body.appendChild(el('p', 'upgrade-report-note', note)); }

                if (detail && detail.length) {
                    var d = el('details', 'upgrade-report-detail');
                    d.appendChild(el('summary', null, T.detail));
                    var ul = el('ul', 'upgrade-report-detail-list');
                    detail.forEach(function (entry) {
                        ul.appendChild(el('li', null, String(entry)));
                    });
                    d.appendChild(ul);
                    body.appendChild(d);
                }

                li.appendChild(body);
                return li;
            }

            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=upgrade_db&skipdb=<?php echo osc_esc_js(Params::getParam('skipdb')); ?>&<?php echo osc_csrf_token_url(); ?>', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json();
            }).then(function (data) {
                var result = document.getElementById('result');
                if (!result) { return; }

                var failed = Number(data.error) !== 0;
                var applied = Array.isArray(data.applied) ? data.applied : [];
                var repairs = Array.isArray(data.repairs) ? data.repairs : [];

                var section = el('section', 'upgrade-report' + (failed ? ' upgrade-report-failed' : ''));
                section.setAttribute('aria-labelledby', 'upgrade-report-title');

                var head = el('header', 'upgrade-report-head');
                var h = el('h3', 'upgrade-report-heading', failed ? T.titleFailed : T.titleDone);
                h.id = 'upgrade-report-title';
                head.appendChild(h);
                if (!failed && data.version) {
                    head.appendChild(el('p', 'upgrade-report-sub', 'Shopclass ' + data.version));
                }
                section.appendChild(head);

                var list = el('ul', 'upgrade-report-list');

                if (failed) {
                    var li = el('li', 'upgrade-report-item upgrade-report-item-failed');
                    li.appendChild(el('span', 'upgrade-report-icon')).setAttribute('aria-hidden', 'true');
                    var body = el('div', 'upgrade-report-body');
                    /* Server-composed, and for error 2 it carries the markup that
                       offers to continue past a false positive — same trust as before. */
                    var msg = el('div', 'upgrade-report-line');
                    msg.innerHTML = String(data.message || '').replace(/\n/g, '<br />');
                    body.appendChild(msg);
                    body.appendChild(el('p', 'upgrade-report-note', T.failedNote));
                    li.appendChild(body);
                    list.appendChild(li);
                } else {
                    var count = function (n, one, many) {
                        return n === 1 ? one : many.replace('%s', n);
                    };

                    if (applied.length) {
                        list.appendChild(row(
                            'done',
                            count(applied.length, T.updatesOne, T.updatesMany),
                            T.updatesNote,
                            applied
                        ));
                    }
                    if (repairs.length) {
                        list.appendChild(row(
                            'repaired',
                            count(repairs.length, T.repairsOne, T.repairsMany),
                            T.repairsNote,
                            repairs
                        ));
                    }
                    if (!applied.length && !repairs.length) {
                        list.appendChild(row('done', T.nothing, T.nothingNote, null));
                    }
                }

                section.appendChild(list);
                result.replaceChildren(section);
            });
            <?php } ?>
        });
    </script>
<?php }

osc_add_hook('admin_header', 'customHead', 10);

/**
 * Parse the newest release section of CHANGELOG.md into a label plus typed entries,
 * the entries ordered so features and notable changes surface first. Falls back to
 * the running version and an empty list when the changelog is missing or unreadable.
 *
 * @return array{label:string,entries:array<int,array{cat:string,text:string}>}
 */
function upgradeReleaseNotes()
{
    $fallbackLabel = 'Shopclass ' . (defined('OSCLASS_VERSION') ? OSCLASS_VERSION : '');
    $file          = ABS_PATH . 'CHANGELOG.md';
    if (!is_readable($file)) {
        return array('label' => $fallbackLabel, 'entries' => array());
    }

    $label   = $fallbackLabel;
    $entries = array();
    $inFirst = false;
    $cat     = '';
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        // "## Shopclass 5.3.0" opens a release; the next one ends the newest section.
        if (preg_match('/^##\s+(?!#)(.+?)\s*$/', $line, $m)) {
            if ($inFirst) {
                break; // reached the previous release
            }
            $inFirst = true;
            $label   = trim($m[1]);
            continue;
        }
        if (!$inFirst) {
            continue;
        }
        // "### Security" names the category the following bullets belong to.
        if (preg_match('/^###\s+(.+?)\s*$/', $line, $m)) {
            $cat = trim($m[1]);
            continue;
        }
        if (preg_match('/^-\s+(.+)$/', $line, $m)) {
            $entries[] = array('cat' => $cat, 'text' => trim(str_replace('`', '', $m[1])));
            continue;
        }
        // Entries are hard-wrapped, so an indented line continues the previous bullet.
        if ($entries !== array() && preg_match('/^\s+(\S.*)$/', $line, $m)) {
            $last                     = count($entries) - 1;
            $entries[$last]['text'] .= ' ' . trim(str_replace('`', '', $m[1]));
        }
    }

    // Surface features and breaking/security notes before routine fixes; PHP 8's
    // stable sort keeps each category in its authored changelog order.
    $priority = array('New' => 0, 'Breaking' => 1, 'Security' => 2, 'Changed' => 3, 'Performance' => 4, 'Fixed' => 5);
    usort($entries, static function ($a, $b) use ($priority) {
        return ($priority[$a['cat']] ?? 9) <=> ($priority[$b['cat']] ?? 9);
    });

    return array('label' => $label, 'entries' => $entries);
}

osc_current_admin_theme_path('parts/header.php'); ?>

<div id="backup-settings">
    <?php osc_admin_page_head(__('Upgrade')); ?>
    <div id="result">
        <div id="output" class="upgrade-running" style="display:none" role="status" aria-live="polite">
            <span class="spinner-border upgrade-running-spinner" aria-hidden="true"></span>
            <div>
                <p class="upgrade-running-line"><?php _e('Updating your database'); ?></p>
                <p class="upgrade-running-note">
                    <?php _e('This can take a minute on a large site. Please leave this page open until it finishes.'); ?>
                </p>
            </div>
        </div>
        <div id="tohide">
            <p>
                <?php _e('You have uploaded a new version of Shopclass, you need to upgrade Shopclass for it to work correctly.'); ?>
            </p>
            <a class="btn btn-dim"
               href="<?php echo osc_admin_base_url(true); ?>?page=upgrade&confirm=true"><?php _e('Upgrade now'); ?></a>
        </div>
    </div>
</div>
<?php
$release  = upgradeReleaseNotes();
$whatsNew = $release['entries'];
// Link at the release tag for the current major.minor.patch, dropping any
// pre-release suffix (5.3.0.dev5 => v5.3.0) so it tracks the version; fall back
// to the releases listing if the version can't be parsed.
$version    = defined('OSCLASS_VERSION') ? OSCLASS_VERSION : '';
$releaseUrl = preg_match('/^\d+\.\d+\.\d+/', $version, $m)
    ? 'https://github.com/mindstellar/shopclass/releases/tag/v' . $m[0]
    : 'https://github.com/mindstellar/shopclass/releases';
if (!empty($whatsNew)) {
    $shown     = array_slice($whatsNew, 0, 10);
    $remaining = count($whatsNew) - count($shown);
    ?>
    <section class="whatsnew" aria-labelledby="whatsnew-title">
        <header class="whatsnew-head">
            <div>
                <h3 id="whatsnew-title" class="whatsnew-heading"><?php _e("What's new"); ?></h3>
                <p class="whatsnew-sub">
                    <?php printf(__('Highlights from %s'), osc_esc_html($release['label'])); ?>
                </p>
            </div>
            <span class="whatsnew-count">
                <?php printf(_n('%d change', '%d changes', count($whatsNew)), count($whatsNew)); ?>
            </span>
        </header>
        <ul class="whatsnew-list">
            <?php foreach ($shown as $entry) {
                $slug = strtolower(preg_replace('/[^a-z]/i', '', $entry['cat'])); ?>
                <li class="whatsnew-item">
                    <span class="whatsnew-tag whatsnew-tag-<?php echo osc_esc_html($slug); ?>"><?php
                        echo osc_esc_html($entry['cat']); ?></span>
                    <span class="whatsnew-text"><?php echo osc_esc_html($entry['text']); ?></span>
                </li>
            <?php } ?>
        </ul>
        <footer class="whatsnew-foot">
            <a class="whatsnew-more-link" href="<?php echo osc_esc_html($releaseUrl); ?>"
               target="_blank" rel="noopener noreferrer">
                <?php if ($remaining > 0) {
                    printf(_n('%d more change', '%d more changes', $remaining), $remaining);
                    echo ' · ';
                } ?>
                <?php _e('Read the full release notes on GitHub'); ?>
                <span aria-hidden="true">↗</span>
            </a>
        </footer>
    </section>
<?php } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
