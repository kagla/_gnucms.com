<?php

declare(strict_types=1);

/**
 * 전체 백업 CLI.
 *
 *   php bin/backup.php create --format=zip
 *   php bin/backup.php create --format=tar
 *   php bin/backup.php list
 *   php bin/backup.php verify gnucms-sqlite-20260904-120000.zip
 *   php bin/backup.php restore gnucms-sqlite-20260904-120000.zip --yes
 *   php bin/backup.php delete gnucms-sqlite-20260904-120000.zip --yes
 *   php bin/backup.php create --config=/경로/config.php
 */

use GnuCms\App;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("명령줄에서만 실행할 수 있습니다.\n");
}

$arguments = array_slice($argv, 1);
$configFile = __DIR__ . '/../config/config.php';
$yes = false;
$format = null;
$positional = [];
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--config=')) {
        $configFile = substr($argument, 9);
    } elseif (str_starts_with($argument, '--format=')) {
        $format = substr($argument, 9);
    } elseif ($argument === '--yes') {
        $yes = true;
    } else {
        $positional[] = $argument;
    }
}

$usage = static function (): void {
    fwrite(STDERR, "사용법:\n");
    fwrite(STDERR, "  php bin/backup.php create [--format=zip|tar] [--config=/경로/config.php]\n");
    fwrite(STDERR, "  php bin/backup.php list [--config=/경로/config.php]\n");
    fwrite(STDERR, "  php bin/backup.php verify 백업파일.zip|백업파일.tar [--config=/경로/config.php]\n");
    fwrite(STDERR, "  php bin/backup.php restore 백업파일.zip|백업파일.tar --yes [--config=/경로/config.php]\n");
    fwrite(STDERR, "  php bin/backup.php delete 백업파일.zip|백업파일.tar --yes [--config=/경로/config.php]\n");
};

$action = $positional[0] ?? '';
if (!in_array($action, ['create', 'list', 'verify', 'restore', 'delete'], true)) {
    $usage();
    exit(2);
}
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
    $manager = (new App($config, $configFile))->backups();
    if ($action === 'create') {
        $result = $manager->create('cli', $format);
        echo "전체 백업을 만들고 검증했습니다.\n";
        echo "파일: {$result['name']}\n";
        echo "크기: {$result['size']} bytes\n";
        echo "SHA-256: {$result['sha256']}\n";
        exit(0);
    }

    if ($action === 'list') {
        $archives = $manager->status()['archives'];
        if ($archives === []) {
            echo "저장된 전체 백업이 없습니다.\n";
            exit(0);
        }
        foreach ($archives as $archive) {
            $verified = $archive['verified_at'] === null ? '확인 필요' : '검증 ' . $archive['verified_at'];
            echo $archive['name'] . "\t" . $archive['size'] . " bytes\t" . $verified . "\n";
        }
        exit(0);
    }

    $archive = $positional[1] ?? '';
    if ($archive === '') {
        $usage();
        exit(2);
    }
    if ($action === 'verify') {
        $result = $manager->verify($archive);
        echo "백업 형식, 파일 체크섬과 DB 무결성이 올바릅니다.\n";
        echo "파일: {$result['name']}\n";
        echo "DB: {$result['driver']}\n";
        echo "SHA-256: {$result['sha256']}\n";
        exit(0);
    }

    if ($action === 'delete') {
        if (!$yes) {
            fwrite(STDERR, "삭제한 백업은 복구할 수 없습니다. 실행하려면 --yes를 함께 지정하세요.\n");
            exit(2);
        }
        $result = $manager->delete($archive);
        echo "전체 백업을 삭제했습니다.\n";
        echo "파일: {$result['deleted']}\n";
        exit(0);
    }

    if (!$yes) {
        fwrite(STDERR, "복원은 현재 DB와 업로드 파일을 교체합니다. 실행하려면 --yes를 함께 지정하세요.\n");
        exit(2);
    }
    $result = $manager->restore($archive);
    echo "복원을 마쳤습니다.\n";
    echo "복원 파일: {$result['restored']}\n";
    echo "복원 직전 안전 백업: {$result['safety_backup']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, '실패: ' . $e->getMessage() . "\n");
    exit(1);
}
