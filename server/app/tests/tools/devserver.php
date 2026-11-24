<?php
// Local preview:  SIGNALLAB_CONFIG=app/tests/config.test.php SL_BASE_URL=http://127.0.0.1:8080 php -S 127.0.0.1:8080 -t public app/tests/tools/devserver.php
$f = realpath(__DIR__ . '/../../../public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($f && is_file($f) && !str_ends_with($f, '.php')) return false;
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../../../public/index.php';
