<?php

$appPath = __DIR__ . '/../';

require_once $appPath . 'vendor/autoload.php';

$app = require_once $appPath . 'bootstrap/app.php';

$request = Illuminate\Http\Request::capture();
$app->instance('request', $request);

$response = $app->handle($request);
$response->send();

$app->terminate($request, $response);