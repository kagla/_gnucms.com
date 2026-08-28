<?php

declare(strict_types=1);

use GnuCms\App;
use GnuCms\Web\BasePath;
use GnuCms\Web\Kernel;

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
// 붙지 않은 "/index.php" 다. 그 경우 라우트 "/" 와 맞지 않으므로(=/index.php/ 만
// 맞는다) 슬래시를 붙여 다시 보낸다. 결정 로직은 BasePath::redirectTarget() 에
// 뽑아 두고 표로 테스트한다 (tests/Web/BasePathTest.php).
$redirectTarget = BasePath::redirectTarget($scriptName, $requestUri);
if ($redirectTarget !== null) {
    header('Location: ' . $redirectTarget, true, 302);
    exit;
}

// 템플릿 변경이 즉시 반영되도록 운영 환경에서도 파일 캐시를 사용하지 않는다.
Kernel::create(new App($config), __DIR__ . '/../templates', $basePath)->run();
