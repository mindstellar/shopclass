<?php osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="row">
    <div class="col-md-4">
        <?php osc_current_web_theme_path( 'user-sidebar.php' ); ?>
    </div>
    <div class="col-md-8">
        <div class="tfc-item">
            <div class="panel-heading">
                <div class="panel-title"><?php echo osc_logged_user_name() . "'s Dashboard"; ?></div>
            </div>
            <div class="content user_account panel-body">

                <?php osc_render_file(); ?>

            </div>
        </div>

    </div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
</body>
</html>