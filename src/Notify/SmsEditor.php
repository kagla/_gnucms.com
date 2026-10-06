<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Aligo\MessageText;
use GnuCms\Aligo\Variables;
use GnuCms\Error\DomainError;

/** 문자 편집 검증·예시 치환. 계정·알리고 API를 호출하거나 발송하지 않는다. */
final class SmsEditor
{
    public static function samples(string $event): array
    {
        $values = ['사이트명' => '우리 쇼핑몰', '이름' => '홍길동', '링크' => 'https://example.com/notice',
            '유효시간' => '1시간', '일시' => '26-10-04 15:00:00', '글제목' => '상품 사용 안내',
            '작성자' => '김회원', '주문번호' => '261004-1234567', '주문금액' => '35,000원',
            '결제금액' => '35,000원', '환불금액' => '10,000원', '상품명' => '예시 상품',
            '문의처' => '02-1234-5678 / support@example.com',
            '사이트주소' => 'https://example.com',
            '택배사' => 'CJ대한통운', '운송장번호' => '123456789012', '배송정보' => 'CJ대한통운 / 123456789012'];
        return array_intersect_key($values, array_flip(Events::variables($event)));
    }

    public static function validate(string $event, string $body, string $title): void
    {
        if (!Events::exists($event)) throw DomainError::validation(['event' => '알 수 없는 알림입니다.']);
        $withoutMarkers = preg_replace('/#\{([^}\r\n]{1,50})\}/u', '', $body);
        if (preg_match('/#\{\s*\}/u', $body) || str_contains((string) $withoutMarkers, '#{')) {
            throw DomainError::validation(['sms_body' => '변수 표기를 확인해 주세요. #{이름}처럼 제공되는 변수 이름과 닫는 괄호가 필요합니다.']);
        }
        $unknown = array_diff(Variables::names($body), Events::variables($event));
        if ($unknown !== []) throw DomainError::validation(['sms_body' => '이 알림이 제공하지 않는 변수: ' . implode(', ', $unknown)]);
        if (str_contains($title, '#{')) throw DomainError::validation(['sms_title' => 'LMS 제목은 변수 없이 고정 문구로 입력해 주세요.']);
        try {
            MessageText::assertFits($body, $title === '' ? null : $title);
        } catch (DomainError $e) {
            $details = [];
            foreach ($e->details() as $key => $value) $details[$key === 'title' ? 'sms_title' : 'sms_body'] = $value;
            throw DomainError::validation($details);
        }
    }

    public static function preview(string $event, array $input): array
    {
        if (!Events::phoneCapable($event)) throw DomainError::validation(['event' => '문자를 편집할 수 없는 알림입니다.']);
        $body = is_string($input['sms_body'] ?? null) ? trim($input['sms_body']) : '';
        $title = is_string($input['sms_title'] ?? null) ? trim($input['sms_title']) : '';
        if ($body === '') throw DomainError::validation(['sms_body' => '미리 볼 본문을 입력해 주세요.']);
        self::validate($event, $body, $title);
        $samples = SmsLinks::forBody($event, self::samples($event), 'https://example.com');
        $posted = is_array($input['samples'] ?? null) ? $input['samples'] : [];
        foreach ($samples as $name => &$value) {
            if (array_key_exists($name, $posted)) {
                if (!is_string($posted[$name]) || mb_strlen($posted[$name]) > 1000) {
                    throw DomainError::validation(['sms_preview' => '예시 변수는 1,000자 이하 문자열로 입력해 주세요.']);
                }
                $value = trim($posted[$name]);
            }
        }
        unset($value);
        try {
            $rendered = Variables::apply($body, $samples);
            MessageText::assertFits($rendered, $title === '' ? null : $title);
        } catch (DomainError $e) {
            throw DomainError::validation(['sms_preview' => implode(' ', $e->details())]);
        }
        $kind = MessageText::channelFor($rendered);
        return ['body' => $rendered, 'title' => $kind === 'lms' ? $title : '', 'kind' => $kind,
            'bytes' => MessageText::byteLength($rendered), 'title_bytes' => MessageText::byteLength($title)];
    }
}
