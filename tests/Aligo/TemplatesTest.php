<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\AlimtalkApi;
use GnuCms\Aligo\Settings;
use GnuCms\Aligo\SettingsRepository;
use GnuCms\Aligo\Templates;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Tests\Support\DatabaseTestCase;
use GnuCms\Tests\Support\FakeAligoTransport;
use PHPUnit\Framework\Attributes\DataProvider;

final class TemplatesTest extends DatabaseTestCase
{
    private FakeAligoTransport $transport;
    private Templates $templates;

    private function boot(array $config): void
    {
        $db = $this->freshDatabase($config);
        $settings = new Settings(new SettingsRepository($db), new SecretCipher('s'));
        $settings->save(['user_id' => 'shop', 'api_key' => 'K', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        $this->transport = new FakeAligoTransport();
        $this->templates = new Templates($db, new AlimtalkApi($this->transport, $settings), $settings);
    }

    private function queueList(array $items): void
    {
        $this->transport->queue(200, (string) json_encode(['code' => 0, 'list' => $items]));
    }

    #[DataProvider('connectionProvider')]
    public function testImportedCopiesStartDisabled(array $config): void
    {
        $this->boot($config);
        $this->queueList([[
            'templtCode' => 'T1', 'templtName' => '주문 안내', 'templtContent' => '#{이름}님 주문 #{주문번호}',
            'templateType' => 'BA', 'templateEmType' => 'NONE', 'status' => 'A', 'inspStatus' => 'APR',
            'buttons' => [['ordering' => 1, 'name' => '주문확인', 'linkType' => 'WL']],
        ]]);

        self::assertSame(['imported' => 1, 'updated' => 0, 'disabled' => 0], $this->templates->fetch());
        $copy = $this->templates->find('T1');
        self::assertSame('주문 안내', $copy['name']);
        self::assertSame(0, (int) $copy['enabled']);
        self::assertSame([], $this->templates->usable());
    }

    #[DataProvider('connectionProvider')]
    public function testOnlyApprovedTemplatesCanBeTurnedOn(array $config): void
    {
        $this->boot($config);
        $this->queueList([
            ['templtCode' => 'OK', 'templtName' => '승인', 'templtContent' => '본문',
                'status' => 'A', 'inspStatus' => 'APR'],
            ['templtCode' => 'WAIT', 'templtName' => '대기', 'templtContent' => '본문',
                'status' => 'R', 'inspStatus' => 'REQ'],
        ]);
        $this->templates->fetch();

        $this->templates->setEnabled('OK', true);
        self::assertCount(1, $this->templates->usable());

        $this->expectException(DomainError::class);
        $this->templates->setEnabled('WAIT', true);
    }

    #[DataProvider('connectionProvider')]
    public function testRefetchDisablesACopyThatLostApproval(array $config): void
    {
        $this->boot($config);
        $this->queueList([['templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '본문',
            'status' => 'A', 'inspStatus' => 'APR']]);
        $this->templates->fetch();
        $this->templates->setEnabled('T1', true);

        $this->queueList([['templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '본문',
            'status' => 'S', 'inspStatus' => 'APR']]);
        self::assertSame(['imported' => 0, 'updated' => 1, 'disabled' => 1], $this->templates->fetch());
        self::assertSame([], $this->templates->usable());
    }

    #[DataProvider('connectionProvider')]
    public function testRefetchDisablesACopyThatVanishedFromTheAligoList(array $config): void
    {
        $this->boot($config);
        $this->queueList([['templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '본문',
            'status' => 'A', 'inspStatus' => 'APR']]);
        $this->templates->fetch();
        $this->templates->setEnabled('T1', true);

        // T1 은 빠지고 다른 템플릿만 온, 비어있지 않은 목록 — T1 이 통째로 사라진 것으로 본다
        // (삭제되었거나 더는 이 발신프로필 소속이 아님).
        $this->queueList([['templtCode' => 'T2', 'templtName' => '다른 안내', 'templtContent' => '본문',
            'status' => 'A', 'inspStatus' => 'APR']]);
        self::assertSame(['imported' => 1, 'updated' => 0, 'disabled' => 1], $this->templates->fetch());
        self::assertSame([], $this->templates->usable());

        // 사본 자체는 남아 마지막으로 확인한 내용·승인상태를 그대로 보여준다 (이력용).
        $copy = $this->templates->find('T1');
        self::assertNotNull($copy);
        self::assertSame(0, (int) $copy['enabled']);
        self::assertSame('A', $copy['status']);
        self::assertSame('APR', $copy['insp_status']);

        // 이미 꺼져 있던 사본이 다시 같은 목록에 없어도 disabled 는 중복으로 늘지 않는다.
        $this->queueList([['templtCode' => 'T2', 'templtName' => '다른 안내', 'templtContent' => '본문',
            'status' => 'A', 'inspStatus' => 'APR']]);
        self::assertSame(['imported' => 0, 'updated' => 0, 'disabled' => 0], $this->templates->fetch());
    }

    #[DataProvider('connectionProvider')]
    public function testEmptyListLeavesAnEnabledCopyEnabled(array $config): void
    {
        $this->boot($config);
        $this->queueList([['templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '본문',
            'status' => 'A', 'inspStatus' => 'APR']]);
        $this->templates->fetch();
        $this->templates->setEnabled('T1', true);

        // 정상적인 빈 목록인지, 알리고 쪽 이상 응답으로 우연히 비었는지는 구별할 수 없다.
        // 구분할 수 없다면 꺼서 알림톡 전체를 조용히 멈추기보다는 그대로 둔다.
        $this->queueList([]);
        self::assertSame(['imported' => 0, 'updated' => 0, 'disabled' => 0], $this->templates->fetch());
        self::assertCount(1, $this->templates->usable());
        $copy = $this->templates->find('T1');
        self::assertSame(1, (int) $copy['enabled']);
    }

    #[DataProvider('connectionProvider')]
    public function testSecondIdenticalFetchDoesNotChurnCounts(array $config): void
    {
        $this->boot($config);
        $item = ['templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '#{이름}님 안내',
            'templateType' => 'BA', 'templateEmType' => 'NONE', 'status' => 'A', 'inspStatus' => 'APR',
            'buttons' => [['ordering' => 1, 'name' => '확인', 'linkType' => 'WL']]];

        $this->queueList([$item]);
        self::assertSame(['imported' => 1, 'updated' => 0, 'disabled' => 0], $this->templates->fetch());

        $this->queueList([$item]);
        self::assertSame(['imported' => 0, 'updated' => 0, 'disabled' => 0], $this->templates->fetch());
    }
}
