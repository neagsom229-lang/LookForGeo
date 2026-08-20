<?php

// Set the Laravel application path
$appPath = __DIR__ . '/../';

// Bootstrap Laravel
require_once $appPath . 'vendor/autoload.php';

$app = require_once $appPath . 'bootstrap/app.php';

// Set the request context
$request = Illuminate\Http\Request::capture();
$app->instance('request', $request);

// Handle the request
$response = $app->handle($request);
$response->send();

$app->terminate($request, $response);