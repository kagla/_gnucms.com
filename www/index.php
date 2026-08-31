<?php

declare(strict_types=1);

use GnuCms\App;
use GnuCms\Db\MaintenanceRequired;
use GnuCms\Web\BasePath;
use GnuCms\Web\Kernel;
use GnuCms\Web\MaintenancePage;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    // 아직 설치 전이다. 설치기가 있으면 그리로 보내고, 없으면 무엇을 해야 하는지만 알린다.
    if (is_file(__DIR__ . '/install.php')) {
        header('Location: ' . BasePath::siblingUrl($scriptName, 'install.php'), true, 302);
        exit;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><p>설치가 필요합니다. www/install.php 를 올리고 브라우저로 여세요.</p>';
    exit;
}

/** @var array $config */
$config = require $configFile;

// mod_rewrite 가 있으면 SCRIPT_NAME 이 REQUEST_URI 에 나타나지 않는다.
// 없으면 /index.php/b/free 형태로 들어오므로 그만큼을 기준 경로로 잘라낸다.
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
try {
    Kernel::create(new App($config), __DIR__ . '/../templates', $basePath)->run();
} catch (MaintenanceRequired $e) {
    // 스키마를 옮기는 중이거나 옮기지 못했다. Slim 바깥에서 나므로 여기서 화면을 낸다.
    MaintenancePage::send($e);
}
