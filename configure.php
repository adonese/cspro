<?php
require_once __DIR__ .'/util.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Disable warnings.
// If the database  connection fails we will get warnings
// inside PDO routines that we don't need user to see
error_reporting(E_ALL ^ E_WARNING);

if (alreadyConfigured()) {
	header('HTTP/1.0 403 Forbidden');
	echo 'This application was already configured';
	exit;
}

function usingHttps() 
{
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || $_SERVER['SERVER_PORT'] == 443;
}

function getProtocol()
{
	return 'http'.(usingHttps() ? 's' : '');
}

function getPort()
{
	return $_SERVER['SERVER_PORT'] == '80' ? '' : ':'.$_SERVER['SERVER_PORT'];
}

function parentDirectory($url) 
{
	$lastSlash = strrpos($url, '/');
	return $lastSlash ? substr($url, 0, $lastSlash) : '';
}

$setupError = false;

$databaseName = "";
$host = "";
$databaseUsername = "";
$databasePassword = "";
$adminPassword = "";
$timezone = date_default_timezone_get();
$filesDirectory = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'files';
$maxExecutionTime = 300;

$apiUrl = getProtocol().'://'.$_SERVER['SERVER_NAME'].getPort().parentDirectory(parentDirectory($_SERVER['REQUEST_URI'])).'/api/';

function validateParameters($databaseName, $host, $databaseUsername, 
							$databasePassword, $adminPassword, $filesDirectory,
							$apiUrl, $timezone,$maxExecutionTime)
{
	if (strlen($databaseName) < 1) {
		throw new Exception('Database name cannot be blank');
	}
	if (strlen($host) < 1) {
		throw new Exception('Hostname cannot be blank');
	}
	if (strlen($databaseUsername) < 1) {
		throw new Exception('Database username cannot be blank');
	}
	if (strlen($adminPassword) < 8) {
		throw new Exception('Administrative password must be at least 8 characters');
	}
	if (strlen($filesDirectory) < 1) {
		throw new Exception('Files directory cannot be blank');
	}
	if (strlen($apiUrl) < 1) {
		throw new Exception('API URL directory cannot be blank');
	}
	if (!date_default_timezone_set($timezone)) {
		throw new Exception('Invalid time zone');
	}
	if ($maxExecutionTime < 0 ) {
		throw new Exception('Maximum exeution cannot be less than zero');
	}
	
}

