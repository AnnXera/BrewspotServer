<?php

// Hostinger entry point. Upload to public_html/backend/index.php.
// The Laravel app itself lives in a "brewspot-api" folder outside public_html
// (e.g. /home/<user>/brewspot-api); this file finds it by walking up the tree.

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$basePath = null;
for ($dir = __DIR__; $dir !== dirname($dir); $dir = dirname($dir)) {
    if (is_file($dir.'/brewspot-api/artisan')) {
        $basePath = $dir.'/brewspot-api';
        break;
    }
}

if ($basePath === null) {
    http_response_code(500);
    exit('Laravel app folder "brewspot-api" not found.');
}

if (file_exists($maintenance = $basePath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $basePath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $basePath.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
