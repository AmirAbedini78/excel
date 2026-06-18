<?php
session_start();
define('APP_ROOT', dirname(__DIR__));
$configFile = APP_ROOT . '/app/config.php';
if (!file_exists($configFile)) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== 'install.php') { header('Location: install.php'); exit; }
    $CONFIG = require APP_ROOT . '/app/config.sample.php';
} else {
    $CONFIG = require $configFile;
}
date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Tehran');
require_once APP_ROOT . '/app/Core/DB.php';
require_once APP_ROOT . '/app/Core/Jalali.php';
require_once APP_ROOT . '/app/Core/Helpers.php';
require_once APP_ROOT . '/app/Core/Auth.php';
require_once APP_ROOT . '/app/Core/Schema.php';
require_once APP_ROOT . '/app/Core/Notify.php';
require_once APP_ROOT . '/app/Core/Xlsx.php';
if (file_exists($configFile)) DB::connect($CONFIG['db']);
