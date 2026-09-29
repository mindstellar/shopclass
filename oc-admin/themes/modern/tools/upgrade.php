<?php

use mindstellar\upgrade\Osclass;

if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}

$updateJson    = osc_get_preference('update_core_json');
$isAvailable   = false;
$remoteVersion = '';
if (!empty($updateJson)) {
    $osclassUpgrade = new Osclass(json_decode($updateJson, true));
    $isAvailable    = $osclassUpgrade->isUpgradable();
    $remoteVersion  = (string) $osclassUpgrade->getNewVersion();
}
$selfUpdateOff = osc_self_update_disabled();
$isConfirm     = Params::getParam('confirm') === 'true';
$running       = !$selfUpdateOff && $isAvailable && $isConfirm;

// The run itself after confirm, the release notes before it, and a fresh version check when
// nothing is waiting.
osc_add_hook('admin_footer', static function () use ($selfUpdateOff, $isAvailable, $remoteVersion, $running) {
    if ($selfUpdateOff) {
        return;
    }
    $strings = array(
        'upgraded'  => sprintf(__('Shopclass is upgraded to %s.'), $remoteVersion),
        'partial'   => __('The upgrade finished, with some errors.'),
        'failed'    => __('The upgrade failed.'),
        'notes'     => __('Check release notes'),
        'notesUrl'  => osc_admin_base_url(true) . '?page=tools&action=version',
    );
    ?>
    <script>
        (function () {
            var steps = document.getElementById('steps');
            var t = <?php echo json_encode($strings); ?>;

            // The same markup osc_admin_verdict() prints, built here for the result of the run.
            var verdict = function (tone, text, action) {
                var box = document.createElement('div');
                box.className = 'callout-' + tone + ' callout-block osc-verdict';
                box.setAttribute('role', tone === 'success' ? 'status' : 'alert');
                var list = document.createElement('ul');
                list.className = 'osc-verdict-list';
                var line = document.createElement('li');
                line.className = 'osc-verdict-line';
                var span = document.createElement('span');
                span.className = 'osc-verdict-text';
                span.textContent = text;
                line.appendChild(span);
                if (action) {
                    var link = document.createElement('a');
                    link.className = 'btn btn-sm btn-secondary';
                    link.href = action.url;
                    link.textContent = action.label;
                    line.appendChild(link);
                }
                list.appendChild(line);
                box.appendChild(list);
                return box;
            };
            var report = function (box, html) {
                steps.innerHTML = '';
                steps.appendChild(box);
                if (html) {
                    var more = document.createElement('div');
                    more.className = 'upgrade-tool-message';
                    more.innerHTML = html;
                    steps.appendChild(more);
                }
            };

            <?php if ($running) { ?>
            fetch(<?php echo json_encode(osc_admin_base_url(true) . '?page=ajax&action=upgrade&' . osc_csrf_token_url()); ?>, {credentials: 'same-origin'})
                .then(function (response) {
                    return response.json();
                })
                .then(function (json) {
                    if (json.error == 0) {
                        report(verdict('success', t.upgraded, {label: t.notes, url: t.notesUrl}));
                    } else if (json.error == 2) {
                        report(verdict('warning', t.partial), json.message);
                    } else {
                        report(verdict('danger', t.failed), json.message);
                    }
                })
                .catch(function () {
                    report(verdict('danger', t.failed));
                });
            <?php } elseif ($isAvailable) { ?>
            var notes = document.getElementById('upgrade-release-notes');
            fetch(<?php echo json_encode('https://api.github.com/repos/mindstellar/Shopclass/releases/tags/' . rawurlencode($remoteVersion)); ?>)
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (json) {
                    if (!notes || !json || typeof json.body !== 'string' || json.body.trim() === '') {
                        return;
                    }
                    // Plain text only: headings, bullets and paragraphs, never the body's own markup.
                    var list = null;
                    json.body.replace(/\r\n/g, '\n').split('\n').forEach(function (raw) {
                        var line = raw.trim();
                        var bullet = line.match(/^[*-]\s+(.*)$/);
                        if (bullet) {
                            if (!list) {
                                list = document.createElement('ul');
                                notes.appendChild(list);
                            }
                            var item = document.createElement('li');
                            item.textContent = bullet[1];
                            list.appendChild(item);
                            return;
                        }
                        list = null;
                        if (line === '') {
                            return;
                        }
                        var heading = line.match(/^#{1,6}\s*(.*)$/);
                        var el = document.createElement(heading ? 'h4' : 'p');
                        el.textContent = heading ? heading[1] : line;
                        notes.appendChild(el);
                    });
                    notes.hidden = false;
                })
                .catch(function () {
                });
            <?php } else { ?>
            fetch(<?php echo json_encode(osc_admin_base_url(true) . '?page=ajax&action=check_version'); ?>, {credentials: 'include'})
                .catch(function () {
                });
            <?php } ?>
        })();
    </script>
    <?php
});

