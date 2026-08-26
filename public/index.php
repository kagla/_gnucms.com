<?php

declare(strict_types=1);

use StandardBoard\App;
use StandardBoard\Config;
use StandardBoard\Http\ApiError;
use StandardBoard\Http\Cors;
use StandardBoard\Http\Request;
use StandardBoard\Http\Response;

// display_errors 가 켜진 호스팅에서도 경고문이 JSON 앞에 섞이지 않게 한다.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require __DIR__ . '/../src/autoload.php';

$basePath = dirname(__DIR__);

$bail = static function (string $message): void {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":{"code":"INTERNAL","message":"' . $message . '","details":{}}}';
    exit;
};

try {
    /** @var array $config */
    $config = Config::load($basePath . '/config/config.php', $basePath . '/.env', $basePath);
} catch (Throwable $e) {
    // 설정을 읽다 죽으면 로그 경로도 아직 모른다. 기본 경로에 남긴다.
    @error_log(
        '[' . gmdate('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL,
        3,
        $basePath . '/storage/logs/error.log'
    );
    $bail('설정을 읽지 못했습니다. .env 문법을 확인하세요.');
    exit;
}

// config.php 없이 .env 만으로 배포할 수도 있으므로 파일 존재가 아니라
// 실제로 접속할 DB 가 정해졌는지를 본다.
if ((string) ($config['db']['dsn'] ?? '') === '') {
    $bail('설치가 필요합니다. install.php 를 실행하세요.');
    exit;
}

$debug = (bool) ($config['debug'] ?? false);

$corsHeaders = Cors::headersFor(
    $_SERVER['HTTP_ORIGIN'] ?? null,
    (array) ($config['cors']['allowed_origins'] ?? [])
);

// 프리플라이트는 라우팅 이전에 끝낸다.
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    ob_end_clean();
    http_response_code($corsHeaders === [] ? 403 : 204);
    foreach ($corsHeaders as $name => $value) {
        header($name . ': ' . $value);
    }
    exit;
}

$logError = static function (Throwable $e) use ($config): void {
    if (!isset($config['log']['file'])) {
        return;
    }
    @error_log(
        '[' . gmdate('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL,
        3,
        (string) $config['log']['file']
    );
};

try {
    $app = new App($config);
    $response = $app->router()->dispatch(Request::fromGlobals());
} catch (ApiError $e) {
    // INTERNAL 의 메시지는 응답에서 일반 문구로 바뀐다. 로그에 남기지 않으면
    // SQL 원문 같은 유일한 단서가 아무 데도 남지 않고 사라진다.
    if ($e->code() === 'INTERNAL') {
        $logError($e);
    }
    $response = Response::fromError($e, $debug);
} catch (Throwable $e) {
    $logError($e);
    $response = Response::fromError(ApiError::internal($e->getMessage()), $debug);
}

// 핸들러가 실수로 출력한 것이 있어도 버린다. 응답은 JSON 하나뿐이어야 한다.
ob_end_clean();
$response->withHeaders($corsHeaders)->send();
