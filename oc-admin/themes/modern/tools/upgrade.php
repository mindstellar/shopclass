<?php

use mindstellar\upgrade\Osclass;

if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}

//customize Head
function customHead()
{
    ?>
    <script>
        // #steps_div hide
        var steps_div = document.getElementById('steps_div');
        if (steps_div) {
            steps_div.style.display = 'none';
        }

        <?php
        $update_core_json = osc_get_preference('update_core_json');
        $is_upgrade_available = false;
        $remoteVersion = '';
        if (!empty($update_core_json)) {
            $OsclassUpgrade = (new Osclass(json_decode($update_core_json, true)));
            $is_upgrade_available = $update_core_json && $OsclassUpgrade->isUpgradable();
            $remoteVersion = $OsclassUpgrade->getNewVersion();
        }
        ?>
        var steps = document.getElementById('steps');
        var remoteVersion = '<?php echo $remoteVersion; ?>';
        // get release body from github api url using remote version as tag
        var releaseUrl = 'https://api.github.com/repos/mindstellar/Osclass/releases/tags/' + remoteVersion;
        var isUpgradeAvailable = <?php echo $is_upgrade_available ? 'true' : 'false'; ?>;
        var upgradeUrl = '<?php echo osc_admin_base_url(true) . '?page=tools&action=upgrade&confirm=true'; ?>';
        var upgradeActionUrl = '<?php echo osc_admin_base_url(true) . '?page=ajax&action=upgrade&' . osc_csrf_token_url(); ?>';
        // check current url has confirm argument and is set to true
        var isConfirm = false;
        if (window.location.href.indexOf('confirm=true') !== -1) {
            isConfirm = true;
        }
        // if upgrade available and not confirm, show that upgrade is available with remote version and a button to upgrade
        if (isUpgradeAvailable && !isConfirm) {
            // append to steps
            var message1 = document.createElement('div');
            message1.className = 'step';
            message1.innerHTML = '<h3><?php sprintf(__('Upgrade is available for (Current version %s)'), osc_get_preference('version')); ?></h3>' +
                '<h3><?php echo sprintf(__('Hey, a new version %s is available for download. Check details below.'), $remoteVersion); ?></h3>'
            steps.appendChild(message1);
            steps.appendChild(document.createElement('hr'));
            // read body from upgradeJson and parse Markdown
            var message2 = document.createElement('div');
            message2.className = 'step';
            message2.innerHTML = '<h3><?php _e('Upgrade Notes:'); ?></h3>' +
                '<p><?php _e('Please note that this upgrade may take a few minutes to complete.'); ?> ' +
                '<?php _e('Once the upgrade is complete, you will be redirected to the Admin Control Panel.'); ?> ' +
                '<?php _e('Please be aware that this upgrade will overwrite any existing modification you have made to core files.'); ?></p>' +
                '<p id="releaseChangelog"></p>' +
                '<p><a href="' + upgradeUrl + '" class="button btn btn-warning"><?php _e('Upgrade Now to ') ?>' + remoteVersion + '</a></p>';
            steps.appendChild(message2);
            // make fetch request to github api to get release body
            fetch(releaseUrl)
                .then(function(response) {
                    return response.json();
                })
                .then(function(json) {
                    var md = json.body;
                    // any string start with #, ##, ###  <h3>
                    md = md.replace(/^(#{1,6})(.*)/gm, '<h3>$2</h3>');
                    // any string start with new line and followed by * will be converted to list item
                    md = md.replace(/^\n\*(.*)/gm, '<li>$1</li>');
                    // any string start with new line followed by /r/n will be converted to <br>
                    md = md.replace(/^\n(\/r\/n)/gm, '<br>$1');
                    document.getElementById('releaseChangelog').innerHTML = md;
                })
                .catch(function(error) {
                    console.log(error);
                });
            // display steps div
            steps_div.style.display = 'block';

        } else if (isUpgradeAvailable && isConfirm) {
            steps_div.style.display = 'block';
            // append a spinner to steps
            var message1 = document.createElement('div');
            message1.className = 'step';
            message1.innerHTML = '<h3><span class="spinner-border text-secondary" style="width:1.2rem;height:1.2rem" role="status"></span>' +
                '<?php echo osc_esc_js(__('Upgrading your Osclass installation (this could take a while):')); ?>' +
                '</h3>';
            steps.innerHTML = '';
            steps.appendChild(message1);
            // make fetch request to upgradeActionUrl
            fetch(upgradeActionUrl)
                .then(function(response) {
                    return response.json();
                })
                .then(function(json) {
                    // if upgrade is successful
                    if (json.error == 0 || json.error == 2) {
                        if (json.error == 0) {
                            // append to steps
                            var message2 = document.createElement('div');
                            message2.className = 'step';
                            message2.innerHTML = '<h3 class="text-success strong"><?php _e('Upgrade Successful'); ?></h3>' +
                                '<p><?php _e('Your Osclass installation has been upgraded to version ') ?>' + remoteVersion + '</p>' +
                                '<p><a href="<?php echo osc_esc_js(osc_admin_base_url(true)); ?>?page=tools&action=version" class="button btn btn-success"><?php _e('Check release notes'); ?></a></p>';
                            steps.innerHTML = '';
                            steps.appendChild(message2);
                        } else {
                            // append to steps
                            var message2 = document.createElement('div');
                            message2.className = 'step';
                            message2.innerHTML = '<h3 class="text-danger"><?php _e('Upgrade completed with few errors'); ?></h3>'
                            message2.innerHTML += json.message;
                            steps.innerHTML = '';
                            steps.appendChild(message2);
                        }

                        //window.location = '<?php echo osc_admin_base_url(true); ?>?page=tools&action=version';
                    } else {
                        // if upgrade failed
                        var message3 = document.createElement('div');
                        message3.className = 'step';
                        message3.innerHTML = '<h3 class=text-danger strong><?php _e('Upgrade Failed'); ?></h3>'
                        // append error message html
                        var message4 = document.createElement('div');
                        message4.className = 'step';
                        message4.innerHTML = json.message
                        steps.innerHTML = '';
                        steps.appendChild(message3);
                        steps.appendChild(message4);
                    }
                }).catch(function(error) {
                    console.log(error);
                });
        } else {
            // make a fetch request to get the latest version
            var checkVersionUrl = '<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_version'
            fetch(checkVersionUrl, {
                method: 'GET',
                credentials: 'include'
            }).then(function(response) {
                return response.json();
            }).then(function(json) {
                console.log(json.error === 0 ? json.msg : ('error: ' + json.msg));
            });
            // append a message to steps
            var message1 = document.createElement('div');
            message1.className = 'step';
            message1.innerHTML = '<h3><?php _e('No Upgrade Available'); ?></h3>' +
                '<p><?php echo osc_esc_js(__('Congratulations! Your Osclass installation is up to date!')); ?></p>'
            steps.innerHTML = '';
            steps.appendChild(message1);
            steps_div.style.display = 'block';
        }
    </script>
    <?php
}


//TODO Not using it right now
osc_add_hook('admin_footer', 'customHead', 10);

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


function addHelp()
{
    echo '<p>'
        . __("Check to see if you're using the latest version of Osclass. If you're not, 
        the system will let you know so you can update and use the newest features.")
        . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Tools'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
    </h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Upgrade &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="backup-setting">
    <!-- settings form -->
    <div id="backup-settings">
        <h2 class="render-title"><?php _e('Upgrade'); ?></h2>
        <form>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="tools upgrade">
                            <p class="text">
                                <?php
                                printf(
                                    __('Your Osclass installation can be auto-upgraded. 
                                        Please, back up your database and the folder oc-content before attempting to 
                                        upgrade your Osclass installation. 
                                        You can also upgrade Osclass manually, more information in the %s'),
                                    '<a href="https://docs.mindstellar.com/">Documentation</a>'
                                );
                                ?>
                            </p>
                            <div id="steps_div">
                                <div id="steps">

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>