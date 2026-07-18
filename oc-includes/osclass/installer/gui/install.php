<?php if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
} ?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php _e('Shopclass Installation'); ?></title>
    <link rel="stylesheet" type="text/css" media="all" href="<?php echo get_absolute_url(); ?>oc-includes/assets/bootstrap/bootstrap.min.css" />
    <link rel="stylesheet" type="text/css" media="all" href="<?php echo get_absolute_url(); ?>oc-includes/assets/bootstrap-icons/bootstrap-icons.css" />
    <link rel="stylesheet" type="text/css" media="all" href="<?php echo get_absolute_url(); ?>oc-includes/osclass/installer/vtip/css/vtip.css" />

    <script src="<?php echo get_absolute_url(); ?>oc-includes/assets/jquery/jquery.min.js" type="text/javascript"></script>
    <script src="<?php echo get_absolute_url(); ?>oc-includes/assets/jquery-ui/jquery-ui.min.js" type="text/javascript"></script>
    <script src="<?php echo get_absolute_url(); ?>oc-includes/assets/bootstrap/bootstrap.min.js" type="text/javascript"></script>
    <script src="<?php echo get_absolute_url(); ?>oc-includes/osclass/installer/vtip/vtip.js" type="text/javascript"></script>
    <script src="<?php echo get_absolute_url(); ?>oc-includes/osclass/installer/install.js" type="text/javascript"></script>
</head>

