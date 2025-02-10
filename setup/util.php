<?php

define ("API_SRC_DIRECTORY", '/../src/AppBundle/');
define ("API_CONFIG_FILE_RELATIVE_PATH", API_SRC_DIRECTORY . 'config.php');
define ("API_VERSION_FILE_RELATIVE_PATH", API_SRC_DIRECTORY . 'version.php');
define ("UI_CONFIG_FILE_RELATIVE_PATH", API_SRC_DIRECTORY . 'config.php');

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

function getApiVersionFilePath()
{
	return __DIR__ . API_VERSION_FILE_RELATIVE_PATH;
}

function guidv4()
{
    //cross platform guid 
    //https://stackoverflow.com/questions/2040240/php-function-to-generate-v4-uuid
    $data = openssl_random_pseudo_bytes(16);

    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

?>
