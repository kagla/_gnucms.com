<?php

declare(strict_types=1);

use ApiBoard\App;
use ApiBoard\Web\BasePath;
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
$basePath = BasePath::resolve($scriptName, $requestUri);

// rewrite 가 없는 호스팅에서 사람이 가장 먼저 입력할 만한 주소가 바로 뒤에 아무것도
// 붙지 않은 "/index.php" 다. 기준 경로를 자른 나머지가 빈 문자열이면 라우트 "/" 와
// 맞지 않으므로(=/index.php/ 만 맞는다) 슬래시를 붙여 다시 보낸다.
$uriPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');
if (substr($uriPath, 0, strlen($basePath)) === $basePath && substr($uriPath, strlen($basePath)) === '') {
    $query = parse_url($requestUri, PHP_URL_QUERY);
    header('Location: ' . $basePath . '/' . ($query !== null && $query !== '' ? '?' . $query : ''), true, 302);
    exit;
}

$cacheDir = __DIR__ . '/../storage/cache/twig';
if (!empty($config['debug'])) {
    $cacheDir = null;
}

Kernel::create(new App($config), __DIR__ . '/../templates', $cacheDir, $basePath)->run();
