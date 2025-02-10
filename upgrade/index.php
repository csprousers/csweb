<?php
require_once __DIR__ . '/../setup/util.php';
require_once getApiVersionFilePath();
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="">
        <meta name="author" content="">

        <link rel='icon' href='../dist/img/favicon.ico' type='image/x-icon'/>

        <title>CSWeb: Upgrade</title>

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

        <div class="container">
            <div class="page-header">
                <h1>CSWeb: Upgrade Database</h1>
            </div>

            <?php
            // Check if app was already configured
            if (!alreadyConfigured()) {
                echo 'This application has not yet been configured. Click <a href="../setup/index.php">here</a> to configure it.';
            } else {
                require_once getApiConfigFilePath();

                try {

                    // Create database connection
                    $pdo = new PDO('mysql:host=' . DBHOST . ';dbname=' . DBNAME . ';charset=utf8mb4',
                            DBUSER, DBPASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

                    // Get current schema version
                    $dbSchemaVersion = $pdo->query("SELECT `value` FROM `cspro_config` WHERE `name`='schema_version'")->fetchColumn();
                    if ($dbSchemaVersion == SCHEMA_VERSION) {
                        echo "The database is up to date.";
                    }
                    /*else if ($dbSchemaVersion >= 7 && $dbSchemaVersion < SCHEMA_VERSION) {
                        //turn this back on when the upgrade is available for 8.0 minor releases 
                        echo '<p>The database needs to be upgraded from schema version ' . $dbSchemaVersion . ' to version ' . SCHEMA_VERSION . '</p>';
                        echo '<a href="upgrade.php" class="btn btn-primary pull-left">Upgrade</a>';
                    }*/else {
                        $strMsg = '<p>There is no upgrade path available from older versions of CSWeb to CSWeb 8.0.'
                                  . ' To configure CSWeb 8.0 using a new database name, please refer to the steps outlined'
                                  . ' in the <a href="https://www.csprousers.org/help/CSWeb/apache_csweb_setup.html" target="_blank">help documentation</a>';
                        echo '<div class="alert alert-danger" role="alert">Error: ' . $strMsg . '</div>';

                    }
                } catch (Exception $e) {
                    echo '<div class="alert alert-danger" role="alert">Error: ' . $e->getMessage() . '</div>';
                }
            }
            ?>

        </div>
    </body>
</html>