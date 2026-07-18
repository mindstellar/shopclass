<?php if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
} ?>
<?php if (isset($error) && $error != '') { ?>
    <div class="alert alert-danger shadow">
        <?php echo $error['error'] ?>
    </div>
<?php } ?>
<form class="p-3" action="install.php" method="post">
    <input type="hidden" name="step" value="3" />
    <h2 class="display-6 mb-3"><?php _e('Database information'); ?></h2>
    <div class="form-table">
        <div class="row mb-3">
            <label for="dbhost" class="col-md-3 col-sm-6 col-form-label text"><strong><?php _e('Host'); ?></strong></label>
            <div class="col-md-3 col-sm-6">
                <input class="form-control" type="text" id="dbhost" name="dbhost" value="<?php echo $form_data['dbhost']??'localhost'; ?>" size="25" />
            </div>
            <div class="small"><?php _e('Server name or IP where the database engine resides'); ?></div>
        </div>
        <div class="row mb-3">
            <label for="dbname" class="col-md-3 col-sm-6 col-form-label"><strong><?php _e('Database name');
            ?></strong></label>
            <div class="col-md-3 col-sm-6">
                <input class="form-control" type="text" id="dbname" name="dbname" value="<?php echo $form_data['dbname']??'osclass'; ?>" size="25" />
            </div>
            <div class="small"><?php _e('The name of the database you want to run Shopclass in');
            ?></div>
        </div>
        <div class="row mb-3">
            <label for="username" class="col-md-3 col-sm-6 col-form-label"><strong><?php _e('User Name');
            ?></strong></label>
            <div class="col-md-3 col-sm-6">
                <input class="form-control" type="text" id="username" name="username" value="<?php echo $form_data['username']??'osclass'; ?>" size="25" />
            </div>
            <div class="small"><?php _e('Your MySQL username'); ?></div>
        </div>
        <div class="row mb-3">
            <label for="password" class="col-md-3 col-sm-6 col-form-label"><strong><?php _e('Password');
            ?></strong></label>
            <div class="col-md-3 col-sm-6">
                <input class="form-control" type="password" id="password" name="password" value="<?php echo $form_data['password']??''; ?>" size="25" autocomplete="off" />
            </div>
            <div class="small"><?php _e('Your MySQL password'); ?></div>
        </div>
        <div class="row mb-3">
            <label for="tableprefix" class="col-md-3 col-sm-6 col-form-label"><strong><?php _e('Table prefix');
            ?></strong></label>
            <div class="col-md-3 col-sm-6">
                <input class="form-control" type="text" id="tableprefix" name="tableprefix" value="<?php echo $form_data['tableprefix']??'oc_'; ?>" size="25" />
            </div>
            <div class="small"><?php _e('If you want to run multiple Shopclass installations in a single database, change this'); ?></div>
        </div>
        <div class="accordion mb-3" id="accordianAdvance">
            <div class="accordion-item">
                <h2 class="accordion-header" id="headingAdvance">
                    <button class="accordion-button <?php echo (Params::getParam('createdb') == '1') ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAdvance" aria-expanded="false" aria-controls="accordianAdvance">
                        <?php _e('Advanced'); ?>
                    </button>
                </h2>
                <div id="collapseAdvance" class="accordion-collapse collapse hide" aria-labelledby="headingAdvance" data-bs-parent="#accordianAdvance">
                    <div class="accordion-body">
                        <div class="row mb-3">
                            <div class="col-md-8 col-sm-12">
                                <input type="checkbox" id="createdb" name="createdb" onclick="db_admin();" value="1" <?php echo (Params::getParam('createdb') == '1') ? 'checked="checked"' : ''; ?> />
                                <label for="createdb"><strong><?php _e('Create DB'); ?></strong></label>
                                <div class="small"><?php _e('Check here if the database is not created and you want to create it now'); ?></div>
                            </div>
                        </div>
                        <div id="admin_username_row" class="row mb-3">
                            <label class="col-md-3 col-sm-6 col-form-label" for="admin_username"><strong><?php _e('DB admin username'); ?></strong></label>
                            <div class="col-md-4 col-sm-6">
                                <input class="form-control" type="text" id="admin_username" name="admin_username" size="25" value="<?php echo $form_data['admin_username']??''; ?>"  <?php echo (Params::getParam('createdb') == '1') ? '' : 'disabled="disabled"'; ?> />
                            </div>
                        </div>
                        <div id="admin_password_row" class="row mb-3">
                            <label class="col-md-3 col-sm-6 col-form-label" for="admin_password"><strong><?php _e('DB admin password'); ?></strong></label>
                            <div class="col-md-4 col-sm-6">
                                <input class="form-control" type="password" id="admin_password" name="admin_password" size="25" disabled="disabled" autocomplete="off" value="<?php echo $form_data['admin_password']??''; ?>" <?php echo (Params::getParam('createdb') == '1') ? '' : 'disabled="disabled"'; ?> />
                                <span id="password_copied"><?php _e('Password copied from above'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            $(document).ready(function() {
                var username =
                    $('#createdb').on('click', function() {
                        if ($("#createdb").is(':checked')) {
                            if ($("#admin_username").val() == '') {
                                $("#admin_username").val($("#username").val());
                            }
                            if ($("#admin_password").val() == '') {
                                $("#admin_password").val($("#password").val());
                                $("#password_copied").show();
                            }
                        } else {
                            $("#password_copied").hide();
                        }
                    });
                $("#password_copied").hide();
            });
        </script>
    </div>
    <input type="submit" class="btn btn-primary" name="submit" value="Next" />
    <div class="clear"></div>
</form>