<?php

declare(strict_types=1);

namespace GnuCms\Tests\Service;

use GnuCms\Error\DomainError;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PostAttachmentLimitTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testAttachmentCountIsLimitedBySetting(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['attach_limit' => '2']);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유', 'use_file' => true]);

        $files = [];
        for ($i = 0; $i < 3; $i++) {
            $files[] = $app->attachments()->upload($acl, 'free', $this->fakeUpload('파일' . $i . '.txt', '내용' . $i));
        }

        $post = $app->postService()->create($acl, 'free', [
            'title' => '두 개는 된다', 'content' => '본문', 'attachments' => [$files[0], $files[1]],
        ]);
        self::assertCount(2, $post['attachments']);

        try {
            $app->postService()->create($acl, 'free', [
                'title' => '세 개는 안 된다', 'content' => '본문', 'attachments' => $files,
            ]);
            self::fail('422 가 나와야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertSame('첨부는 2개까지입니다.', $e->details()['attachments']);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testZeroMeansUnlimited(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['attach_limit' => '0']);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유', 'use_file' => true]);

        $attachments = [];
        for ($i = 0; $i < 7; $i++) {
            $attachments[] = $app->attachments()->upload($acl, 'free', $this->fakeUpload('파일' . $i . '.txt', 'x'));
        }
        $post = $app->postService()->create($acl, 'free', [
            'title' => '무제한', 'content' => '본문', 'attachments' => $attachments,
        ]);

        self::assertCount(7, $post['attachments']);
    }

    #[DataProvider('connectionProvider')]
    public function testOrderIsPreservedAsSubmitted(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유', 'use_file' => true]);
        $first = $app->attachments()->upload($acl, 'free', $this->fakeUpload('가.txt', '1'));
        $second = $app->attachments()->upload($acl, 'free', $this->fakeUpload('나.txt', '2'));

        $post = $app->postService()->create($acl, 'free', [
            'title' => '순서', 'content' => '본문', 'attachments' => [$second, $first],
        ]);

        self::assertSame(['나.txt', '가.txt'], array_column($post['attachments'], 'name'));
    }

    #[DataProvider('connectionProvider')]
    public function testUploadSizeFollowsSiteSetting(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['attach_max_mb' => '1']);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유', 'use_file' => true]);

        try {
            $app->attachments()->upload($acl, 'free', $this->fakeUpload('큰파일.txt', str_repeat('a', 1048577)));
            self::fail('413 이 나와야 한다');
        } catch (DomainError $e) {
            self::assertSame(413, $e->status());
        }
    }
}
