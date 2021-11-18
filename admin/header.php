<div class="tfc-dashboard">
    <style type="text/css" media="screen">
        .command {
            background-color: #ffffff;
            color: #2E2E2E;
            border: 1px solid #000000;
            padding: 8px;
        }
    </style>
    <h2 class="render-title">Header Settings</h2>

    <form action="<?php echo osc_admin_render_theme_url( 'oc-content/themes/shopclass/admin/header.php' ); ?>"
          method="post" enctype="multipart/form-data">
        <input type="hidden" name="action_specific" value="header-settings"/>
        <div class="form-horizontal">
            <div class="form-row">
                <div class="form-label"> Header Logo Text</div>
                <div class="form-controls">
                    <input type="text" class="xlarge" name="header_logo_text"
                           value="<?php echo osc_esc_html( osc_get_preference( 'header_logo_text' , 'shopclass_theme' ) ); ?>">
                    <div class="help-box">Enter name for your site to show as Brand. Also work as alt tag replacement if
                        image logo uploaded
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-label"> Header Logo Icon</div>
                <div class="form-controls">
                    <input type="text" class="xlarge" name="header_logo_icon"
                           value="<?php echo osc_esc_html( osc_get_preference( 'header_logo_icon' , 'shopclass_theme' ) ); ?>">
                    <div class="help-box"> Enter Logo Icon class i.e. fa-paper-plane</div>
                    <div class="help-box"> We use Font Awesome as icon font. Visit: <a
                                href="http://fontawesome.io/icons/" target="_blank"> Font Awesome Icons</a></div>
                </div>
            </div>

            <div class="form-actions">
                <input type="submit" value="Submit"
                       class="btn btn-submit">
            </div>
        </div>
    </form>
    <h2 class="render-title">Change Header Logo</h2>
    <?php if ( is_writable( WebThemes::newInstance()->getCurrentThemePath() . "assets/images/" ) ) { ?>
        <?php if ( file_exists( WebThemes::newInstance()->getCurrentThemePath() . "assets/images/logo.png" ) ) { ?>
            <h3 class="render-title">Preview</h3>
            <img border="0" alt="<?php echo osc_esc_html( osc_page_title() ); ?>"
                 src="<?php echo osc_current_web_theme_url( 'assets/images/logo.png' ); ?>"/>
            <form action="<?php echo osc_admin_render_theme_url( 'oc-content/themes/shopclass/admin/header.php' ); ?>"
                  method="post" enctype="multipart/form-data">
                <input type="hidden" name="action_specific" value="remove"/>
                <fieldset>
                    <div class="form-horizontal">
                        <div class="form-actions">
                            <input id="button_remove" type="submit"
                                   value="Remove logo"
                                   class="btn btn-red">
                        </div>
                    </div>
                </fieldset>
            </form>
        <?php } else { ?>
            <div class="flashmessage flashmessage-warning flashmessage-inline" style="display: block;">
                <p>No logo has been uploaded yet</p>
            </div>
        <?php } ?>
        <h2 class="render-title separate-top">Upload logo</h2>
        <p>
            <?php if ( file_exists( WebThemes::newInstance()->getCurrentThemePath() . "assets/images/logo.png" ) ) { ?>
                <strong>Note:</strong> Uploading another logo will overwrite the current logo.
            <?php } ?>
        </p>
        <form action="<?php echo osc_admin_render_theme_url( 'oc-content/themes/shopclass/admin/header.php' ); ?>"
              method="post" enctype="multipart/form-data">
            <input type="hidden" name="action_specific" value="upload_logo"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label">Logo image (png)</div>
                        <div class="form-controls">
                            <input type="file" name="logo" id="package"/>
                            <div class="help-box"> Recommended Image Size is 173x53.</div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <input id="button_save" type="submit"
                               value="Upload"
                               class="btn btn-submit">
                    </div>
                </div>
            </fieldset>
        </form>
    <?php } else { ?>
    <div class="flashmessage flashmessage-error" style="display: block;">
        <p>
            <?php
                $msg = sprintf( 'The images folder <strong>%s</strong> is not writable on your server' , WebThemes::newInstance()->getCurrentThemePath() . "images/" ) . ", ";
                $msg .= 'OSClass can\'t upload the logo image from the administration panel.' . ' ';
                $msg .= 'Please make the aforementioned image folder writable.' . ' ';
                echo $msg;
            ?>
        </p>
        <p>
            To make a directory writable under UNIX execute this command from the shell:
        </p>
        <p class="command">
            chmod a+w<?php echo WebThemes::newInstance()->getCurrentThemePath() . "assets/images/"; ?>
        </p>
    </div>
<?php } ?>