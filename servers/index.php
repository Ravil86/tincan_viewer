<?php

$base_dir = dirname(dirname(dirname(dirname(__FILE__))));
require_once $base_dir . '/engine/settings.php';
if (file_exists($base_dir . '/vendor/autoload.php')) {
	require_once $base_dir . '/vendor/autoload.php';
}

require_once dirname(dirname(__FILE__)) . '/vendor/autoload.php';

global $CONFIG;

$server = new \TinCan\Viewer\Server($CONFIG);
$server->serve();