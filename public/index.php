<?php

declare(strict_types=1);

use StandardBoard\App;
use StandardBoard\Http\ApiError;
use StandardBoard\Http\Cors;
use StandardBoard\Http\Request;
use StandardBoard\Http\Response;

// display_errors 가 켜진 호스팅에서도 경고문이 JSON 앞에 섞이지 않게 한다.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require __DIR__ . '/../src/autoload.php';

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":{"code":"INTERNAL","message":"설치가 필요합니다. install.php 를 실행하세요.","details":{}}}';
    exit;
}

/** @var array $config */
$config = require $configFile;
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
