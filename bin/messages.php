<?php

declare(strict_types=1);

/**
 * 알림톡·문자 발송 결과 갱신 CLI — 선택 사항이다.
 *
 * 알리고는 결과 웹훅이 없어 조회로만 결과를 안다. 관리자가 운영 → 알림톡·문자 발송 →
 * 이력 화면을 열 때마다 자동으로 조금씩 갱신되므로(GnuCms\Aligo\History::refresh()),
 * 이 CLI 없이도 그대로 동작한다. `docs/extensions.md`가 정한 대로 이 기능은 호스팅
 * cron 을 요구하지 않는다 — cron 을 쓸 수 있는 환경에서 결과를 더 자주 최신으로
 * 유지하고 싶을 때만 등록해서 쓰는 보조 수단이다.
 *
 *   php bin/messages.php refresh                 config/config.php 를 읽어 최대 20건 갱신
 *   php bin/messages.php refresh 50               최대 50건 갱신
 *   php bin/messages.php refresh 50 /경로/config.php  다른 설정 파일을 쓴다
 *
 * 여러 번 돌려도 안전하다. 이미 결과가 잡힌 건은 다시 조회하지 않는다.
 */

use GnuCms\App;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("명령줄에서만 실행할 수 있습니다.\n");
}

$command = $argv[1] ?? '';
if ($command !== 'refresh') {
    fwrite(STDERR, "사용법: php bin/messages.php refresh [건수] [/경로/config.php]\n"
        . "발송 결과를 알리고에 물어 갱신합니다. 관리자가 이력 화면을 열 때도 갱신되므로\n"
        . "cron 없이 동작하며, 이 CLI는 cron을 쓸 수 있는 환경을 위한 선택 사항입니다.\n");
    exit(1);
}

$limit = (int) ($argv[2] ?? 20);

$configFile = $argv[3] ?? __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "설정 파일을 찾을 수 없습니다: {$configFile}\n");
    exit(1);
}

/** @var mixed $config */
$config = require $configFile;
if (!is_array($config) || !isset($config['db'])) {
    fwrite(STDERR, "설정 파일에 db 항목이 없습니다: {$configFile}\n");
    exit(1);
}

try {
    $app = new App($config, $configFile);
    $done = $app->aligo()->history->refresh(max(1, $limit));
    echo $done . '건의 발송 결과를 갱신했습니다.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    // 알리고 계정 미설정, 통신 실패 등도 여기로 온다. 원문에 비밀정보가 섞이지
    // 않는 DomainError·TransportFailure 의 메시지만 그대로 보여준다.
    fwrite(STDERR, '실패: ' . $e->getMessage() . "\n");
    exit(1);
}
