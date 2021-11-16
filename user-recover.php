<?php osc_current_web_theme_path('head.php') ; ?>
    <!--suppress ALL -->
<body>
    <?php osc_current_web_theme_path('header.php') ; ?>
        <div class="container panel panel-body panel-primary">
            <div class="content tfc-form user_forms">
                <div class="inner ">
                    <legend><?php _e('Recover your password', 'shopclass') ; ?></legend>
                    <form action="<?php echo osc_base_url(true) ; ?>" method="post" >
                        <input type="hidden" name="page" value="login" />
                        <input type="hidden" name="action" value="recover_post" />
                        <fieldset class="form-group">
                        	<div class="form-group">
                            <label class="col-md-2 control-label" for="email"><?php _e('E-mail', 'shopclass') ; ?></label> 
                            <div class="input-group col-md-8">
  							<span class="input-group-addon"><i class="fa fa-envelope"></i></span>
                            <?php UserForm::email_text() ; ?>
                            </div>
                            </div>
                            
                            <?php osc_show_recaptcha('recover_password'); ?>
                            
                        </fieldset>
                        <button class="btn btn-default col-md-offset-2" type="submit"><?php _e('Send me a new password', 'shopclass') ; ?></button>
                    </form>
                </div>
            </div>
           
        </div>
         <?php osc_current_web_theme_path('footer.php') ; ?>
    </body>
</html>
