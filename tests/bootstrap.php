<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 어떤 DB 로 돌고 있는지 한 줄로 알린다. 환경변수가 없으면 MySQL/PostgreSQL 케이스가
// 조용히 빠지는데, 그러면 초록불 "OK" 가 "세 DB 통과" 인지 "SQLite 만 통과" 인지
// 구분되지 않는다. 이 프로젝트에서 실제로 그 형태의 사고가 한 번 있었다.
$activeDatabases = ['sqlite'];
if ((string) getenv('TEST_MYSQL_DSN') !== '') {
    $activeDatabases[] = 'mysql';
}
if ((string) getenv('TEST_PGSQL_DSN') !== '') {
    $activeDatabases[] = 'pgsql';
}
fwrite(
    STDERR,
    '테스트 대상 DB: ' . implode(', ', $activeDatabases)
        . (count($activeDatabases) === 3 ? '' : '  <-- 일부 DB 를 건너뜁니다')
        . PHP_EOL
);
