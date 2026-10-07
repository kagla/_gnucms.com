<?php

declare(strict_types=1);

// GitHub Actions에서만 사용한다. 운영 애플리케이션과 Composer 의존성을 불러오지 않는다.
try {
    $version = $argv[1] ?? '';
    $notesPath = $argv[2] ?? '';
    $versionPattern = '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/';
    if (!preg_match($versionPattern, $version) || $notesPath === '') {
        throw new RuntimeException('버전 X.Y.Z와 릴리스 노트 출력 경로가 필요합니다.');
    }

    $read = static function (string $path): string {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException($path . ' 파일을 읽을 수 없습니다.');
        }
        return $content;
    };
    $write = static function (string $path, string $content): void {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException($path . ' 파일을 저장할 수 없습니다.');
        }
    };
    $current = trim($read('version.txt'));
    $mainVersion = $argv[3] ?? $current;
    if (!preg_match($versionPattern, $current) || !preg_match($versionPattern, $mainVersion)
        || version_compare($version, $current, '<') || version_compare($version, $mainVersion, '<')) {
        throw new RuntimeException('현재 제품 버전보다 낮은 버전으로 릴리스할 수 없습니다.');
    }
    $changelog = str_replace("\r\n", "\n", $read('CHANGELOG.md'));
    $sections = '/^## (?<heading>[^\n]+)\n(?<body>.*?)(?=^## |\z)/ms';
    preg_match_all($sections, $changelog, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $releaseSection = null;
    $unreleasedSection = null;
    foreach ($matches as $match) {
        if (preg_match('/\A\[' . preg_quote($version, '/') . '\](?:\(|\s|\z)/', $match['heading'][0])) {
            if ($releaseSection !== null) {
                throw new RuntimeException('CHANGELOG.md에 같은 버전이 두 번 기록되어 있습니다.');
            }
            $releaseSection = $match;
        }
        if ($match['heading'][0] === '[Unreleased]') {
            if ($unreleasedSection !== null) {
                throw new RuntimeException('CHANGELOG.md에 [Unreleased]가 두 번 기록되어 있습니다.');
            }
            $unreleasedSection = $match;
        }
    }

    if ($version === $current) {
        if ($releaseSection === null) {
            throw new RuntimeException('재실행할 버전의 변경 내역이 없습니다.');
        }
        $notes = trim($releaseSection['body'][0]);
    } else {
        if ($releaseSection !== null || $unreleasedSection === null || $unreleasedSection !== ($matches[0] ?? null)) {
            throw new RuntimeException('새 릴리스의 변경 내역을 CHANGELOG.md 맨 위의 ## [Unreleased]에 작성해 주세요.');
        }
        $notes = trim($unreleasedSection['body'][0]);
    }
    if ($notes === '' || !preg_match('/[가-힣]/u', $notes)) {
        throw new RuntimeException('사용자 관점의 한국어 릴리스 노트가 필요합니다.');
    }
    if (!preg_match('/^### 업그레이드 안내\s*$/m', $notes)) {
        throw new RuntimeException('릴리스 노트에 ### 업그레이드 안내를 작성해 주세요.');
    }

    if ($version !== $current) {
        $repository = getenv('GITHUB_REPOSITORY') ?: 'kagla/gnucms';
        $date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
        $heading = '## [' . $version . '](https://github.com/' . $repository
            . '/compare/v' . $current . '...v' . $version . ') (' . $date . ')';
        $offset = $unreleasedSection[0][1];
        $length = strlen($unreleasedSection[0][0]);
        $changelog = substr_replace($changelog, $heading . "\n\n" . $notes . "\n\n", $offset, $length);
        $write('CHANGELOG.md', $changelog);
        $write('version.txt', $version . "\n");
    }
    $write($notesPath, $notes . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
