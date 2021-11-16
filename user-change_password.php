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
                    <div class="panel-title"><?php _e('Change your password', 'shopclass') ; ?></div>
                </div>
                <div class="content user_account panel-body">
                
                <div id="main" class="modify_profile">
                    <legend><?php _e('Change your password', 'shopclass') ; ?></legend>
                    <form class="tfc-form form-group"action="<?php echo osc_base_url(true) ; ?>" method="post">
                        <input type="hidden" name="page" value="user" />
                        <input type="hidden" name="action" value="change_password_post" />
                        <fieldset>
                            <p>
                                <label for="password"><?php _e('Current password', 'shopclass') ; ?> *</label>
                                <input class="form-control" placeholder="<?php echo osc_esc_html(__('Enter Current Password','shopclass')); ?>" type="password" name="password" id="password" value="" />
                            </p>
                            <p>
                                <label for="new_password"><?php _e('New password', 'shopclass') ; ?> *</label>
                                <input class="form-control" placeholder="<?php echo osc_esc_html(__('Enter New Password','shopclass')); ?>" type="password" name="new_password" id="new_password" value="" />
                            </p>
                            <p>
                                <label for="new_password2"><?php _e('Repeat new password', 'shopclass') ; ?> *</label>
                                <input class="form-control" placeholder="<?php echo osc_esc_html(__('Retype New Password','shopclass')); ?>" type="password" name="new_password2" id="new_password2" value="" />
                            </p>
                            <div style="clear:both;"></div>
                            <button class="btn btn-default" type="submit"><?php _e('Update', 'shopclass') ; ?></button>
                        </fieldset>
                    </form>
                </div>
                </div>
            </div>
            
        </div></div>
        <?php osc_current_web_theme_path('footer.php') ; ?>
    </body>
</html>
