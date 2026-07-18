<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Osclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Tools'); ?></h1>
    <?php
}


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
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            <?php if (Params::getParam('confirm') === 'true') {?>
            var output = document.getElementById('output');
            if (output) { output.style.display = ''; }
            var tohide = document.getElementById('tohide');
            if (tohide) { tohide.style.display = 'none'; }

            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=upgrade_db&skipdb=<?php echo Params::getParam('skipdb')?>&<?php echo osc_csrf_token_url(); ?>', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json();
            }).then(function (data) {
                var loading = document.getElementById('loading_image');
                if (loading) { loading.style.display = 'none'; }
                var result = document.getElementById('result');
                if (result) {
                    result.innerHTML = (data.error === 1)
                        ? 'Error: ' + data.message.replace(/\n/g, '<br />')
                        : 'Success: ' + data.message + '<br />';
                }
            });
            <?php } ?>
        });
    </script>
<?php }


osc_add_hook('admin_header', 'customHead', 10);

osc_current_admin_theme_path('parts/header.php'); ?>

<div id="backup-settings">
    <h2 class="render-title"><?php _e('Upgrade'); ?></h2>
    <div id="result">
        <div id="output" style="display:none">
            <span class="spinner-border text-secondary" style="width:1.2rem;height:1.2rem" role="status"></span>
            <?php _e('Upgrading your Osclass installation (this could take a while): ', 'admin'); ?>
        </div>
        <div id="tohide">
            <p>
                <?php _e('You have uploaded a new version of Osclass, you need to upgrade Osclass for it to work correctly.'); ?>
            </p>
            <a class="btn btn-dim"
               href="<?php echo osc_admin_base_url(true); ?>?page=upgrade&confirm=true"><?php _e('Upgrade now'); ?></a>
        </div>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
