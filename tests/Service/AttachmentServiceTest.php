<?php

declare(strict_types=1);

namespace GnuCms\Tests\Service;

use GnuCms\Service\AttachmentService;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AttachmentServiceTest extends WebTestCase
{
    public function testServerMaxMbIsPositive(): void
    {
        // php.ini 값에 따라 다르지만 항상 1 이상의 정수여야 한다.
        self::assertGreaterThanOrEqual(1, AttachmentService::serverMaxMb());
    }

    public function testIniShorthandIsParsed(): void
    {
        self::assertSame(8, AttachmentService::iniToMb('8M'));
        self::assertSame(1024, AttachmentService::iniToMb('1G'));
        self::assertSame(1, AttachmentService::iniToMb('1536K'));
        self::assertSame(2, AttachmentService::iniToMb('2097152'));
        self::assertSame(PHP_INT_MAX, AttachmentService::iniToMb('0'), '0 은 무제한이라는 뜻이다');
        self::assertSame(PHP_INT_MAX, AttachmentService::iniToMb('-1'));
    }

    #[DataProvider('connectionProvider')]
    public function testWithSignatureRoundTripsThroughVerify(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유', 'use_file' => true]);
        $descriptor = $app->attachments()->upload($acl, 'free', $this->fakeUpload('문서.txt', '내용'));
        $post = $app->postService()->create($acl, 'free', [
            'title' => '글', 'content' => '본문', 'attachments' => [$descriptor],
        ]);

        // 저장된 디스크립터에는 서명이 없다. 수정 화면이 다시 서명해 폼에 싣는다.
        $stored = $post['attachments'][0];
        self::assertArrayNotHasKey('sig', $stored);
        $signed = $app->attachments()->withSignature($stored);
        self::assertSame($stored['name'], $app->attachments()->verify($signed)['name']);
    }

    #[DataProvider('connectionProvider')]
    public function testCollectGarbageSkipsFreshFiles(array $dbConfig): void
    {
        $this->purgeTestUploads();
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유', 'use_file' => true]);
        $fresh = $app->attachments()->upload($acl, 'free', $this->fakeUpload('방금.txt', '1'));
        $old = $app->attachments()->upload($acl, 'free', $this->fakeUpload('어제.txt', '2'));
        touch($old['path'], time() - 90000);

        $result = $app->attachments()->collectGarbage($acl);

        self::assertFileExists($fresh['path'], '24시간이 안 된 파일은 작성 중일 수 있으니 남긴다');
        self::assertFileDoesNotExist($old['path']);
        self::assertSame(1, $result['deleted']);
    }

    #[DataProvider('connectionProvider')]
    public function testCollectGarbageKeepsReferencedFiles(array $dbConfig): void
    {
        $this->purgeTestUploads();
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유', 'use_file' => true]);
        $kept = $app->attachments()->upload($acl, 'free', $this->fakeUpload('붙음.txt', '1'));
        $app->postService()->create($acl, 'free', ['title' => '글', 'content' => '본문', 'attachments' => [$kept]]);
        touch($kept['path'], time() - 90000);

        $result = $app->attachments()->collectGarbage($acl);

        self::assertFileExists($kept['path']);
        self::assertSame(0, $result['deleted']);
    }
}
