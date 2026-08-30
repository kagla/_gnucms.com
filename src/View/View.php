<?php

declare(strict_types=1);

namespace GnuCms\View;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class View
{
    public const ATTRIBUTE = 'gnucms.view';

    public static function fromRequest(ServerRequestInterface $request): ViewInterface
    {
        $view = $request->getAttribute(self::ATTRIBUTE);
        if (!$view instanceof ViewInterface) {
            throw new RuntimeException('요청에 View 가 없습니다. ViewMiddleware 가 먼저 돌아야 합니다.');
        }
        return $view;
    }
}
