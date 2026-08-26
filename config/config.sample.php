<?php

declare(strict_types=1);

// 이 파일은 설치 마법사가 만들어 주는 파일의 모양이다.
// 값을 바꾸려고 이 파일을 열 필요는 없다. 프로젝트 루트의 .env 에 적으면
// 여기 있는 값을 덮는다. .env.example 을 참고한다.
return [
    // DSN 은 sqlite: / mysql: / pgsql: 중 하나로 시작한다.
    'db' => [
        'dsn'      => 'sqlite:' . __DIR__ . '/../storage/board.sqlite',
        'username' => null,
        'password' => null,
    ],

    // 호스트 앱과 공유하는 시크릿. 32바이트 이상 임의 문자열.
    'auth' => [
        'secret' => 'CHANGE-ME-32-BYTES-OR-LONGER-RANDOM-STRING',
        'ttl'    => 3600,
        'leeway' => 60,
    ],

    // 호스트 앱을 붙인 뒤에는 null 로 두어 이 경로를 닫는다.
    'bootstrap_admin' => [
        'id'            => 'root',
        'password_hash' => '',
    ],

    'uploads' => [
        'dir'         => __DIR__ . '/../storage/uploads',
        'max_bytes'   => 5 * 1024 * 1024,
        'allowed_ext' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip', 'txt',
            'hwp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        ],
    ],

    // 브라우저가 직접 호출하는 호스트 앱의 출처를 정확히 적는다.
    // 와일드카드는 지원하지 않는다.
    'cors' => [
        'allowed_origins' => [
            // 'https://app.example.com',
        ],
    ],

    'log' => [
        'file' => __DIR__ . '/../storage/logs/error.log',
    ],

    // true 로 두면 오류 응답에 원문 메시지가 포함된다. 운영에서는 반드시 false.
    'debug' => false,
];
