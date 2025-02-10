<?php
require_once __DIR__ . '/util.php';

if (alreadyConfigured()) {
    header('HTTP/1.0 403 Forbidden');
     echo '<p>This application has already been configured. To re-configure it, please refer to the steps outlined '
    . 'in the <a href="https://www.csprousers.org/help/CSWeb/troubleshooting.html" target="_blank">help documentation</a>.</p>';
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="">
        <meta name="author" content="">

        <link rel='icon' href='../dist/img/favicon.ico' type='image/x-icon'/ >

              <title>CSWeb: Requirements</title>

        <!-- Bootstrap Core CSS -->
        <link href="../bower_components/bootstrap/css/bootstrap.min.css" rel="stylesheet">

        <!-- Custom Fonts -->
        <link href="../bower_components/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">

        <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
        <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
        <!--[if lt IE 9]>
            <script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
            <script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
        <![endif]-->

    </head>
    <body>
        <?php

        function testResultToHtml($result) {
            if ($result) {
                return '<i class="fas fa-check-circle fa-2x text-success" aria-label="Pass"></i>';
            } else {
                return '<i class="fas fa-ban fa-2x text-danger" aria-label="Fail"></i>';
            }
        }

        function canFindGuzzleCABundle() {
            if (PHP_VERSION_ID >= 50600) {
                return true;
            }
            if (extension_loaded('curl')) {
                return true;
            }
            try {
                \GuzzleHttp\default_ca_bundle();
            } catch (Exception $e) {
                return false;
            }
            return true;
        }

// Determine if an on/off option is on in php.ini
// Options that are enabled can be set to 1, on or true.
        function enabledInIniFile($setting) {
            return in_array(strtolower(ini_get($setting)), array('1', 'on', 'true'));
        }

// Convert ini file size string like '2G' to size in MB.
        function sizeStringToMegaBytes($size) {
            if (is_numeric($size)) {
                // bytes
                return $size / (1024 * 1024);
            }
            $suffix = substr($size, -1);
            $numVal = substr($size, 0, -1);

            switch (strtoupper($suffix)) {
                case 'P':
                    $multiplier = 1024 * 1024 * 1024;
                    break;
                case 'T':
                    $multiplier = 1024 * 1024;
                    break;
                case 'G':
                    $multiplier = 1024;
                    break;
                case 'M':
                    $multiplier = 1;
                    break;
                case 'K':
                    $multiplier = 1.0 / 1024;
                    break;
            }
            return $numVal * $multiplier;
        }

        function directoriesWriteable() {
            $dirs = array('var', 'app/config', 'src/AppBundle');

            foreach ($dirs as $d) {
                $fullPath = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . $d;
                if (!is_writable($fullPath))
                    return false;
            }
            return true;
        }

        $tests = array(
            'PHP version 8.0 or above' => version_compare(PHP_VERSION, '8.0') >= 0,
            'PHP file_info extension' => function_exists('finfo_open'),
            'PHP pdo extension' => extension_loaded('pdo'),
            'PHP dom extension' => extension_loaded('dom'),
            'PHP pdo_mysql extension' => extension_loaded('pdo_mysql'),
            'PHP curl extension or allow_url_fopen on in php.ini' => extension_loaded('curl') || enabledInIniFile('allow_url_fopen'),
            'PHP openssl extension' => extension_loaded('openssl'),
            'PHP zip extension' => class_exists('ZipArchive'),
            'CA bundle (for PHP < 5.6 and no curl)' => canFindGuzzleCABundle(),
            'enable_post_data_reading on in php.ini' => enabledInIniFile('enable_post_data_reading'),
            'post-max-size >= 8M in php.ini' => sizeStringToMegaBytes(ini_get('post_max_size')) >= 8 || sizeStringToMegaBytes(ini_get('post_max_size')) === 0,
            'var, app/config, src/AppBundle directories are writeable' => directoriesWriteable(),
        );

        $showRawPostWarning = false;
        if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION == 6) {
            $showRawPostWarning = true;
            //$tests['always_populate_raw_post_data = -1 in php.ini'] = ini_get ('always_populate_raw_post_data') == '-1';
        }
        if (strpos($_SERVER['SERVER_SOFTWARE'], 'Apache') !== false) {
            $tests['Apache mod_rewrite enabled'] = $rewriteSuccess;
        }
        ?>

        <div class="container">
            <div class="page-header">
                <h1>CSWeb: Server Requirements</h1>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <tbody>
<?php
$allPass = true;

foreach ($tests as $label => $result) {
    $allPass &= $result;
    $resultHtml = testResultToHtml($result);
    echo "<tr><th>$label</th><td>$resultHtml</td></tr>";
}
?>

                    </tbody>
                </table>
            </div>

                        <?php
                        if ($allPass) {
                            if ($showRawPostWarning) {
                                echo '<div class="alert alert-warning" role="alert">You are running PHP 5.6. If you are unable to login to CSWeb after the setup, you have to set always_populate_raw_post_data = -1 in your php.ini file.</div>';
                            }
                            echo '<div class="alert alert-success" role="alert">Your server meets all the requirements. Click next to begin configuration.</div>';
                            echo '<a href="configure.php" class="btn btn-primary float-right"">Next</a>';
                            echo '<div style="padding-bottom: 50px"></div>';
                        } else {
                            echo '<div class="alert alert-danger" role="alert">Your server is missing one or more required settings. Please correct the issue(s) and click "Try Again".</div>';
                            echo '<form action="" method="get"><input type="submit" class="btn btn-primary float-right" value="Try Again"></form>';
                            echo '<div style="padding-bottom: 50px"></div>';
                        }
                        ?>
        </div>
    </body>
</html>
