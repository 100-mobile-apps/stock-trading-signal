<?php
declare(strict_types=1);

// Front controller. Point your (sub)domain's document root at this folder.
// If the app/ folder lives elsewhere, set SIGNALLAB_APP_DIR or edit this path.
$appDir = getenv('SIGNALLAB_APP_DIR') ?: __DIR__ . '/../app';
require $appDir . '/bootstrap.php';

use SignalLab\Api;
use SignalLab\Request;
use SignalLab\Router;

Router::send(Api::router()->dispatch(Request::fromGlobals()));
