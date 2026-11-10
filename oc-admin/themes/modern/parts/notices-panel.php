<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}

use mindstellar\admin\AdminNotice;
use mindstellar\admin\AdminNoticeView;

// The inside of the bell panel, also sent back after each Hide and Show.
$oscPanelView = osc_admin_notices();
$oscPanelCap  = 30;
$oscPanelBase = osc_admin_base_url(true);

$oscPanelRow = static function (AdminNotice $n, string $state): string {
    $icons = ['action' => 'bi-exclamation-octagon-fill', 'headsup' => 'bi-exclamation-triangle-fill', 'hidden' => 'bi-eye-slash'];
    $text  = osc_esc_html($n->text);
    $id    = osc_esc_html($n->id);
    $links = $state === 'hidden'
        ? '<span class="osc-notice-when">' . osc_esc_html(sprintf(__('Comes back %s'), osc_format_date(date('Y-m-d H:i:s', $n->hiddenUntil)))) . '</span>' . AdminNoticeView::actions($n, true, false)
        : AdminNoticeView::actions($n, false);

    return '<li><div class="osc-notice-row is-' . $state . '" data-osc-notice="' . $id . '">'
        . '<i class="bi ' . $icons[$state] . ' osc-notice-mark" aria-hidden="true"></i>'
        . '<p class="osc-notice-text">' . $text . '</p>'
        . '<div class="osc-notice-links">' . $links . '</div></div></li>';
};

$oscPanelGroup = static function (string $title, int $total, array $rows, string $state, string $id, string $extra = '') use ($oscPanelRow): string {
    if ($rows === []) {
        return '';
    }

    return '<section class="osc-notice-group' . ($state === 'hidden' ? ' osc-notice-group-hidden' : '') . '"' . $extra . ' aria-labelledby="' . $id . '">'
        . '<h3 id="' . $id . '">' . osc_esc_html($title) . '<span class="osc-notice-n">' . $total . '</span></h3>'
        . '<ul>' . implode('', array_map(static fn (AdminNotice $n): string => $oscPanelRow($n, $state), $rows)) . '</ul></section>';
};

$oscPanelActive = array_slice($oscPanelView->active, 0, $oscPanelCap);
$oscPanelHidden = array_slice($oscPanelView->hidden, 0, $oscPanelCap);
$oscPanelAction = array_values(array_filter($oscPanelActive, static fn (AdminNotice $n): bool => $n->group() === 'action'));
$oscPanelUp     = array_values(array_filter($oscPanelActive, static fn (AdminNotice $n): bool => $n->group() !== 'action'));
$oscPanelHiddenTotal = count($oscPanelView->hidden);
?>
<header class="osc-notice-head">
    <h2 id="oscNoticeTitle"><?php _e('Notices'); ?></h2>
    <button type="button" class="osc-notice-close" data-osc-notice-close aria-label="<?php echo osc_esc_html(__('Close')); ?>">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
</header>
<div class="osc-notice-body">
    <?php
    echo $oscPanelGroup(__('Needs action'), $oscPanelView->actionCount(), $oscPanelAction, 'action', 'oscNoticeAction');
    echo $oscPanelGroup(__('Heads-up'), $oscPanelView->count() - $oscPanelView->actionCount(), $oscPanelUp, 'headsup', 'oscNoticeHeadsup');
    if ($oscPanelActive === []) {
        echo '<p class="osc-notice-empty">' . osc_esc_html(__('Nothing needs attention.')) . '</p>';
    }
    echo $oscPanelGroup(__('Hidden by you'), $oscPanelHiddenTotal, $oscPanelHidden, 'hidden', 'oscNoticeHidden', ' hidden');
    ?>
</div>
<footer class="osc-notice-foot">
    <?php if ($oscPanelHiddenTotal > 0) { ?>
    <span class="osc-notice-hidden-count"><?php echo osc_esc_html(sprintf(_n('%d hidden', '%d hidden', $oscPanelHiddenTotal), $oscPanelHiddenTotal)); ?>
        <button type="button" class="osc-notice-btn" data-osc-notice-toggle aria-expanded="false"
                data-label-open="<?php echo osc_esc_html(__('Show')); ?>" data-label-close="<?php echo osc_esc_html(__('Close list')); ?>"><?php _e('Show'); ?></button>
    </span>
    <?php } ?>
    <a class="osc-notice-all" href="<?php echo osc_esc_html($oscPanelBase . '?page=notices'); ?>"><?php _e('See all'); ?></a>
</footer>