<body>
    <div id="wrapper" class="container-md">
        <div class="row">
            <div class="offset-md-1 col-md-10 col-sm-12 align-self-center p-5" id="container">
                <div class="card rounded-3" tabindex="-1">
                    <div id="header" class="card-header text-dark bg-light installation">
                        <div class="text-center">
                            <img width="350" src="<?php echo get_absolute_url(); ?>oc-includes/images/osclass-logo.png" alt="Shopclass" title="Shopclass" />
                        </div>
                        <?php if (in_array($step, array(2, 3))) { ?>
                            <?php if ($step === 2) {
                                $databaseStep = 'text-info';
                                $targetStep   = 'text-muted';
                            } elseif ($step === 3) {
                                $databaseStep = 'text-muted';
                                $targetStep   = 'text-info';
                            } ?>
                            <ul class="nav nav-pills nav-fill justify-content-center">
                                <li class="nav-item border-bottom">
                                    <div class="nav-link <?php echo $databaseStep; ?>"><strong>1 - Database</strong></div>
                                </li>
                                <li class="nav-item border-bottom">
                                    <div class="nav-link <?php echo $targetStep; ?>"><strong>2 - Target</strong></div>
                                </li>
                            </ul>
                        <?php } ?>
                    </div>
                    <div class="card-body bg-light" id="content">
                        <?php if ($step === 1) { ?>
                            <h2 class="card-title text-center display-6"><?php _e('Welcome'); ?></h2>
                            <?php if (isset($error) && $error) { ?>
                                <div class="alert alert-danger shadow-sm" role="alert">
                                    <h4><?php _e('Oops! You need a compatible Hosting'); ?></h4>
                                    <?php _e('Your hosting seems to be not compatible, check your settings.'); ?>
                                </div>
                                <br>
                            <?php } ?>

                            <form class="p-3" action="install.php" method="post">
                                <input type="hidden" name="step" value="2" />
                                <div class="form-table">
                                    <?php if (count($jsonLocales) > 1) { ?>
                                        <div>
                                            <div class="row mb-3">
                                                <label for="install_locale" class="col-md-3 col-sm-6
                                                col-form-label"><strong><?php _e('Choose language'); ?></strong></label>
                                                <div class="col-md-3 col-sm-6">
                                                    <select class="form-control" aria-label="<?php _e('Choose language'); ?>" id="install_locale" name="install_locale" onchange="window.location.href='?install_locale='+document.getElementById(this.id).value">
                                                        <?php foreach ($jsonLocales as $k => $locale) { ?>
                                                            <option value="<?php echo osc_esc_html($k); ?>" <?php if (
                                                                                                                $k
                                                                                                                === Session::newInstance()->_get('userLocale')
                                                                                                            ) {
                                                                                                                echo 'selected="selected"';
                                                                           } ?>><?php echo $locale; ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    <?php } ?>
                                    <?php if ($error) { ?>
                                        <p><?php _e('Check the next requirements:'); ?></p>
                                        <div class="requirements_help alert alert-warning shadow-sm">
                                            <span class="small"><b><?php _e('Requirements help:'); ?></b></span>
                                            <ul>
                                                <?php foreach ($requirements as $k => $v) { ?>
                                                    <?php if (!$v['fn'] && $v['solution'] != '') { ?>
                                                        <li class="small"><?php echo $v['solution']; ?></li>
                                                    <?php } ?>
                                                <?php } ?>
                                                <li class="small">
                                                    <a href="https://github.com/mindstellar/shopclass/discussions" target="_blank" hreflang="en"><?php _e('Need more help?'); ?></a>
                                                </li>
                                            </ul>
                                        </div>
                                    <?php } else { ?>
                                        <div class="alert alert-success shadow-sm"><?php _e('All right! All the requirements have met:'); ?></div>
                                    <?php } ?>
                                    <ul>
                                        <?php foreach ($requirements as $k => $v) { ?>
                                            <li><?php echo $v['requirement']; ?> <i class="bi <?php echo $v['fn'] ? 'text-success bi-check'
                                                                                                    : 'text-danger bi-x-circle-fill'; ?>"></i></li>
                                        <?php } ?>
                                    </ul>
                                </div>
                                <?php if ($error) { ?>
                                    <p class="margin20">
                                        <input type="button" class="btn btn-primary" onclick="document.location = 'install.php?step=1'" value="<?php echo osc_esc_html(__('Try again')); ?>" />
                                    </p>
                                <?php } else { ?>
                                    <p class="margin20">
                                        <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Run the install')); ?>" />
                                    </p>
                                <?php } ?>
                            </form>
                        <?php } elseif ($step == 2) {
                            display_database_config();
                        } elseif ($step === 3) {
                            if (!isset($error['error'])) {
                                display_target();
                            } else {
                                // save form data
                                $form_data = array(
                                    'dbhost'     => Params::getParam('dbhost'),
                                    'username'     => Params::getParam('username'),
                                    'password' => Params::getParam('password'),
                                    'dbname'     => Params::getParam('dbname'),
                                    'tableprefix'   => Params::getParam('tableprefix'),
                                    'createdb'   => Params::getParam('createdb'),
                                    'admin_username'  => Params::getParam('admin_username'),
                                    'admin_password'  => Params::getParam('admin_password')
                                );
                                display_database_config($form_data, $error);
                            }
                        } elseif ($step === 4) {
                            // ping engines

                            if (isset($_COOKIE['osclass_ping_engines'])) {
                                try {
                                    ping_search_engines($_COOKIE['osclass_ping_engines']);
                                } catch (Exception $e) {
                                    LogOsclassInstaller::newInstance()
                                        ->error($e->getMessage(), $e->getFile() . ' at line: '
                                            . $e->getLine());
                                }
                            }
                            if (!headers_sent()) {
                                setcookie('osclass_save_stats', '', time() - 3600);
                                setcookie('osclass_ping_engines', '', time() - 3600);
                            }

                            // copy robots.txt
                            $source      = LIB_PATH . 'osclass/installer/robots.txt';
                            $destination = ABS_PATH . 'robots.txt';
                            if (function_exists('copy')) {
                                @copy($source, $destination);
                            } else {
                                $contentx   = @file_get_contents($source);
                                $openedfile = fopen($destination, 'wb');
                                fwrite($openedfile, $contentx);
                                fclose($openedfile);
                                $status = true;
                                if ($contentx === false) {
                                    $status = false;
                                }
                            }
                            display_finish($password);

                            // Install bender theme for first time.
                            if (!is_dir(CONTENT_PATH . 'themes/bender')) {
                                $fileSystem = new \mindstellar\utility\FileSystem();
                                $bender_filename       = 'bender.zip';
                                $download_path   = CONTENT_PATH . 'downloads/';
                                if ($downloaded = $fileSystem->downloadFile(
                                    'https://github.com/mindstellar/theme-bender/releases/download/v3.2.3/bender_3.2.3.zip',
                                    $download_path . 'bender.zip'
                                )) {
                                    $zip = new \mindstellar\utility\Zip();
                                    $resultCode = $zip->unzipFile($downloaded, CONTENT_PATH . 'themes/');
                                    $fileSystem->remove($downloaded);
                                }
                            }
                        }
                        ?>
                    </div>
                    <div class="card-footer" id="footer">
                        <ul class="list-inline">
                            <li class="list-inline-item"><a href="https://docs.mindstellar.com/osclass-docs/" target="_blank" hreflang="en"><?php _e('Documentation'); ?></a></li>
                            <li class="list-inline-item"><a href="https://github.com/mindstellar/shopclass/" target="_blank" hreflang="en"><?php _e('Feedback'); ?></a></li>
                            <li class="list-inline-item"><a href="https://github.com/mindstellar/shopclass/discussions" target="_blank" hreflang="en"><?php _e('Forums'); ?></a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>