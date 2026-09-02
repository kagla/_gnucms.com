<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SeoTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testSitemapContainsOnlyPublicCanonicalDocuments(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => 'https://example.test']]);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'free', 'name' => '공개 게시판', 'use_secret' => true,
        ]);
        $app->boardService()->create($acl, [
            'board_key' => 'members', 'name' => '회원 게시판', 'perm_read' => 'member',
        ]);
        $public = $app->postService()->create($acl, 'free', ['title' => '공개 글', 'content' => '공개 본문']);
        $secret = $app->postService()->create($acl, 'free', [
            'title' => '비밀 글', 'content' => '비밀 본문', 'is_secret' => true,
        ]);
        $private = $app->postService()->create($acl, 'members', ['title' => '회원 글', 'content' => '회원 본문']);
        $app->cmsService()->createPage($acl, [
            'slug' => 'about', 'title' => '소개', 'content' => '소개 내용',
            'seo_description' => '소개 설명', 'status' => 'published',
        ]);
        $app->cmsService()->createPage($acl, [
            'slug' => 'draft', 'title' => '초안', 'content' => '초안 내용', 'status' => 'draft',
        ]);

        $response = $this->get($app, '/sitemap.xml');
        $body = $this->body($response);

        self::assertSame('application/xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<loc>https://example.test/boards/free</loc>', $body);
        self::assertStringContainsString('<loc>https://example.test/posts/' . $public['id'] . '</loc>', $body);
        self::assertStringContainsString('<loc>https://example.test/content/about</loc>', $body);
        self::assertStringNotContainsString('/posts/' . $secret['id'] . '</loc>', $body);
        self::assertStringNotContainsString('/posts/' . $private['id'] . '</loc>', $body);
        self::assertStringNotContainsString('/boards/members</loc>', $body);
        self::assertStringNotContainsString('/content/draft</loc>', $body);
        self::assertSame('noindex, nofollow',
            $this->get($app, '/posts/' . $secret['id'])->getHeaderLine('X-Robots-Tag'));
    }

    #[DataProvider('connectionProvider')]
    public function testRssFeedsAreDiscoverableAndExcludeSecrets(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => 'https://example.test']]);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'free', 'name' => '공개 게시판', 'description' => '게시판 설명', 'use_secret' => true,
        ]);
        $post = $app->postService()->create($acl, 'free', ['title' => 'RSS 공개 글', 'content' => 'RSS 공개 본문']);
        $app->postService()->create($acl, 'free', [
            'title' => 'RSS 비밀 글', 'content' => 'RSS 비밀 본문', 'is_secret' => true,
        ]);

        $feed = $this->get($app, '/boards/free/rss.xml');
        $xml = $this->body($feed);
        self::assertSame('application/rss+xml; charset=utf-8', $feed->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<rss version="2.0"', $xml);
        self::assertStringContainsString('RSS 공개 글', $xml);
        self::assertStringContainsString('https://example.test/posts/' . $post['id'], $xml);
        self::assertStringNotContainsString('RSS 비밀 글', $xml);

        $board = $this->body($this->get($app, '/boards/free'));
        self::assertStringContainsString('rel="canonical" href="https://example.test/boards/free"', $board);
        self::assertStringContainsString('href="https://example.test/boards/free/rss.xml"', $board);

        $app->cmsService()->createPage($acl, [
            'slug' => 'guide', 'title' => 'RSS 내용', 'content' => '내용 피드 본문', 'status' => 'published',
        ]);
        $contentFeed = $this->body($this->get($app, '/content/rss.xml'));
        self::assertStringContainsString('RSS 내용', $contentFeed);
        self::assertStringContainsString('https://example.test/content/guide', $contentFeed);
    }

    #[DataProvider('connectionProvider')]
    public function testRobotsAndNoindexHeadersCoverPrivateAndFilteredScreens(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => 'https://example.test']]);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '공개 게시판']);

        $robots = $this->body($this->get($app, '/robots.txt'));
        self::assertStringContainsString('Sitemap: https://example.test/sitemap.xml', $robots);
        self::assertSame('noindex, nofollow', $this->get($app, '/login')->getHeaderLine('X-Robots-Tag'));
        self::assertSame('noindex, follow',
            $this->get($app, '/boards/free', ['q' => '검색'])->getHeaderLine('X-Robots-Tag'));
        self::assertSame('', $this->get($app, '/boards/free')->getHeaderLine('X-Robots-Tag'));
    }
}
