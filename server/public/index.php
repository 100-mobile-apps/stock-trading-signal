<?php
declare(strict_types=1);

// Front controller for https://stocksignal.bulksend.top
//   /api/*  → JSON API used by the Android app
//   /admin* → admin panel
//   else    → website (public pages + member dashboard)
// If the app/ folder lives elsewhere, set SIGNALLAB_APP_DIR or edit this path.
$appDir = getenv('SIGNALLAB_APP_DIR') ?: __DIR__ . '/../app';
require $appDir . '/bootstrap.php';

use SignalLab\{Api, Request, Router, Web};

$req = Request::fromGlobals();
if ($req->path === '/api' || str_starts_with($req->path, '/api/')) {
    Router::sendWith(Api::router()->dispatch($req->withPath(substr($req->path, 4) ?: '/')), true);
} else {
    Router::sendWith(Web::handle($req), false);
}
