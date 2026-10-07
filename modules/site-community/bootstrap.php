<?php

declare(strict_types=1);

use GnuCms\Extension\Context;
use GnuCms\View\View;

return static function (Context $context): void {
    $context->route('GET', '/', static function ($request, $response) use ($context) {
        $view = View::fromRequest($request);
        // 전용 테마를 사용하지 않는 사이트에서는 기존 전체 글로 연결한다.
        if (!$view->exists('community/index')) {
            return $response->withHeader('Location', $view->url('posts.all'))->withStatus(302);
        }
        $acl = $context->app->guestAcl();
        $boards = $context->app->boardService()->listBoards($acl);
        foreach ($boards as &$board) {
            $board['latest_posts'] = $context->app->postService()->latestPosts(
                $acl, (string) $board['board_key'], 8
            );
        }
        unset($board);
        return $view->render($response, 'community/index', ['boards' => $boards]);
    });
};
