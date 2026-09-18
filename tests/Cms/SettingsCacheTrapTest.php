<?php

declare(strict_types=1);

namespace GnuCms\Tests\Cms;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * WebTestCase::saveSiteSettings() 가 실제로 함정을 막는지 못박는다.
 *
 * 함정: CmsService 는 settings() 를 메모리에 캐시한다. 테스트가 맨 CmsRepository
 * ($app->cms())로 설정을 바꾸면 DB 만 바뀌고, 이미 한 번 settings() 를 읽은
 * CmsService 는 옛 값을 계속 돌려준다. 그 위에서 서비스를 돌린 테스트는 바꾸지도
 * 않은 설정으로 돌아가 없는 실패를 보고하거나, 고침을 되돌려도 통과하는 빈 테스트가
 * 된다. 이 분기에서만 세 번 일어났다.
 *
 * 헬퍼가 캐시를 비우는 줄이 사라지면 아래 testHelperMakesTheServiceSeeTheNewValue()
 * 가 실패한다 — 그래서 이 파일이 헬퍼의 계약이다.
 */
final class SettingsCacheTrapTest extends WebTestCase
{
    /**
     * 함정 그 자체. 맨 리포지토리로 바꾼 값은 이미 캐시를 채운 CmsService 에 닿지
     * 않는다. CmsService 가 언젠가 캐시를 그만두면 이 단언은 지워도 된다 — 그때는
     * 함정 자체가 사라진 것이다. 그전까지는 이 줄이 아래 단언을 공허하지 않게 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testBareRepositoryLeavesTheServiceLookingAtTheOldValue(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        self::assertSame('off', $app->cmsService()->settings()['signup_phone'], '기본값을 읽어 캐시를 채운다');

        $app->cms()->saveSettings(['signup_phone' => 'required']);

        self::assertSame('required', $app->cms()->settings()['signup_phone'], 'DB 에는 분명히 들어갔다');
        self::assertSame('off', $app->cmsService()->settings()['signup_phone'],
            '그런데 서비스는 낡은 캐시를 본다 — 이것이 세 번 값을 치른 함정이다');
    }

    /** 헬퍼로 바꾸면 같은 인스턴스가 곧바로 새 값을 본다. */
    #[DataProvider('connectionProvider')]
    public function testHelperMakesTheServiceSeeTheNewValue(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $service = $app->cmsService();
        self::assertSame('off', $service->settings()['signup_phone']);

        $this->saveSiteSettings($app, ['signup_phone' => 'required']);

        self::assertSame('required', $service->settings()['signup_phone']);
    }

    /**
     * 헬퍼가 닿지 못하는 네 설정은 조용히 지나가지 않는다. post_min_chars 는
     * App::postService() 가 만들어질 때 한 번 읽어 가므로, 그 뒤에 바꾸면 이 헬퍼로도
     * 서비스에 닿지 않는다 — 막지 않으면 "짧은 글이 거절되는지" 같은 테스트가 바꾸지도
     * 않은 값으로 돌아가 통과해 버린다. 함정을 닫겠다는 헬퍼가 같은 함정을 새로 파는 셈이다.
     */
    #[DataProvider('connectionProvider')]
    public function testHelperRefusesASettingAnExistingServiceAlreadyCopied(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->postService(); // 요청 한 번이 하는 일과 같다: 여기서 post_min_chars 를 읽어 간다

        $refused = null;
        try {
            $this->saveSiteSettings($app, ['post_min_chars' => '50']);
        } catch (AssertionFailedError $e) {
            $refused = $e->getMessage();
        }

        self::assertNotNull($refused, '닿지 못하는 설정을 조용히 받아 주면 안 된다');
        self::assertStringContainsString('post_min_chars', $refused);
        self::assertStringContainsString('postService', $refused, '어느 서비스가 범인인지 말해 줘야 한다');
    }

    /** 반대로 서비스가 아직 없으면 같은 설정도 평범하게 통과한다 — 막기만 하면 쓸모가 없다. */
    #[DataProvider('connectionProvider')]
    public function testHelperAllowsTheSameSettingBeforeThatServiceExists(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        $this->saveSiteSettings($app, ['post_min_chars' => '50']);

        self::assertSame(50, $app->cmsService()->settings()['post_min_chars']);
    }

    /**
     * 화면을 거쳐 서비스가 이미 만들어지고 캐시까지 찬 뒤에도 마찬가지다 — 웹
     * 테스트에서 함정이 실제로 열리는 순간이 바로 이 순서이기 때문이다(요청 한 번,
     * 그다음 설정 변경, 그다음 다시 요청).
     */
    #[DataProvider('connectionProvider')]
    public function testHelperWorksAfterARequestHasAlreadyWarmedTheCache(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        self::assertSame(200, $this->get($app, '/login')->getStatusCode());

        $this->saveSiteSettings($app, ['registration_enabled' => '0', 'social_registration_enabled' => '0']);

        self::assertFalse($app->cmsService()->settings()['registration_enabled']);
        self::assertStringNotContainsString('/register', $this->body($this->get($app, '/login')));
    }
}
