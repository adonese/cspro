<?php

define ("API_CONFIG_FILE_RELATIVE_PATH", '/../src/api/app/config.php');
define ("UI_CONFIG_FILE_RELATIVE_PATH", '/../src/ui/src/config.php');

function getApiConfigFilePath()
{
	return __DIR__ . API_CONFIG_FILE_RELATIVE_PATH;
}

function getUiConfigFilePath()
{
	return __DIR__ . UI_CONFIG_FILE_RELATIVE_PATH;
}

function alreadyConfigured()
{
	return file_exists(getApiConfigFilePath()) &&
		file_exists(getUiConfigFilePath());
}

?>
