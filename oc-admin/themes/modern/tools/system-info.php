<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}

use mindstellar\admin\SystemChecks;

// The checks live in SystemChecks. The oscsi_* helpers stay defined for plugins that call them.

if (!function_exists('oscsi_bytes')) {
    /**
     * PHP ini shorthand ("128M") to a byte count. -1 (unlimited) stays -1.
     *
     * @param string $value
     *
     * @return int
     */
    function oscsi_bytes($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        if ((int)$value === -1) {
            return -1;
        }
        $unit   = strtolower(substr($value, -1));
        $number = (int)$value;
        switch ($unit) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }
}

if (!function_exists('oscsi_size')) {
    /**
     * A human size for a byte count. -1 is unlimited.
     *
     * @param int $bytes
     *
     * @return string
     */
    function oscsi_size($bytes)
    {
        if ($bytes === -1) {
            return __('unlimited');
        }
        if (!is_numeric($bytes) || $bytes <= 0) {
            return '—';
        }
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i     = (int)min(floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }
}

if (!function_exists('oscsi_row')) {
    /**
     * One ledger row: a verdict word, the fact, a note (may hold <code>/<strong>), a value,
     * and at most one action.
     *
     * @param string                                 $state  ok | warn | danger | off
     * @param string                                 $word   the verdict, spelled out
     * @param string                                 $name
     * @param string                                 $note   developer-authored copy; callers escape any interpolated values
     * @param string                                 $value
     * @param array{label:string,url:string}|array{} $action
     *
     * @return void
     */
    function oscsi_row($state, $word, $name, $note = '', $value = '', $action = array())
    {
        ?>
        <div class="sysinfo-row">
            <span class="sysinfo-state sysinfo-state-<?php echo osc_esc_html($state); ?>"><?php echo osc_esc_html($word); ?></span>
            <div class="sysinfo-fact">
                <div class="sysinfo-name"><?php echo osc_esc_html($name); ?></div>
                <?php if ($note !== '') { ?>
                    <p class="sysinfo-note"><?php echo $note; ?></p>
                <?php } ?>
            </div>
            <div class="sysinfo-trailing">
                <?php if ($value !== '') { ?>
                    <span class="sysinfo-value"><?php echo osc_esc_html($value); ?></span>
                <?php } ?>
                <?php if (!empty($action)) { ?>
                    <a class="btn btn-sm btn-dim sysinfo-action" href="<?php echo osc_esc_html($action['url']); ?>"><?php echo osc_esc_html($action['label']); ?></a>
                <?php } ?>
            </div>
        </div>
        <?php
    }
}

$view   = View::newInstance();
$tab    = (string) $view->_get('sysinfo_tab');
$env    = (array) $view->_get('sysinfo_env');
$report = (array) $view->_get('sysinfo_report');
$tabs   = SystemChecks::labels();

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('System info'),
    'help'    => __('A checked view of your site: what needs doing, background jobs, security, the cache, the database, and the server it runs on.'),
));

// Backup and restore left the old Database page; links to those parts follow. Remove in 7.0.
osc_add_hook('admin_footer', static function () { ?>
    <script>
        if (location.hash === '#backup' || location.hash === '#restore') {
            location.replace(<?php echo json_encode(osc_admin_base_url(true) . '?page=tools&action=backup'); ?> + location.hash);
        }
    </script>
<?php });

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('System info')); ?>
    <div id="system-info" class="sysinfo">
        <ul class="osc-tabnav sysinfo-tabs">
            <?php foreach ($tabs as $key => $label) { ?>
                <li>
                    <a<?php echo $key === $tab ? ' class="is-active" aria-current="page"' : ''; ?>
                        href="<?php echo osc_esc_html(SystemChecks::url($env, $key)); ?>"><?php echo osc_esc_html($label); ?></a>
                </li>
            <?php } ?>
        </ul>

        <?php
        // The update's result joins the tab's verdict: one box, not two.
        $upgrade = $view->_get('db_upgrade');
        $issues  = $report['issues'] ?? array();
        $healthy = SystemChecks::healthy($tab);
        if (is_array($upgrade) && $upgrade['error'] === 0) {
            $ran = $upgrade['applied'] === array()
                ? __('Nothing was waiting. The database is up to date.')
                : sprintf(_n('The database is updated. %d update ran.', 'The database is updated. %d updates ran.', count($upgrade['applied'])), count($upgrade['applied']));
            if ($issues === array()) {
                $healthy = $ran;
            } else {
                $issues[] = array('tone' => 'info', 'text' => $ran);
            }
        } elseif (is_array($upgrade)) {
            array_unshift($issues, array(
                'tone' => 'danger',
                'text' => $upgrade['message'] !== '' ? $upgrade['message'] : __('The database update failed.'),
            ));
        }
        osc_admin_verdict($issues, $healthy); ?>

        <?php foreach (($report['groups'] ?? array()) as $group) { ?>
            <section class="sysinfo-group">
                <?php osc_admin_form_section($group['title'], isset($group['link']) ? array(
                    'intro_html' => '<a href="' . osc_esc_html($group['link']['url']) . '">' . osc_esc_html($group['link']['label']) . '</a>',
                ) : array()); ?>
                <?php osc_admin_panel_open('', array('class' => 'sysinfo-facts')); ?>
                <?php osc_admin_definition($group['rows']); ?>
                <?php osc_admin_panel_close(); ?>
            </section>
        <?php } ?>

        <?php if ($tab !== 'overview') {
            require __DIR__ . '/system-info/' . $tab . '.php';
        } ?>
    </div>

    <?php if (($env['pending'] ?? array()) !== array()) {
        osc_admin_confirm_dialog(array(
            'id'      => 'db-update-dialog',
            'tone'    => 'plain',
            'method'  => 'post',
            'url'     => SystemChecks::url($env, 'database', 'db-update'),
            'fields'  => array('upgrade' => '1'),
            'title'   => __('Run the database update?'),
            'text'    => __('This applies the waiting updates in order. Keep the page open until it finishes. Take a backup first.'),
            'confirm' => __('Run database update'),
        ));
    } ?>
<?php
osc_current_admin_theme_path('parts/footer.php');
