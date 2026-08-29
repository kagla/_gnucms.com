<?php

declare(strict_types=1);

namespace GnuCms\Tests\Cms;

use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Cms\CmsService;
use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Error\DomainError;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

final class CmsServiceConsentTest extends WebTestCase
{
    // 개인정보 초안이 자동 수집 안내(HTML) 문단을 이어 붙이면서 문서 전체가 태그를
    // 포함하게 됐다. HtmlSanitizer::clean() 은 태그가 하나라도 있으면 순수 텍스트용
    // 줄바꿈 처리를 건너뛰므로, 앞쪽 1~9항도 직접 <p> 로 나눠 두지 않으면 정제 후
    // 한 문단으로 뭉개진다. 이 회귀를 잡는다.
    public function testPrivacyDraftRendersAsMultipleBlocks(): void
    {
        $method = new ReflectionMethod(CmsService::class, 'privacyDraft');
        $method->setAccessible(true);
        $service = (new \ReflectionClass(CmsService::class))->newInstanceWithoutConstructor();
        $draft = $method->invoke($service, '테스트');

        $clean = (new HtmlSanitizer())->clean($draft);

        self::assertStringContainsString('<h2>', $clean);
        self::assertGreaterThan(1, substr_count($clean, '<p>'), '개인정보 초안은 여러 문단으로 나뉘어야 한다');
    }

    #[DataProvider('connectionProvider')]
    public function testConsentPagesAreSeparatedFromContents(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = new Acl(Identity::user('1', '관리자', true));

        $app->cmsService()->createPage($acl, [
            'title' => '회사소개', 'slug' => 'company', 'content' => '<p>본문</p>',
            'status' => 'published', 'show_in_menu' => '1', 'sort_order' => '0',
            'image_key' => bin2hex(random_bytes(16)), 'is_consent' => '0',
        ]);
        $termsId = $app->cmsService()->createPage($acl, [
            'title' => '이용약관', 'slug' => 'terms', 'content' => '<p>본문</p>',
            'status' => 'published', 'show_in_menu' => '0', 'sort_order' => '0',
            'image_key' => bin2hex(random_bytes(16)), 'is_consent' => '1',
        ]);

        $contents = array_column($app->cmsService()->contents($acl), 'slug');
        self::assertContains('company', $contents);
        self::assertNotContains('terms', $contents, '약관은 내용 관리 목록에서 빠진다');

        $consents = $app->cmsService()->consentPages($acl);
        self::assertCount(1, $consents);
        self::assertSame('terms', $consents[0]['slug']);
        self::assertSame([], $consents[0]['uses'], '아직 어디에도 안 붙었다');
        self::assertNull($consents[0]['use']);
        self::assertSame(0, $consents[0]['counts']['agreed']);

        // 붙이면 가입 화면 목록에 나온다.
        $app->consentUses()->attach('signup', $termsId, true, 10);
        $signup = $app->cmsService()->consentDocuments('signup');
        self::assertCount(1, $signup);
        self::assertSame('terms', $signup[0]['slug']);
        self::assertSame(1, (int) $signup[0]['required']);
    }

    #[DataProvider('connectionProvider')]
    public function testAttachedConsentCannotBeDeleted(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = new Acl(Identity::user('1', '관리자', true));
        $id = $app->cmsService()->createPage($acl, [
            'title' => '이용약관', 'slug' => 'terms', 'content' => '<p>본문</p>',
            'status' => 'published', 'show_in_menu' => '0', 'sort_order' => '0',
            'image_key' => bin2hex(random_bytes(16)), 'is_consent' => '1',
        ]);
        $app->consentUses()->attach('signup', $id, true, 10);

        try {
            $app->cmsService()->deletePage($acl, $id);
            self::fail('붙어 있는 약관은 지울 수 없어야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('is_consent', $e->details());
        }

        // 붙임을 걷으면 지울 수 있다.
        $app->consentUses()->detachContent($id);
        $app->cmsService()->deletePage($acl, $id);
        self::assertCount(0, $app->cmsService()->consentPages($acl));
    }
}
