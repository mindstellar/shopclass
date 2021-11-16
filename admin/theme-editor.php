<div class="tfc-dashboard">
    <style type="text/css">
        .CodeMirror, .codemirror textarea {
            border: 1px solid #efefef;
            width: 97%;
            height: 600px !important;
        }

        .CodeMirror-scroll {
            overflow-y: scroll;
            overflow-x: scroll;
        }

        #sidebar {
            z-index: 999
        }

        .tfc-files-directory {
            width: 100%;
            display: block;
        }

        .ose-all-files {
            width: 100%;
            display: block;
        }

        .ose-directories {
            font-size: 16px;
            font-weight: 600;
            padding: 5px;
            margin: 5px;
            display: block;
            float: left;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 0 5px 0 rgba(128, 128, 128, 0.32)
        }

        .ose-files {
            padding: 5px;
            margin: 5px;
            display: block;
            float: left;
            background: #fff;
            border-radius: 5px;
            min-height: 20px;
            line-height: 1.4;
            box-shadow: 0 0 5px 0 rgba(128, 128, 128, 0.32)
        }

        .tfc-active {
            background: rgb(255, 35, 70);
            color: white;
            font-weight: 600;
            box-shadow: 0 0 5px 0px rgba(232, 33, 15, 0.59);
        }

        .directory-select-box {
            margin-bottom: 5px;
        }

    </style>

	<?php //getting file content and saving it to variable
		use shopclass\includes\classes\tfcAdminDashboard;
		use shopclass\includes\classes\tfcFilesClass;

		$nonedirselected = '';
		$noneselected    = '';
		if ( ! Params::getParam( 'editfilename' ) ) {
			$editFileName = 'index.php';
			$noneselected = '1';
		} else {
			$editFileName = Params::getParam( 'editfilename' );
		}
		if ( ! Params::getParam( 'editdirectory' ) ) {
			$editDirectory   = WebThemes::newInstance()->getCurrentThemePath();
			$nonedirselected = '1';
		} else {
			$editDirectory = urldecode( Params::getParam( 'editdirectory' ) );
		}
		$editDirectory       = rtrim( $editDirectory , '/' ) . '/';
		$parentEditDirectory = rtrim( dirname( $editDirectory ) , '/' ) . '/';
		$editDirectoryInfo   = pathinfo( $editDirectory );

		if ( ( strpos( realpath( $editDirectory ) , realpath( ABS_PATH ) ) !== false ) ) {
            $parentEditDirectoryName = basename( $parentEditDirectory);
			$editfileext             = array ();
			if ( file_exists( $editDirectory . $editFileName ) ) {
				$editfileext = pathinfo( urlencode( realpath( $editDirectory ) ) . $editFileName );
				switch ( $editfileext[ 'extension' ] ) {
					case 'php':
						$editor_mode = 'application/x-httpd-php';
						break;
					case 'css':
						$editor_mode = 'css';
						break;
					case 'js':
						$editor_mode = 'application/javascript';
						break;
					case 'sql':
						$editor_mode = 'text/x-plsql';
						break;
					default:
						$editor_mode = 'shell';
				}
			} else {
				$editor_mode = 'application/x-httpd-php';
			}

			?>
        <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/codemirror.min.css">
            <script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/codemirror.min.js"></script>
            <script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/addon/edit/matchbrackets.min.js"></script>
            <script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/mode/php/php.min.js"></script>
            <script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/mode/htmlmixed/htmlmixed.min.js"></script>
            <script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/mode/javascript/javascript.min.js"></script>
            <script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/mode/xml/xml.min.js"></script>
            <script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/mode/css/css.min.js"></script>
            <script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/mode/clike/clike.min.js"></script>

		<?php if ( $editor_mode == 'shell' ){ ?>
            <script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/mode/shell/shell.min.js"></script>
		<?php } ?>
		<?php if ( $editor_mode == 'text/x-plsql' ){ ?>
            <script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/5.31.0/mode/sql/sql.min.js"></script>
		<?php } ?>

            <div class="grid-85 grid-row">
                <h3 class="render-title"><?php echo 'Editing' . ': <strong>' . $editFileName . '</strong>'; ?></h3>
                <form action="<?php echo tfcAdminDashboard::newInstance()->getRouteAdminURL( 'osclass-editor-shopclass' ); ?>"
                      method="post">
                    <fieldset>
                        <input type="hidden" name="action_specific" value="osclass_file_editor"/>
                        <input type="hidden" name="theme_editor_directory" value="<?php echo $editDirectory; ?>"/>
                        <input type="hidden" name="theme_editor_filename" value="<?php echo $editFileName; ?>"/>

                        <div>
                            <div class="form-controls">
                        <textarea name="tfc-edit" id="tfc-edit"
                                  style="margin: 2px 0px; width: 100%; height: 529px;"></textarea>
                                <div class="help-box"
                                     style="color:red;">Make a backup before making any changes to ;
										<?php echo $editFileName; ?></div>
                                <div class="form-actions" style="margin-left:30px">
                                    <input type="submit"
                                           value="Save changes"
                                           class="btn btn-submit"/>
                                    <a id="make-backup" src="#backup-response"
                                       class="btn">Make Backup</a>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </form>
                <div id="backup-response"></div>
				<?php
					if ( $editDirectory && $editFileName ) {
						date_default_timezone_set( osc_timezone() );
						$filepath = urldecode( $editDirectory . $editFileName ) . '.bak';
						if ( file_exists( $filepath ) ) {
							?>
                            <div>Backup Found For This File</div>
                            <div><?php echo  'Last Updated:' ;
									echo gmdate( "Y-m-d H:i:s" , filemtime( $filepath ) ); ?></div>

						<?php }
					}
				?>
            </div>
            <div class="grid-25 grid-row directory-select-box">
				<?php if ( ! ( is_writable( $editDirectory ) ) ) {
					?>
                    <div class="flashmessage-error">
                        This Directory is not writable
                    </div>
				<?php } ?>
                <?php
	                if ( ( strpos( realpath( $parentEditDirectory ) , realpath( ABS_PATH ) ) !== false ) ) { ?>
                <div class="form-controls grid-row" style="width:100%; margin-bottom:10px">
                    <div class="form-label-selection">
                        <h4 class="render-title" style="float:left;margin-bottom:5px"> Parent Directory :
                        <?php
                        echo '<a href="' . tfcAdminDashboard::newInstance()->getRouteAdminURL( 'osclass-editor-shopclass' , array (
										'editdirectory' => urlencode( $parentEditDirectory ) ,
										'editfilename'  => ''
									) ) . '">' . ucfirst( strtolower( $parentEditDirectoryName ) ) . '</a>';
                        ?>
                        </h4>
                    </div>
                </div>
                <?php } ?>
                <div class="form-controls">
                    <div class="form-label-selection">
                        <h4 class="render-title" style="float:left;margin-bottom:5px"> Selected Directory
                            : </h4>
                        <select id="tfc_dir_select">
                            <option value=""<?php if ( $nonedirselected == "1" ) { echo "selected"; } ?>>Choose Directory ...</option>

							<?php
								$Oselected = ( realpath( $editDirectory ) == realpath( ABS_PATH ) ) ? ' selected' : '';

								echo '<option value="' . tfcAdminDashboard::newInstance()->getRouteAdminURL( 'osclass-editor-shopclass' , array (
										'editdirectory' => urlencode( ABS_PATH ) ,
										'editfilename'  => ''
									) ) . '" ' . $Oselected . '>Osclass Install Directory</option> ';
								echo '<option value="' . tfcAdminDashboard::newInstance()->getRouteAdminURL( 'osclass-editor-shopclass' , array (
										'editdirectory' => urlencode( PLUGINS_PATH ) ,
										'editfilename'  => ''
									) ) . '" >Plugins Directory</option> ';
								echo '<option value="' . tfcAdminDashboard::newInstance()->getRouteAdminURL( 'osclass-editor-shopclass' , array (
										'editdirectory' => urlencode( THEMES_PATH ) ,
										'editfilename'  => ''
									) ) . '" >Themes Directory</option> ';

								$tfcFC = new tfcFilesClass();
								$tfcFC->setEditDirectory( $parentEditDirectory );
								$Pdirectories = $tfcFC->scanDirectories();
								if ( ! empty( $Pdirectories ) ) {
									foreach ( $Pdirectories as $Pdirectory ) {
										$Pselected = ( basename( $editDirectory ) == $Pdirectory ) ? ' selected' : '';
										echo '<option value="' . tfcAdminDashboard::newInstance()->getRouteAdminURL( 'osclass-editor-shopclass' , array (
												'editdirectory' => urlencode( $parentEditDirectory . $Pdirectory ) ,
												'editfilename'  => ''
											) ) . '" ' . $Pselected . '>' . ucfirst( $Pdirectory ) . '</option> ';
									}
								}

							?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="grid-25 grid-row tfc-file-list">

                <div class="tfc-files-directory">
                    <div style="color: #616161;font-size: 18px;font-weight: 600">Current Directory: <?php echo basename( $editDirectory ); ?></div>
					<?php if ( ! ( is_writable( $editDirectory ) ) ) {
						?>
                        <div class="flashmessage-error">
                            This Directory is not writable
                        </div>
					<?php }

						$tfcFC->setEditDirectory( $editDirectory );
						$directories = $tfcFC->scanDirectories();
						$files       = $tfcFC->scanFilenames();

						if ( ! empty( $directories ) ) {
							foreach ( $directories as $directory ) {
								echo '<a class="ose-directories" href="' . tfcAdminDashboard::newInstance()->getRouteAdminURL( 'osclass-editor-shopclass' , array (
										'editdirectory' => urlencode( $editDirectory . $directory ) ,
										'editfilename'  => ''
									) ) . '">' . strtolower( $directory ) . '</a>';
							}

						} ?>
                </div>
                <div class="ose-all-files"><?php
						if ( ! empty( $files ) ) {
							foreach ( $files as $file ) {
								$activeclass = ( $editFileName == $file ) ? ' tfc-active' : '';
								echo '<a class="ose-files ' . $activeclass . '" href="' . tfcAdminDashboard::newInstance()->getRouteAdminURL( 'osclass-editor-shopclass' , array (
										'editdirectory' => urlencode( $editDirectory ) ,
										'editfilename'  => $file
									) ) . '">' . strtolower( $file ) . '</a>';
							}
						}
					?></div>
            </div>
            <script>
                $(function () {
                    // bind change event to select
                    $('#tfc_dir_select').on('change', function () {
                        var url = $(this).val(); // get selected value
                        if (url) { // require a URL
                            window.location = url; // redirect
                        }
                        return false;
                    });
                });
            </script>
            <script>
                $(document).ready(function () {
                    var secure_data = {
                        token: '<?php echo md5( DB_NAME . DB_USER . DB_PASSWORD . DB_HOST . WEB_PATH ); ?>', //used token here.
                        is_ajax: 1,
                        editdirectory: '<?php echo urlencode( $editDirectory );?>',
                        editfilename: '<?php echo $editFileName;?>'
                    };
                    $.ajax({
                        type: "POST",
                        url: '<?php echo osc_current_web_theme_url( 'includes/editfilename.php' )?>',//get response from this file
                        data: secure_data,
                        success: function (response) {

                            $("textarea#tfc-edit").val(response);//send response to textarea
                        }
                    });
                    $("#make-backup").click(function () {
                        $.ajax({
                            type: "POST",
                            url: '<?php echo osc_current_web_theme_url( 'includes/makebackup.php' )?>', //get response from this file
                            data: secure_data,
                            global: false,
                            success: function (response) {

                                $("#backup-response").replaceWith(response);//send response to textarea
                            }
                        });
                    });
                });
            </script>
            <script>
                $(document).ajaxComplete(function () {
                    var editor = CodeMirror.fromTextArea(document.getElementById("tfc-edit"), {
                        lineNumbers: true,
                        matchBrackets: true,

                        mode: "<?php echo $editor_mode;?>",

                        indentUnit: 4,
                        indentWithTabs: true,
                        lineWrapping: true
                    });
                });
            </script>
		<?php } else { ?>
            <h2 class="render-title ">Osclass File Editor</h2>
            <h3 class="render-title flashmessage-error">WARNING: Access above Osclass Installation is not allowed!</h3>

		<?php } ?>
</div>