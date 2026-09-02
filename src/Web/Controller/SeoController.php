<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SeoController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function sitemap(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $boards = $this->app->boardService()->listBoards($acl);
        $boardMap = [];
        foreach ($boards as $board) $boardMap[(int) $board['id']] = $board;
        $pages = $this->app->cms()->listPublishedPages();
        $room = max(1, 50000 - 1 - count($boards) - count($pages));
        $posts = $this->app->posts()->publicFeedRows(array_keys($boardMap), $room);
        $latestByBoard = [];
        $siteLastmod = null;
        foreach ($posts as $post) {
            $modified = (string) ($post['updated_at'] ?? $post['created_at'] ?? '');
            $boardId = (int) $post['board_id'];
            if ($modified !== '' && (!isset($latestByBoard[$boardId]) || $modified > $latestByBoard[$boardId])) {
                $latestByBoard[$boardId] = $modified;
            }
            if ($modified !== '' && ($siteLastmod === null || $modified > $siteLastmod)) $siteLastmod = $modified;
        }
        foreach ($pages as $page) {
            $modified = (string) ($page['updated_at'] ?? $page['published_at'] ?? '');
            if ($modified !== '' && ($siteLastmod === null || $modified > $siteLastmod)) $siteLastmod = $modified;
        }

        $urls = [['loc' => $this->absolute('/'), 'lastmod' => $siteLastmod]];
        foreach ($boards as $board) {
            $urls[] = [
                'loc' => $this->absolute('/boards/' . rawurlencode((string) $board['board_key'])),
                'lastmod' => $latestByBoard[(int) $board['id']] ?? $board['updated_at'] ?? $board['created_at'] ?? null,
            ];
        }
        foreach ($pages as $page) {
            $prefix = !empty($page['is_consent']) ? '/terms/' : '/content/';
            $urls[] = [
                'loc' => $this->absolute($prefix . rawurlencode((string) $page['slug'])),
                'lastmod' => $page['updated_at'] ?? $page['published_at'] ?? null,
            ];
        }
        foreach ($posts as $post) {
            $urls[] = [
                'loc' => $this->absolute('/posts/' . (int) $post['id']),
                'lastmod' => $post['updated_at'] ?? $post['created_at'] ?? null,
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . $this->xml((string) $url['loc']) . '</loc>';
            if (!empty($url['lastmod'])) $xml .= '<lastmod>' . $this->xml($this->atom((string) $url['lastmod'])) . '</lastmod>';
            $xml .= "</url>\n";
        }
        $xml .= "</urlset>\n";

        return $this->xmlResponse($response, $xml, 'application/xml; charset=utf-8');
    }

    public function robots(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /account\n"
            . "Disallow: /notifications\nSitemap: " . $this->absolute('/sitemap.xml') . "\n";
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public function siteRss(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $boards = $this->publicBoardMap();
        $rows = $this->app->posts()->publicFeedRows(array_keys($boards), 50);
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->postItem($row, $boards[(int) $row['board_id']] ?? null);
        }
        $site = $this->app->cmsService()->settings();
        return $this->rss($response, (string) $site['site_name'], (string) $site['site_tagline'],
            $this->absolute('/posts'), $this->absolute('/rss.xml'), $items);
    }

    public function boardRss(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $board = $this->app->boardService()->get($acl, (string) $args['key']);
        $rows = $this->app->posts()->publicFeedRows([(int) $board['id']], 50, (int) $board['id']);
        $items = array_map(fn (array $row): array => $this->postItem($row, $board), $rows);
        $site = $this->app->cmsService()->settings();
        return $this->rss($response,
            (string) $board['name'] . ' · ' . (string) $site['site_name'],
            (string) ($board['description'] ?: $site['site_tagline']),
            $this->absolute('/boards/' . rawurlencode((string) $board['board_key'])),
            $this->absolute('/boards/' . rawurlencode((string) $board['board_key']) . '/rss.xml'),
            $items
        );
    }

    public function contentRss(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pages = array_slice($this->app->cms()->listPublishedPages(), 0, 50);
        $items = [];
        foreach ($pages as $page) {
            $prefix = !empty($page['is_consent']) ? '/terms/' : '/content/';
            $url = $this->absolute($prefix . rawurlencode((string) $page['slug']));
            $items[] = [
                'title' => (string) $page['title'], 'url' => $url,
                'date' => (string) ($page['updated_at'] ?? $page['published_at'] ?? ''),
                'description' => (string) ($page['seo_description'] ?: $this->excerpt((string) $page['content'])),
            ];
        }
        $site = $this->app->cmsService()->settings();
        return $this->rss($response, '공개 내용 · ' . (string) $site['site_name'],
            '최근 공개되거나 수정된 내용입니다.', $this->absolute('/'), $this->absolute('/content/rss.xml'), $items);
    }

    private function rss(ResponseInterface $response, string $title, string $description,
        string $link, string $self, array $items): ResponseInterface
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n"
            . '<title>' . $this->xml($title) . '</title><link>' . $this->xml($link) . '</link>'
            . '<description>' . $this->xml($description) . '</description><language>ko</language>'
            . '<atom:link href="' . $this->xml($self) . '" rel="self" type="application/rss+xml" />' . "\n";
        if ($items !== []) $xml .= '<lastBuildDate>' . $this->xml($this->rssDate((string) $items[0]['date'])) . "</lastBuildDate>\n";
        foreach ($items as $item) {
            $xml .= '<item><title>' . $this->xml((string) $item['title']) . '</title>'
                . '<link>' . $this->xml((string) $item['url']) . '</link>'
                . '<guid isPermaLink="true">' . $this->xml((string) $item['url']) . '</guid>'
                . '<pubDate>' . $this->xml($this->rssDate((string) $item['date'])) . '</pubDate>'
                . '<description>' . $this->xml((string) $item['description']) . "</description></item>\n";
        }
        $xml .= "</channel></rss>\n";
        return $this->xmlResponse($response, $xml, 'application/rss+xml; charset=utf-8');
    }

    private function postItem(array $row, ?array $board): array
    {
        $title = (string) $row['title'];
        if ($board !== null) $title .= ' · ' . (string) $board['name'];
        return [
            'title' => $title,
            'url' => $this->absolute('/posts/' . (int) $row['id']),
            'date' => (string) ($row['updated_at'] ?? $row['created_at']),
            'description' => $this->excerpt((string) $row['content']),
        ];
    }

    private function publicBoardMap(): array
    {
        $map = [];
        foreach ($this->app->boardService()->listBoards($this->app->guestAcl()) as $board) {
            $map[(int) $board['id']] = $board;
        }
        return $map;
    }

    private function excerpt(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300) . '…' : $text;
    }

    private function absolute(string $path): string
    {
        return rtrim((string) $this->app->config('app.url', GNUCMS_URL), '/') . '/' . ltrim($path, '/');
    }

    private function atom(string $utc): string
    {
        $time = strtotime($utc . ' UTC');
        return $time === false ? gmdate(DATE_ATOM) : gmdate(DATE_ATOM, $time);
    }

    private function rssDate(string $utc): string
    {
        $time = strtotime($utc . ' UTC');
        return gmdate(DATE_RSS, $time === false ? time() : $time);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function xmlResponse(ResponseInterface $response, string $body, string $type): ResponseInterface
    {
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', $type)
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
