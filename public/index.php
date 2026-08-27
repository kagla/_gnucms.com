<?php

declare(strict_types=1);

use ApiBoard\App;
use ApiBoard\Web\Kernel;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><p>설치가 필요합니다. install.php 를 실행하세요.</p>';
    exit;
}

/** @var array $config */
$config = require $configFile;

// mod_rewrite 가 있으면 SCRIPT_NAME 이 REQUEST_URI 에 나타나지 않는다.
// 없으면 /index.php/b/free 형태로 들어오므로 그만큼을 기준 경로로 잘라낸다.
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$basePath = strpos($requestUri, $scriptName) === 0
    ? $scriptName
    : rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

$cacheDir = __DIR__ . '/../storage/cache/twig';
if (!empty($config['debug'])) {
    $cacheDir = null;
}

Kernel::create(new App($config), __DIR__ . '/../templates', $cacheDir, $basePath)->run();