// The files backup moved to Tools > Backup and restore; old links to it follow. Remove in 7.0.
osc_add_hook('admin_footer', static function () { ?>
    <script>
        if (location.hash === '#backup-files') {
            location.replace(<?php echo json_encode(osc_admin_base_url(true) . '?page=tools&action=backup'); ?>);
        }
    </script>
<?php });

/**
 * Filter callback for `render-wrapper`: the CSS class the page wrapper renders with.
 *
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Upgrade'),
    'help'    => __("Check to see if you're using the latest version of Shopclass. If you're not, "
                    . 'the system will let you know so you can update and use the newest features.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Upgrade Shopclass')); ?>
    <div class="upgrade-tool">
        <p class="form-intro">
            <?php
            printf(
                osc_esc_html(__('Your Shopclass installation can be auto-upgraded. %1$s: the database and oc-content. You can also upgrade Shopclass manually, more information in the %2$s.')),
                '<a href="' . osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=backup') . '">' . osc_esc_html(__('Back up first')) . '</a>',
                '<a href="https://docs.mindstellar.com/">' . osc_esc_html(__('Documentation')) . '</a>'
            );
            ?>
        </p>
        <div id="steps_div">
            <div id="steps">
                <?php if ($selfUpdateOff) {
                    osc_admin_verdict(array(array(
                        'tone' => 'info',
                        'text' => __('This installation runs from a container image. Update by deploying a newer image tag; the database is migrated automatically when the container starts.'),
                    )));
                } elseif (!$isAvailable) {
                    osc_admin_verdict(array(), sprintf(__('Shopclass is up to date. You have version %s.'), osc_get_preference('version')));
                } elseif ($running) { ?>
                    <div class="upgrade-running" role="status">
                        <span class="spinner-border upgrade-running-spinner" aria-hidden="true"></span>
                        <div>
                            <p class="upgrade-running-line"><?php echo osc_esc_html(sprintf(__('Upgrading Shopclass to %s.'), $remoteVersion)); ?></p>
                            <p class="upgrade-running-note"><?php _e('This can take a few minutes. Keep this page open.'); ?></p>
                        </div>
                    </div>
                <?php } else {
                    osc_admin_verdict(array(array(
                        'tone'   => 'info',
                        'text'   => sprintf(__('Shopclass %1$s is available. You have %2$s.'), $remoteVersion, osc_get_preference('version')),
                        'action' => array(
                            'label'   => sprintf(__('Upgrade to %s'), $remoteVersion),
                            'variant' => 'primary',
                            'url'     => osc_admin_base_url(true) . '?page=tools&action=upgrade&confirm=true',
                        ),
                    ))); ?>
                    <p class="upgrade-tool-note">
                        <?php _e('Please note that this upgrade may take a few minutes to complete.'); ?>
                        <?php _e('Please be aware that this upgrade will overwrite any existing modification you have made to core files.'); ?>
                    </p>
                    <section class="upgrade-tool-notes" id="upgrade-release-notes" hidden>
                        <?php osc_admin_form_section(sprintf(__("What's new in %s"), $remoteVersion), array('spaced' => true)); ?>
                    </section>
                <?php } ?>
            </div>
        </div>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
