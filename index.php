<?php
error_reporting(E_ALL & ~E_NOTICE);

define('APP_PATH', dirname(__FILE__) . '/app');
define('IPHP_PATH', '/data/lib/iphp');
require_once(IPHP_PATH . '/loader.php');
require __DIR__.'/vendor/autoload.php';

mb_internal_encoding('utf-8');

App::run();