function createDatabase($databaseName, $host, $databaseUsername, $databasePassword, $adminPassword)
{
		try {
			// Create connection with database name
			$pdo = new PDO('mysql:host='.$host.';dbname='.$databaseName.';charset=utf8mb4', 
			$databaseUsername, $databasePassword, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
		} catch (PDOException $e) {
			$unknownDB = 1049;
			if ($e->getCode() === $unknownDB) {
				// Create connection without database name
				$pdo = new PDO('mysql:host='.$host.';charset=utf8mb4', $databaseUsername, 
					$databasePassword, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));		
			} else {
				throw $e;
			}
		}

		// Check database version_compare
		 $mysqlVersion = $pdo->query('select version()')->fetchColumn();
		 define ("MIN_MYSQL_VERSION", "5.5.3");
		 if (version_compare($mysqlVersion, MIN_MYSQL_VERSION, '<'))
			throw new Exception('MySQL version ' . MIN_MYSQL_VERSION . ' or greater is required. This server is running version ' . $mysqlVersion);

		$pdo->exec("CREATE DATABASE IF NOT EXISTS `$databaseName`;");
		$pdo->exec("USE `$databaseName`;");

		$sql = <<<'EOT'
			CREATE TABLE IF NOT EXISTS `cspro_dictionaries` (
			  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
			  `dictionary_name` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `dictionary_label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `dictionary_full_content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
			  `modified_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  `created_time` timestamp DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `dictionary_name` (`dictionary_name`)
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			CREATE TRIGGER tr_cspro_dictionaries BEFORE INSERT ON `cspro_dictionaries` FOR EACH ROW SET NEW.`created_time` = CURRENT_TIMESTAMP;
EOT;
		$pdo->exec($sql);

		$sql = <<<'EOT'
			CREATE TABLE IF NOT EXISTS `cspro_sync_history` (
			  `revision` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `device` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `dictionary_id` tinyint(3) unsigned NOT NULL,
			  `universe` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `direction` enum('put','get','both') COLLATE utf8mb4_unicode_ci NOT NULL,
			  `created_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`revision`),
			  KEY `dictionary_id` (`dictionary_id`),
			  CONSTRAINT `cspro_dict_id_constraint` FOREIGN KEY (`dictionary_id`) REFERENCES `cspro_dictionaries`(`id`)
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
		$pdo->exec($sql);
		
		$sql = <<<'EOT'
		CREATE TABLE IF NOT EXISTS `oauth_access_tokens` (
		  `access_token` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
		  `client_id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
		  `user_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
		  `expires` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		  `scope` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
		  PRIMARY KEY (`access_token`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
		$pdo->exec($sql);
		
		$sql = <<<'EOT'
			CREATE TABLE  IF NOT EXISTS `oauth_authorization_codes` (
			  `authorization_code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `client_id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `user_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `redirect_uri` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `expires` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  `scope` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  PRIMARY KEY (`authorization_code`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
		$pdo->exec($sql);
		
		$sql = <<<'EOT'
			CREATE TABLE IF NOT EXISTS `oauth_clients` (
			  `client_id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `client_secret` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `redirect_uri` varchar(2000) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `grant_types` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `scope` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `user_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  PRIMARY KEY (`client_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
		$pdo->exec($sql);

		$sql = <<<'EOT'
			INSERT IGNORE INTO `oauth_clients` (`client_id`, `client_secret`, `redirect_uri`, `grant_types`, `scope`, `user_id`) VALUES
			('cspro_android', 'cspro', '', NULL, NULL, NULL);
EOT;
		$pdo->exec($sql);
		$sql = <<<'EOT'
			CREATE TABLE IF NOT EXISTS `oauth_jwt` (
			  `client_id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `subject` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `public_key` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  PRIMARY KEY (`client_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
		$pdo->exec($sql);
		$sql = <<<'EOT'
			CREATE TABLE IF NOT EXISTS `oauth_refresh_tokens` (
			  `refresh_token` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `client_id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `user_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `expires` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  `scope` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  PRIMARY KEY (`refresh_token`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
		$pdo->exec($sql);
		$sql = <<<'EOT'
			CREATE TABLE IF NOT EXISTS `oauth_scopes` (
			  `scope` text COLLATE utf8mb4_unicode_ci,
			  `is_default` tinyint(1) DEFAULT NULL
			) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
		$pdo->exec($sql);
		$sql = <<<'EOT'
			CREATE TABLE IF NOT EXISTS `oauth_users` (
			  `username` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `password` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `last_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  PRIMARY KEY (`username`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
		$pdo->exec($sql);
		$sql = <<<'EOT'
			CREATE TABLE IF NOT EXISTS `cspro_roles` (
			  `id` tinyint(3) unsigned NOT NULL,
			  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `modified_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  `created_time` timestamp DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Roles Table';
			CREATE TRIGGER tr_cspro_roles BEFORE INSERT ON `cspro_roles` FOR EACH ROW SET NEW.`created_time` = CURRENT_TIMESTAMP;

EOT;
		$pdo->exec($sql);
		$sql = <<<'EOT'
				INSERT IGNORE INTO `cspro_roles` (`id`, `name`) VALUES
				(1, 'Standard User'),
				(2, 'Administrator');
EOT;
		$pdo->exec($sql);

		$sql = <<<'EOT'
			CREATE TABLE IF NOT EXISTS `cspro_users` (
			  `username` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `password` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `last_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `role` tinyint(3) unsigned NOT NULL,
			  `modified_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  `created_time` timestamp DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`username`),
			  KEY `role` (`role`),
			  CONSTRAINT `role_id_constraint` FOREIGN KEY (`role`) REFERENCES `cspro_roles` (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			CREATE TRIGGER tr_cspro_users BEFORE INSERT ON `cspro_users` FOR EACH ROW SET NEW.`created_time` = CURRENT_TIMESTAMP;
EOT;
		$pdo->exec($sql);

		$hash = password_hash($adminPassword, PASSWORD_DEFAULT);
		$sql = <<<EOT
			INSERT IGNORE INTO `cspro_users` (`username`, `password`, `first_name`, `last_name`,`role`) VALUES
			('admin', '$hash', 'System', 'Administrator','2');
EOT;
		$pdo->exec($sql);	
		
		//config table
		$sql = <<<'EOT'
			CREATE TABLE IF NOT EXISTS `cspro_config` (
			  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
			  `value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			  `modified_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  `created_time` timestamp DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`name`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			CREATE TRIGGER tr_cspro_config BEFORE INSERT ON `cspro_config` FOR EACH ROW SET NEW.`created_time` = CURRENT_TIMESTAMP;
EOT;
		$pdo->exec($sql);
		$sql = <<<EOT
			INSERT IGNORE INTO `cspro_config` (`name`, `value`) VALUES
			('schema_version', '1');
EOT;
		$pdo->exec($sql);	
}

function createFilesDirectory($filesDirectory)
{
	if (file_exists($filesDirectory))
	{
		if (!is_dir($filesDirectory)) {
			throw new Exception("Files directory $filesDirectory exists but is a file, not a directory.");
		}
		
		if (!is_writable($filesDirectory)) {
			throw new Exception("Files directory $filesDirectory is not writeabe.");			
		}
	} else {
		if (!@mkdir($filesDirectory, 0755, true) ) {
			throw new Exception("Failed to create directory $filesDirectory");
		}
	}
}

function writeApiConfigFile($databaseName, $host, $databaseUsername, 
					     $databasePassword, $adminPassword, $filesDirectory,
						 $serverDeviceId, $timezone,$maxExecutionTime)
{
	$configFilePath = getApiConfigFilePath();
	$configFile = @fopen($configFilePath, "w");
	if (!$configFile)
		throw new Exception("Unable to write to file $configFilePath. Check the file permissions for the directory.");
	fwrite($configFile, "<?php\n");
	fwrite($configFile, "define('DBHOST', '$host');\n");
	fwrite($configFile, "define('DBUSER', '$databaseUsername');\n");
	fwrite($configFile, "define('DBPASS', '$databasePassword');\n");
	fwrite($configFile, "define('DBNAME', '$databaseName');\n");
	fwrite($configFile, "define('ENABLE_OAUTH', true);\n");
	fwrite($configFile, "define('FILES_FOLDER', '$filesDirectory');\n");
	fwrite($configFile, "define('SERVER_DEVICE_ID', '$serverDeviceId');\n");
	fwrite($configFile, "define('DEFAULT_TIMEZONE', '$timezone');\n");
	fwrite($configFile, "define('MAX_EXECUTION_TIME', '$maxExecutionTime');\n");
	fwrite($configFile, "?>\n");
	fclose($configFile);
}

function writeUiConfigFile($apiUrl,$timezone)
{
	$configFilePath = getUiConfigFilePath();
	$configFile = @fopen($configFilePath, "w");
	if (!$configFile)
		throw new Exception("Unable to write to file $configFilePath. Check the file permissions for the directory.");
	fwrite($configFile, "<?php\n");
	fwrite($configFile, "define('API_URL', '$apiUrl');\n");
	fwrite($configFile, "define('DEFAULT_TIMEZONE', '$timezone');\n");
	fwrite($configFile, "?>\n");
	fclose($configFile);	
}

// Make sure that we can reach the API url.
function testApiUrl($apiUrl, $username, $password) 
{
	try{
		$client = new GuzzleHttp\Client();
		$body = json_encode(array("client_id"=>"cspro_android",
							  "client_secret"=>"cspro",
							  "grant_type"=>"password",
							  "username"=>$username,
							  "password"=>$password));
		$response = $client->request('POST', rtrim($apiUrl, '/') . '/token', ['body' => $body, 'headers' => ['Content-Type' => 'application/json',
                                                                      'Accept' => 'application/json']]);

		if ($response->getStatusCode() != 200)
			throw new \Exception("Failed to contact API server $apiUrl : error ".$response->getStatusCode());
	} catch (Exception $e) {
		throw new \Exception("Failed to contact API server $apiUrl : ".$e->getMessage());
	}
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
			
	try {
		$databaseName = trim($_POST['dbname']);
		$host = trim($_POST['dbhost']);
		$databaseUsername = trim($_POST['dbusername']);
		$databasePassword = trim($_POST['dbpassword']);
		$adminPassword = trim($_POST['adminpassword']);
		$timezone = trim($_POST['timezone']);
		$filesDirectory = trim($_POST['filespath']);
		$maxExecutionTime = trim($_POST['maxExecutionTime']);
		$apiUrl = trim($_POST['apiurl']);
		$serverDeviceId = $apiUrl;
		
		validateParameters($databaseName, $host, $databaseUsername, 
						   $databasePassword, $adminPassword, 
						   $filesDirectory, $apiUrl, $timezone, $maxExecutionTime);
		
		createFilesDirectory($filesDirectory);
		
		createDatabase($databaseName, $host, $databaseUsername, 
					   $databasePassword, $adminPassword);
		
		writeApiConfigFile($databaseName, $host, $databaseUsername, $databasePassword, 
					   $adminPassword, $filesDirectory, $serverDeviceId, $timezone,$maxExecutionTime);
		
		testApiUrl($apiUrl, 'admin', $adminPassword);
		
		writeUiConfigFile($apiUrl, $timezone);
		
		header('Location: complete.php');
	} catch (PDOException $e) {
		$setupError = true;
		$setupErrorMessage = 'Failed to connect to database. '.$e->getMessage();
	} catch (Exception $e) {
		$setupError = true;
		$setupErrorMessage = $e->getMessage();
	}
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="author" content="">
	
	<link rel='icon' href='../ui/dist/img/favicon.ico' type='image/x-icon'/ >

	<title>CSWeb: Configuration</title>

    <!-- Bootstrap Core CSS -->
    <link href="../ui/bower_components/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom Fonts -->
    <link href="../ui/bower_components/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css">

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
<h1>CSWeb: Configuration</h1>
</div>

<br/>
<br/>

<?php
if ($setupError) {
	echo '<div class="alert alert-danger" role="alert">'.htmlspecialchars($setupErrorMessage).'</div>';
}
?>

<form class="form-horizontal" method="post" action="">
<fieldset>

<div class="form-group row">
  <label class="col-sm-3 col-form-label" for="name">Database name</label>  
  <div class="col-sm-9">
  <input id="dbname" name="dbname" type="text" value="<?php print(htmlspecialchars($databaseName)) ?>" placeholder="Name of database (e.g. cspro)." class="form-control input-md" required="">
  </div>
</div>

<div class="form-group row">
  <label class="col-sm-3 col-form-label" for="host">Hostname</label>  
  <div class="col-sm-9">
  <input id="dbhost" name="dbhost" type="text" value="<?php print(htmlspecialchars($host)) ?>" placeholder="Hostname of database server (e.g. localhost)." class="form-control input-md" required="">  
  </div>
</div>

<div class="form-group row">
  <label class="col-sm-3 col-form-label" for="username">Database username</label>  
  <div class="col-sm-9">
  <input id="dbusername" name="dbusername" type="text" value="<?php print(htmlspecialchars($databaseUsername)) ?>" placeholder="Name of database user. Must already exist." class="form-control input-md" required="">
  </div>
</div>

<div class="form-group row">
  <label class="col-sm-3 col-form-label" for="dbpassword">Database password</label>
  <div class="col-sm-9">
    <input id="dbpassword" name="dbpassword" type="password" value="<?php print(htmlspecialchars($databasePassword)) ?>" placeholder="Database user password." class="form-control input-md">
  </div>
</div>

<div class="form-group row">
  <label class="col-sm-3 col-form-label" for="adminpassword">CSWeb admin password</label>
  <div class="col-sm-9">
    <input id="adminpassword" name="adminpassword" type="password" value="<?php print(htmlspecialchars($adminPassword)) ?>" placeholder="Choose password for CSWeb admin user." class="form-control input-md" required="">
  </div>
</div>

<div class="form-group row">
  <label class="col-sm-3 col-form-label" for="timezone">Timezone</label>
  <div class="col-sm-9">
    <select id="timezone" name="timezone" class="form-control input-md">
		<?php
			foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $tz) {
				$selected = ($tz == $timezone) ? 'selected' : '';
				echo "<option $selected >$tz</option>\n";				
			}
		?>
	</select>
  </div>
</div>

<div class="form-group row">
  <label class="col-sm-3 col-form-label" for="maxExecutionTime">Maximum execution time</label>
  <div class="col-sm-9">
    <input id="maxExecutionTime" name="maxExecutionTime" type="number" min="1" step="1" value="<?php print(htmlspecialchars($maxExecutionTime)) ?>" placeholder="Maximum script execution time in seconds (Default 300 seconds)" class="form-control input-md" required="">
  </div>
</div>

<div class="form-group row">
  <label class="col-sm-3 col-form-label" for="filespath">Path to files directory</label>
  <div class="col-sm-9">
    <input id="filespath" name="filespath" type="text" value="<?php print(htmlspecialchars($filesDirectory)) ?>" placeholder="Full path on server to directory in which to store synced files." class="form-control input-md" required="">
  </div>
</div>

<div class="form-group row">
  <label class="col-sm-3 col-form-label" for="apiurl">CSWeb API URL</label>
  <div class="col-sm-9">
    <input id="apiurl" name="apiurl" type="text" value="<?php print(htmlspecialchars($apiUrl)) ?>" placeholder="URL of CSWeb API." class="form-control input-md" required="">
  </div>
</div>

<div class="form-group row">
  <div class="col-sm-11">
	<a href="index.php" class="btn btn-primary" role="button">Back</a>
  </div>

  <div class="col-sm-1">
    <button id="next" name="next" class="btn btn-primary">Next</button>
  </div>
</div>

</fieldset>
</form>
</div>

</body>
</html>