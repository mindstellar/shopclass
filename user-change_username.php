<?php osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="row">
    <div class="col-md-4">
        <?php include 'user-sidebar.php'; ?>
    </div>
    <div class="col-md-8">
        <div class="tfc-item">
            <div class="panel-heading">
                <div class="panel-title"><?php _e( 'Change your Username' , 'shopclass' ); ?></div>
            </div>
            <div class="content user_account panel-body">

                <div id="main" class="modify_profile">
                    <legend><?php _e( 'Change your Username' , 'shopclass' ); ?></legend>
                    <form id="change-username" class="tfc-form form-group" action="<?php echo osc_base_url( true ); ?>"
                          method="post">
                        <input type="hidden" name="page" value="user"/>
                        <input type="hidden" name="action" value="change_username_post"/>
                        <fieldset class="form-group">
                            <div class="col-md-6">
                                <label for="s_username"><?php _e( 'New Username' , 'shopclass' ); ?> *</label>
                                <input class="form-control" type="text" name="s_username" id="s_username" value=""/>
                                <div id="available" class="help-box"></div>
                            </div>

                            <div style="clear:both;"></div>

                        </fieldset>
                        <button class="btn btn-default" type="submit"><?php _e( 'Update' , 'shopclass' ); ?></button>
                    </form>

                </div>
            </div>
        </div>
    </div>

</div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
<script type="text/javascript">
    $(document).ready(function () {
        $('form#change-username').validate({
            rules: {
                s_username: {
                    required: true
                }
            },
            messages: {
                s_username: {
                    required: '<?php echo osc_esc_js( __( "Username: this field is required" , "shopclass" ) ); ?>.'
                }
            },
            highlight: function (element) {
                $(element).closest('.form-group').addClass('has-warning');
            },
            unhighlight: function (element) {
                $(element).closest('.form-group').removeClass('has-warning');
            },
            errorElement: 'span',
            errorClass: 'help-block',
            errorPlacement: function (error, element) {
                if (element.parent('.input-group').length) {
                    error.insertAfter(element.parent());
                } else {
                    error.insertAfter(element);
                }
            }
        });

        var cInterval;
        $("#s_username").keydown(function (event) {
            if ($("#s_username").val() != '') {
                clearInterval(cInterval);
                cInterval = setInterval(function () {
                    $.getJSON(
                        "<?php echo osc_base_url( true ); ?>?page=ajax&action=check_username_availability",
                        {"s_username": $("#s_username").val()},
                        function (data) {
                            clearInterval(cInterval);
                            if (data.exists == 0) {
                                $("#available").closest('.form-group').addClass('has-success').removeClass('has-error');
                                $("#available").text('<?php echo osc_esc_js( __( "The username is available" , "shopclass" ) ); ?>').addClass('text-success').removeClass('text-danger');
                            } else {
                                $("#available").closest('.form-group').removeClass('has-success').addClass('has-error');
                                $("#available").text('<?php echo osc_esc_js( __( "The username is NOT available" , "shopclass" ) ); ?>').addClass('text-danger').removeClass('text-success');
                            }
                        }
                    );
                }, 1000);
            }
        });

    });
</script>
</body>
</html>
