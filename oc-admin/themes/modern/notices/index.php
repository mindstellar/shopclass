<?php if (!defined('OC_ADMIN')) {
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

use mindstellar\admin\AdminNotice;
use mindstellar\admin\AdminNoticeView;

$tab      = (string) __get('tab');
$active   = (array) __get('active');
$hidden   = (array) __get('hidden');
$isHidden = $tab === 'hidden';
$rows     = $isHidden ? $hidden : $active;
$base     = osc_admin_base_url(true) . '?page=notices';
$menu     = AdminMenu::getInstance()->get_array_menu();

$areaOf = static function (AdminNotice $n) use ($menu): string {
    $section = $menu[$n->menu] ?? null;

    return is_array($section) && isset($section[0]) ? \mindstellar\utility\Sanitize::stripTags((string) $section[0]) : '';
};
$day = static fn (int $time): string => osc_admin_date(date('Y-m-d H:i:s', $time), true);

osc_admin_page(array(
    'section' => __('Notices'),
    'title'   => __('Notices'),
    'heading' => __('Notices'),
));
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_body_open(); ?>

    <?php osc_admin_tab_nav(array(
        array('label' => __('Active'), 'url' => $base, 'active' => !$isHidden, 'count' => count($active)),
        array('label' => __('Hidden'), 'url' => $base . '&tab=hidden', 'active' => $isHidden, 'count' => count($hidden)),
    ), '', array('aria_label' => __('Notices'))); ?>

    <?php if ($rows === array()) {
        osc_admin_empty(array('title' => $isHidden ? __('No hidden notices.') : __('Nothing needs attention.')));
    } else {
        $tableRows = array();
        foreach ($rows as $n) {
            $state  = $isHidden ? 'notice-hidden' : 'notice-' . $n->group();
            $word   = $isHidden ? __('Hidden') : ($n->group() === 'action' ? __('Needs action') : __('Heads-up'));
            $tableRows[] = array(
                'attrs' => array('data-osc-notice' => $n->id),
                'cells' => array(
                    'status'  => array('status' => array($state, $word)),
                    'message' => array('render' => static function () use ($n): void { ?>
                        <span class="osc-notice-msg"><b><?php echo osc_esc_html($n->text); ?></b>
                        <?php if ($n->detail !== '') { ?><span><?php echo osc_esc_html($n->detail); ?></span><?php } ?></span>
                    <?php }),
                    'area'    => $areaOf($n),
                    'when'    => array('html' => $isHidden ? $day($n->hiddenUntil) : ($n->since > 0 ? $day($n->since) : '')),
                    'actions' => array('html' => '<span class="osc-notice-links">' . AdminNoticeView::actions($n, $isHidden) . '</span>'),
                ),
            );
        }
        osc_admin_table(array(
            'status'  => __('Status'),
            'message' => __('Message'),
            'area'    => __('Area'),
            'when'    => $isHidden ? __('Comes back') : __('Since'),
            'actions' => __('Actions'),
        ), $tableRows, array('class' => 'osc-notices-table', 'stack' => true));
    } ?>
<?php osc_admin_body_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
