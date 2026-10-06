<?php

declare(strict_types=1);

namespace GnuCms\Tests\Notify;

use GnuCms\Aligo\AligoService;
use GnuCms\Aligo\Variables;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Notify\AlimtalkChannel;
use GnuCms\Notify\Recipient;
use GnuCms\Notify\SmsChannel;
use GnuCms\Notify\SmsEditor;
use GnuCms\Tests\Shop\ShopTestCase;
use GnuCms\Tests\Support\FakeAligoTransport;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApprovedTemplateBindingsTest extends ShopTestCase
{
    private const MAPPING = ['welcome' => 'UM_1061', 'password_reset' => 'UM_1062', 'password_changed' => 'UM_1063',
        'order_pending' => 'UM_1065', 'order_paid' => 'UM_1066', 'order_confirmed' => 'UM_1067',
        'order_shipped' => 'UM_1068', 'order_completed' => 'UM_1069', 'order_cancelled' => 'UM_1070',
        'order_refunded' => 'UM_1071', 'inquiry_replied' => 'UM_1072'];

    private function aligo(array $config): array
    {
        $this->setupShop($config);
        $transport = new FakeAligoTransport();
        $aligo = new AligoService($this->app->db(), $transport, new SecretCipher('test-secret'));
        $this->app->setAligo($aligo);
        $aligo->settings->save(['user_id' => 'test', 'api_key' => 'TEST-KEY', 'sender' => '0212345678',
            'senderkey' => 'SK_TEST', 'sms_enabled' => '1', 'alimtalk_enabled' => '1', 'test_mode' => '1']);
        $aligo->setPhoneMode('alimtalk_sms');
        return [$aligo, $transport];
    }

    #[DataProvider('connectionProvider')]
    public function testApprovedTemplatesProduceMatchingUtf8MessagesForAllElevenRules(array $config): void
    {
        [$aligo, $transport] = $this->aligo($config);
        $templates = json_decode(file_get_contents(__DIR__ . '/../fixtures/aligo/gnucms-approved-templates.json'), true, 512, JSON_THROW_ON_ERROR);
        $byCode = [];
        foreach ($templates as $row) {
            $byCode[$row['tpl_code']] = $row;
            $this->app->db()->insert('alimtalk_templates', $row + ['senderkey' => 'SK_TEST', 'enabled' => 0, 'fetched_at' => '2026-10-06 00:00:00']);
        }
        $settings = $this->app->notifySettings();
        $channel = new AlimtalkChannel($aligo, $settings, 'https://example.test');
        $to = Recipient::forUser(['id' => (string) $this->memberId(), 'display_name' => '테스트 주문자',
            'email' => 'test@example.test', 'phone' => '01000000000']);
        $values = SmsEditor::samples();
        $values['이름'] = '테스트 주문자';
        $values['사이트명'] = '테스트 상점';
        $values['사이트주소'] = 'https://example.test';
        $index = 0;
        foreach (self::MAPPING as $event => $code) {
            $settings->save($event, ['tpl_code' => $code, 'delivery_choice' => '1', 'phone' => '1', 'mail' => '0']);
            self::assertTrue($channel->available($event, $to), $event);
            $transport->queue(200, json_encode(['code' => 0, 'info' => ['mid' => 'test-' . ++$index, 'scnt' => 1, 'fcnt' => 0]]));
            $channel->send($event, $to, $values);
            $request = $transport->requests[array_key_last($transport->requests)];
            self::assertSame('https://kakaoapi.aligo.in/akv10/alimtalk/send/', $request['url']);
            self::assertSame($code, $request['fields']['tpl_code']);
            self::assertSame('01000000000', $request['fields']['receiver_1']);
            self::assertSame(Variables::apply($byCode[$code]['content'], $values), $request['fields']['message_1']);
            self::assertTrue(mb_check_encoding($request['fields']['message_1'], 'UTF-8'));
            self::assertStringNotContainsString('#{', $request['fields']['message_1']);
            $settings->save($event, ['delivery_choice' => '1', 'phone' => '0', 'mail' => '0']);
            self::assertFalse($channel->available($event, $to), '개별 발송 해제: ' . $event);
        }
        self::assertCount(11, $transport->requests, '실제 통신과 추가 문자 발송 없이 이벤트별 요청 한 건');
    }

    #[DataProvider('connectionProvider')]
    public function testUnapprovedReturnTemplateIsRejectedAndSmsRemainsAvailable(array $config): void
    {
        [$aligo, $transport] = $this->aligo($config);
        $this->app->db()->insert('alimtalk_templates', ['tpl_code' => 'RETURN_PENDING', 'senderkey' => 'SK_TEST', 'name' => '반품 요청',
            'content' => '#{이름}님 #{주문번호} 반품 요청', 'status' => 'R', 'insp_status' => 'REQ', 'enabled' => 0, 'fetched_at' => '2026-10-06 00:00:00']);
        $settings = $this->app->notifySettings();
        try { $settings->save('order_returning', ['tpl_code' => 'RETURN_PENDING']); self::fail('미승인 연결은 거절'); }
        catch (DomainError $e) { self::assertSame(422, $e->status()); }
        $settings->save('order_returning', ['delivery_choice' => '1', 'phone' => '1', 'mail' => '0']);
        $to = Recipient::forUser(['id' => '1', 'display_name' => '테스트', 'email' => 'test@example.test', 'phone' => '01000000000']);
        self::assertFalse((new AlimtalkChannel($aligo, $settings))->available('order_returning', $to));
        self::assertTrue((new SmsChannel($aligo, $settings))->available('order_returning', $to));
        self::assertCount(0, $transport->requests);
    }
}
