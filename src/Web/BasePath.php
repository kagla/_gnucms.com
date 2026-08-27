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

    /**
     * rewrite 가 없는 호스팅에서 사람이 가장 먼저 입력할 만한 주소가 바로 뒤에
     * 아무것도 붙지 않은 "/index.php" 다. 기준 경로(resolve() 의 결과)를 자른
     * 나머지가 빈 문자열이면 라우트 "/" 와 맞지 않으므로(=/index.php/ 만 맞는다)
     * 슬래시를 붙여 리다이렉트해야 한다.
     *
     * 리다이렉트가 필요 없으면 null, 필요하면 Location 헤더에 그대로 쓸 대상
     * 문자열(쿼리스트링 포함)을 반환한다.
     *
     * 무한 리다이렉트가 되지 않는 이유: 대상은 항상 기준 경로 + "/" 형태라서
     * 자신을 다시 이 함수에 넣으면 "자른 나머지"가 "/" 가 되어(빈 문자열이 아니라서)
     * 더 이상 리다이렉트 대상이 되지 않는다. 아래 BasePathTest 의
     * testRedirectTargetNeverRedirectsAgain 이 이를 표로 확인한다.
     */
    public static function redirectTarget(string $scriptName, string $requestUri): ?string
    {
        $basePath = self::resolve($scriptName, $requestUri);
        $uriPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');

        $matchesBasePath = substr($uriPath, 0, strlen($basePath)) === $basePath;
        $remainderIsEmpty = substr($uriPath, strlen($basePath)) === '';
        if (!$matchesBasePath || !$remainderIsEmpty) {
            return null;
        }

        $query = parse_url($requestUri, PHP_URL_QUERY);

        return $basePath . '/' . ($query !== null && $query !== '' ? '?' . $query : '');
    }
}
