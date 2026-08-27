<?php

declare(strict_types=1);

namespace ApiBoard\Web\Middleware;

use ApiBoard\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Slim\Views\TwigRuntimeLoader;
use Throwable;

/**
 * 스택의 가장 바깥이다. 도메인 오류는 그대로 화면으로 옮기고, 그 밖의 예외는
 * 500 으로 뭉갠 뒤 원문을 로그에만 남긴다. 로그에 남기지 않으면 SQL 원문 같은
 * 유일한 단서가 아무 데도 남지 않고 사라진다.
 *
 * 라우팅이 실패(404)하면 TwigMiddleware 는 한 번도 실행되지 않아 `base_path()` 같은
 * Slim\Views\TwigRuntimeExtension 함수가 오류 화면 렌더링 중 죽는다. 그래서 여기서도
 * 같은 런타임 로더를 직접 등록해 둔다. TwigMiddleware 가 나중에 같은 값으로 다시
 * 등록해도 먼저 등록된 것이 우선이므로 결과는 같아 안전하다.
 */
final class ErrorPageMiddleware implements MiddlewareInterface
{
    /** @var Twig */
    private $twig;

    /** @var bool */
    private $debug;

    /** @var string|null */
    private $logFile;

    /** @var RouteParserInterface */
    private $routeParser;

    /** @var string */
    private $basePath;

    public function __construct(
        Twig $twig,
        bool $debug,
        ?string $logFile,
        RouteParserInterface $routeParser,
        string $basePath
    ) {
        $this->twig = $twig;
        $this->debug = $debug;
        $this->logFile = $logFile === '' ? null : $logFile;
        $this->routeParser = $routeParser;
        $this->basePath = $basePath;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->twig->addRuntimeLoader(new TwigRuntimeLoader($this->routeParser, $request->getUri(), $this->basePath));

        try {
            return $handler->handle($request);
        } catch (HttpNotFoundException $e) {
            return $this->render(404, '찾을 수 없습니다.', '요청하신 주소에 해당하는 것이 없습니다.');
        } catch (DomainError $e) {
            if ($e->code() === 'INTERNAL') {
                $this->log($e);

                return $this->render(500, '오류가 발생했습니다.', $this->safeMessage($e));
            }

            return $this->render($e->status(), $this->titleFor($e->status()), $e->getMessage());
        } catch (Throwable $e) {
            $this->log($e);

            return $this->render(500, '오류가 발생했습니다.', $this->safeMessage($e));
        }
    }

    private function titleFor(int $status): string
    {
        switch ($status) {
            case 401:
                return '로그인이 필요합니다.';
            case 403:
                return '권한이 없습니다.';
            case 404:
                return '찾을 수 없습니다.';
            default:
                return '요청을 처리할 수 없습니다.';
        }
    }

    private function safeMessage(Throwable $e): string
    {
        return $this->debug
            ? get_class($e) . ': ' . $e->getMessage()
            : '잠시 후 다시 시도해 주세요. 문제가 계속되면 관리자에게 알려 주세요.';
    }

    private function log(Throwable $e): void
    {
        if ($this->logFile === null) {
            return;
        }

        @error_log(
            '[' . gmdate('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL,
            3,
            $this->logFile
        );
    }

    private function render(int $status, string $title, string $message): ResponseInterface
    {
        $response = (new Response())->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');

        return $this->twig->render($response, 'error.html.twig', [
            'title'   => $title,
            'message' => $message,
        ]);
    }
}
