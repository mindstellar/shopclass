<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

$all      = osc_get_preference('location_todo');
$worktodo = LocationsTmp::newInstance()->count();

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


function customHead()
{
    $all = osc_get_preference('location_todo');
    if ($all == '') {
        $all = 0;
    }
    $worktodo = LocationsTmp::newInstance()->count();
    ?>
    <script type="text/javascript">
        function reload() {
            window.location = '<?php echo osc_admin_base_url(true) . '?page=tools&action=locations'; ?>';
        }

        function ajax_() {
            fetch('<?php echo osc_admin_base_url(true)?>?page=ajax&action=location_stats&<?php echo osc_csrf_token_url(); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json();
            }).then(function (data) {
                var pct = document.querySelector('span#percent');
                if (data.status == 'done') {
                    if (pct) { pct.innerHTML = 100; }
                    document.querySelectorAll('.spinner-border').forEach(function (el) { el.remove(); });
                } else {
                    var pending = data.pending;
                    var all = <?php echo osc_esc_js($all);?>;
                    var percent = parseInt(((all - pending) * 100) / all);
                    if (pct) { pct.innerHTML = percent; }
                    ajax_();
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (<?php echo $worktodo;?> > 0) {
                ajax_();
            }
        });
    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Statistics'); ?></h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Location stats &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="locations-stats-setting">
    <!-- settings form -->
    <div id="">
        <h2 class="render-title"><?php _e('Locations stats'); ?></h2>
        <?php if ($worktodo > 0) { ?>
            <p>
                <span id="percent">0</span> % <?php _e('Complete'); ?> <span class="spinner-border spinner-border-sm text-primary"></span>
            </p>
        <?php } ?>
        <p>
            <?php _e('You can recalculate your location stats if they are incorrect.'); ?>
        </p>
        <form action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="action" value="locations_post"/>
            <input type="hidden" name="page" value="tools"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-actions">
                        <input id="button_save" type="submit"
                               value="<?php echo osc_esc_html(__('Calculate location stats')); ?>"
                               class="btn btn-submit"/>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
