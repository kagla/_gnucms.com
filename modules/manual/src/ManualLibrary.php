<?php

declare(strict_types=1);

namespace GnuCmsManual;

use GnuCms\Error\DomainError;

/** 매뉴얼 본문은 배포 소스다. 요청값으로 파일 경로를 만들지 않는다. */
final class ManualLibrary
{
    private ?array $catalog = null;

    public function __construct(private string $directory) {}

    public function catalog(): array
    {
        if ($this->catalog === null) {
            $this->catalog = $this->read('catalog.json');
            foreach ($this->catalog['articles'] as $article) {
                if (!is_string($article['slug']) || !preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~D', $article['slug'])) {
                    throw DomainError::internal('매뉴얼 주소가 올바르지 않습니다.');
                }
            }
        }
        return $this->catalog;
    }

    public function article(string $slug): array
    {
        foreach ($this->catalog()['articles'] as $index => $meta) {
            if ($meta['slug'] !== $slug) continue;
            $article = $this->read($meta['file']);
            $article['previous'] = $this->catalog()['articles'][$index - 1] ?? null;
            $article['next'] = $this->catalog()['articles'][$index + 1] ?? null;
            $article['updated_at'] = filemtime($this->directory . '/' . $meta['file']);
            return $article + $meta;
        }
        throw DomainError::notFound('매뉴얼 문서를 찾을 수 없습니다.');
    }

    public function search(): array
    {
        return $this->read('search.json');
    }

    private function read(string $name): array
    {
        if (!preg_match('/^[a-z0-9_-]+\.json$/D', $name)) {
            throw DomainError::internal('매뉴얼 파일 이름이 올바르지 않습니다.');
        }
        $data = json_decode((string) file_get_contents($this->directory . '/' . $name), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw DomainError::internal('매뉴얼 문서 형식이 올바르지 않습니다.');
        return $data;
    }
}
