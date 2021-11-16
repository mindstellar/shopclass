<?php osc_current_web_theme_path('head.php') ; ?>
    <body>
    <?php osc_current_web_theme_path('header.php') ; ?>
    <div class="row">
    <div class="col-md-4">
	<?php include 'user-sidebar.php'; ?>
	</div>
        <div class="col-md-8">
        <div class="tfc-item">
                <div class="panel-heading">
                    <div class="panel-title"><?php _e('Change you email address', 'shopclass') ; ?></div>
                </div>
                <div class="content user_account panel-body">
                
                <div id="main" class="modify_profile">
                    <legend><?php _e('Change your e-mail', 'shopclass') ; ?></legend>
                    <form class="tfc-form form-group" action="<?php echo osc_base_url(true) ; ?>" method="post">
                        <input type="hidden" name="page" value="user" />
                        <input type="hidden" name="action" value="change_email_post" />
                        <fieldset class="form-group">
                            <p>
                                <label for="email"><?php _e('Current e-mail:', 'shopclass') ; ?></label>
                                <strong><?php echo osc_logged_user_email(); ?></strong>
                            </p>
                            <p class="col-md-6">
                                <label for="new_email"><?php _e('New e-mail', 'shopclass') ; ?> *</label>
                                <input type="text" name="new_email" id="new_email" value="" />
                            </p>
                            <div style="clear:both;"></div>
                            
                        </fieldset>
                        <button class="btn btn-default" type="submit"><?php _e('Update', 'shopclass') ; ?></button>
                    </form>
                </div>
                </div>
            </div>
            
        </div></div>
        <?php osc_current_web_theme_path('footer.php') ; ?>
    </body>
</html>
