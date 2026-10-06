<?php
/**
 * @var string $message
 * @var string $file
 * @var int|string $line
 * @var string $type
 * @var string $trace
 */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">
    <link rel="icon" href="<?php echo osc_base_url(); ?>/favicon.ico">
    <title>OSClass Error</title>
    <link href="<?php echo osc_base_url(); ?>/oc-admin/themes/modern/css/main.css" rel="stylesheet">
</head>

<body style="background:var(--bs-gray-dark);">
    <div class="container">
        <div class="row">
            <div class="col-lg-12">
                <h1 class="display-4 text-center text-primary mt-5"><i class="bi bi-info-circle text-warning"></i> OSClass Error</h1>
                <hr>
            </div>
            <div class="col-lg-12">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card mb-5 bg-dark text-light shadow">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-lg-4">
                                        <h2 class="mb-3 p-1">Error Message</h2>
                                        <p class="lead text-primary font-monospace"><?php echo $message; ?></p>
                                    </div>
                                    <div class="col-lg-8">
                                        <h2 class="mb-1 p-1">Error Details</h2>
                                        <div class="p-2 font-monospace">
                                            <div class="p-1 text-info"><strong class="">File: </strong><?php echo $file; ?></div>
                                            <div class="p-1 text-info"><strong>Line: </strong><?php echo $line; ?></div>
                                            <div class="p-1 text-info"><strong>Type: </strong><?php echo $type; ?></div>
                                            <pre style="background:var(--bs-gray-dark);" class="mt-4 text-warning border-0"><?php echo $trace; ?></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>