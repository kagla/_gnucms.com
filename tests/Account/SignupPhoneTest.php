<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\AccountService;
use GnuCms\Account\ConsentRepository;
use GnuCms\Account\TokenRepository;
use GnuCms\Account\TokenService;
use GnuCms\Account\UserRepository;
use GnuCms\Cms\CmsRepository;
use GnuCms\Cms\CmsService;
use GnuCms\Cms\ConsentUseRepository;
use GnuCms\Cms\ContentImageService;
use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Tests\Support\CollectingMailer;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 가입 화면의 signup_phone 정책(off/optional/required)이 실제로 번호를 받고 저장하는지. */
final class SignupPhoneTest extends DatabaseTestCase
{
    /**
     * off 와 optional 에 *같은* 번호를 넣어 결과를 맞대 본다. off 만 보고 null 을
     * 확인하면, 기능을 통째로 들어내도(어차피 아무도 안 쓰니 늘 null) 테스트가
     * 그대로 통과해 버린다 — optional 쪽이 실제로 저장된다는 것까지 같은 테스트
     * 안에서 확인해야 이 테스트가 무언가를 놓쳤을 때 실패할 수 있다.
     */
    #[DataProvider('connectionProvider')]
    public function testPhoneIsIgnoredWhenTheSiteDoesNotAskForIt(array $config): void
    {
        $input = $this->signup(['phone' => '010-1234-5678']);

        [$offService, $offDb] = $this->boot($config, 'off');
        $offService->register($input);
        self::assertNull($this->lastPhone($offDb));

        [$optionalService, $optionalDb] = $this->boot($config, 'optional');
        $optionalService->register($input);
        self::assertSame('01012345678', $this->lastPhone($optionalDb));
    }

    #[DataProvider('connectionProvider')]
    public function testOptionalPhoneIsStoredWithoutHyphens(array $config): void
    {
        [$service, $db] = $this->boot($config, 'optional');
        $service->register($this->signup(['phone' => '010-1234-5678']));

        self::assertSame('01012345678', $this->lastPhone($db));
    }

    /**
     * optional 의 "빈 값 허용"과 required 의 "빈 값 거절"을 한 테스트 안에서 맞대
     * 본다. optional 쪽만 보고 null 을 확인하면, 기능을 통째로 들어내도(아무 검증도
     * 안 하니 늘 통과) 테스트가 그대로 통과해 버린다.
     */
    #[DataProvider('connectionProvider')]
    public function testOptionalPhoneMayBeLeftBlank(array $config): void
    {
        $input = $this->signup(['phone' => '']);

        [$optionalService, $optionalDb] = $this->boot($config, 'optional');
        $optionalService->register($input);
        self::assertNull($this->lastPhone($optionalDb));

        [$requiredService] = $this->boot($config, 'required');
        try {
            $requiredService->register($input);
            self::fail('required 정책에서는 빈 번호를 거절해야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('phone', $e->details());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testRequiredPhoneRefusesABlankOrBadNumber(array $config): void
    {
        [$service] = $this->boot($config, 'required');

        foreach (['', '02-1234-5678', '010-12'] as $value) {
            try {
                $service->register($this->signup(['phone' => $value, 'email' => uniqid() . '@example.com']));
                self::fail($value . ' 는 거절해야 한다');
            } catch (DomainError $e) {
                self::assertArrayHasKey('phone', $e->details());
            }
        }
    }

    /** freshDatabase() 로 DB 를 만들고 signup_phone 설정을 $policy 로 저장한 뒤 서비스와 연결을 돌려준다. */
    private function boot(array $config, string $policy): array
    {
        $db = $this->freshDatabase($config);
        $cmsRepository = new CmsRepository($db);
        $cmsRepository->saveSettings(['signup_phone' => $policy]);
        $consentUses = new ConsentUseRepository($db);
        $consents = new ConsentRepository($db);
        $service = new AccountService(
            new UserRepository($db),
            new TokenService(new TokenRepository($db)),
            new CollectingMailer(),
            'https://example.test',
            new CmsService(
                $cmsRepository,
                new HtmlSanitizer(),
                new ContentImageService(sys_get_temp_dir() . '/' . GNUCMS_ID . '-signup-phone-test'),
                $consentUses,
                $consents
            ),
            $consents
        );

        return [$service, $db];
    }

    /** 가장 최근에 만든 회원의 phone 칸. */
    private function lastPhone(Connection $db): ?string
    {
        return $db->selectOne('SELECT phone FROM ' . $db->table('users') . ' ORDER BY id DESC')['phone'];
    }

    /** register() 가 요구하는 이메일·비밀번호·표시이름 동의 입력을 채운다. 첫 가입이라 약관 동의는 필요 없다. */
    private function signup(array $override): array
    {
        return array_replace([
            'email' => 'member@example.com',
            'password' => 'safe-password-123',
            'password_confirmation' => 'safe-password-123',
        ], $override);
    }
}
