<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\ConsentTrace;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConsentRepositoryTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testRecordsForUserAndSubmissionWithTrace(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $consents = $app->consents();
        $id = $app->cms()->createPage([
            'slug' => 'terms', 'title' => '이용약관', 'content' => '본문', 'seo_description' => null,
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'is_consent' => 1,
        ]);
        $doc = $app->cms()->findPageById($id);
        $trace = new ConsentTrace('203.0.113.7', 'Mozilla/5.0 테스트');

        $consents->record('user', 42, 'signup', $doc, true, $trace);
        $consents->record('submission', 7, 'form:event', $doc, false, $trace);

        $user = $consents->forSubject('user', 42);
        self::assertCount(1, $user);
        self::assertSame('signup', $user[0]['scope']);
        self::assertSame('terms', $user[0]['consent_type']);
        self::assertSame(1, (int) $user[0]['agreed']);
        self::assertSame('203.0.113.7', $user[0]['agreed_ip']);

        $submission = $consents->forSubject('submission', 7);
        self::assertCount(1, $submission);
        self::assertSame(0, (int) $submission[0]['agreed']);

        // 다시 받으면 덮어쓴다. 나중에 동의를 켜고 끄는 화면이 이 길을 쓴다.
        $consents->record('submission', 7, 'form:event', $doc, true, $trace);
        self::assertCount(1, $consents->forSubject('submission', 7));
        self::assertSame(1, (int) $consents->forSubject('submission', 7)[0]['agreed']);

        // 같은 사람이라도 자리가 다르면 따로 쌓인다.
        $consents->record('user', 42, 'form:event', $doc, true, $trace);
        self::assertCount(2, $consents->forSubject('user', 42));

        $counts = $consents->countsForContent($id);
        self::assertSame(3, $counts['agreed']);
        self::assertSame(0, $counts['declined']);

        self::assertCount(3, $consents->forContent($id));
    }

    #[DataProvider('connectionProvider')]
    public function testForSubjectWithDocumentMarksChangedDocument(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->cms()->createPage([
            'slug' => 'terms', 'title' => '이용약관', 'content' => '본문', 'seo_description' => null,
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'is_consent' => 1,
        ]);
        $doc = $app->cms()->findPageById($id);
        $app->consents()->record('user', 1, 'signup', $doc, true, null);

        $rows = $app->consents()->forSubjectWithDocument('user', 1);
        self::assertCount(1, $rows);
        self::assertSame('이용약관', $rows[0]['content_title']);
        self::assertSame('terms', $rows[0]['content_slug']);
        self::assertSame($doc['updated_at'], $rows[0]['content_current_updated_at']);
    }
}
