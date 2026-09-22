<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (!str_starts_with((string) getenv('TEST_MYSQL_DSN'), 'mysql:')) {
    throw new RuntimeException('TEST_MYSQL_DSN에 전용 MySQL/MariaDB 테스트 DB를 지정하세요.');
}
fwrite(STDERR, "테스트 대상 DB: MySQL/MariaDB\n");
