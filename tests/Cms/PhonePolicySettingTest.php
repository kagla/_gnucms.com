<?php

declare(strict_types=1);

namespace GnuCms\Tests\Cms;

use GnuCms\Account\ConsentRepository;
use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Cms\CmsRepository;
use GnuCms\Cms\CmsService;
use GnuCms\Cms\ConsentUseRepository;
use GnuCms\Cms\ContentImageService;
use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 가입 화면에서 휴대폰번호를 받을지 정하는 signup_phone 설정. 기본은 off. */
final class PhonePolicySettingTest extends DatabaseTestCase
{
    #[DataProvider('connectionProvider')]
    public function testDefaultsToNotAskingForAPhone(array $config): void
    {
        $service = $this->cmsService($config);
        self::assertSame('off', $service->settings()['signup_phone']);
    }

    #[DataProvider('connectionProvider')]
    public function testStoresTheChosenPolicy(array $config): void
    {
        $service = $this->cmsService($config);
        $service->saveWritingSettings($this->adminAcl(), $this->writingInput(['signup_phone' => 'required']));
        self::assertSame('required', $service->settings()['signup_phone']);
    }

    #[DataProvider('connectionProvider')]
    public function testUnknownValueFallsBackToOff(array $config): void
    {
        $service = $this->cmsService($config);
        $service->saveWritingSettings($this->adminAcl(), $this->writingInput(['signup_phone' => 'nonsense']));
        self::assertSame('off', $service->settings()['signup_phone']);
    }

    private function cmsService(array $config): CmsService
    {
        $db = $this->freshDatabase($config);

        return new CmsService(
            new CmsRepository($db),
            new HtmlSanitizer(),
            new ContentImageService(sys_get_temp_dir() . '/' . GNUCMS_ID . '-phone-policy-test'),
            new ConsentUseRepository($db),
            new ConsentRepository($db)
        );
    }

    private function adminAcl(): Acl
    {
        return new Acl(Identity::user('1', '관리자', true));
    }

    /** saveWritingSettings() 는 전체 입력을 요구하므로, 나머지 필드는 기본값으로 채우고 필요한 값만 덮어쓴다. */
    private function writingInput(array $overrides = []): array
    {
        return array_replace([
            'guest_write_enabled' => '0',
            'post_min_chars' => '0',
            'comment_min_chars' => '0',
            'post_rate_interval' => '30',
            'post_rate_10m' => '5',
            'post_rate_day' => '20',
            'comment_rate_interval' => '5',
            'comment_rate_10m' => '20',
            'comment_rate_day' => '100',
            'attach_max_mb' => '5',
            'attach_limit' => '5',
            'signup_phone' => 'off',
        ], $overrides);
    }
}
