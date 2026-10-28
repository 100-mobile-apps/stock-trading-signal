<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'SignalLab\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

require_once __DIR__ . '/src/Http.php'; // holds several small classes

use SignalLab\Config;

$configFile = getenv('SIGNALLAB_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo "Missing config.php. Copy config.sample.php to config.php and fill it in.\n";
    exit(1);
}
Config::load(require $configFile);
date_default_timezone_set('UTC');
