<?php

declare(strict_types=1);

namespace ApiBoard\Web;

/**
 * 프론트 컨트롤러의 기준 경로 계산. mod_rewrite 가 있으면 REQUEST_URI 에
 * SCRIPT_NAME (index.php) 이 나타나지 않으므로 기준 경로는 그 디렉터리다.
 * 없으면 /index.php/b/free 형태로 들어오므로 SCRIPT_NAME 전체가 기준 경로다.
 *
 * 순수 함수로 뽑아 둔 이유는 웹서버 전역 변수 없이도 표로 테스트하기 위해서다.
 */
final class BasePath
{
    public static function resolve(string $scriptName, string $requestUri): string
    {
        return strpos($requestUri, $scriptName) === 0
            ? $scriptName
            : rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    }
}
