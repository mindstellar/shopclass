<?php osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="col-md-12">
    <div class="container panel panel-body panel-success">
        <?php UserForm::js_validation(); ?>

        <div class="content tfc-form user_forms">
            <div class="inner">
                <div class="panel-heading lead">
                    <?php _e( 'Recover your password' , 'shopclass' ); ?>
                </div>
                <form class="tfc-form" action="<?php echo osc_base_url( true ); ?>" method="post">
                    <input type="hidden" name="page" value="login"/>
                    <input type="hidden" name="action" value="forgot_post"/>
                    <input type="hidden" name="userId" value="<?php echo Params::getParam( 'userId' ); ?>"/>
                    <input type="hidden" name="code" value="<?php echo Params::getParam( 'code' ); ?>"/>
                    <fieldset>
                        <p>
                            <label for="new_email"><?php _e( 'New pasword' , 'shopclass' ); ?></label><br/>
                            <input type="password" name="new_password" value=""
                                   placeholder="<?php echo osc_esc_html( __( 'Enter Your Password' , 'shopclass' ) ); ?>"/>
                        </p>
                        <p>
                            <label for="new_email"><?php _e( 'Repeat new pasword' , 'shopclass' ); ?></label><br/>
                            <input type="password" name="new_password2" value=""
                                   placeholder="<?php echo osc_esc_html( __( 'Retype Your Password' , 'shopclass' ) ); ?>"/>
                        </p>
                        <button type="submit"><?php _e( 'Change password' , 'shopclass' ); ?></button>
                    </fieldset>
                </form>
            </div>
        </div>

    </div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
</body>
</html>
