<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

/** 접수·전송 중과 단말기 전송 결과를 구분한다. 불명확한 상태를 실패로 확정하지 않는다. */
final class SmsResult
{
    public static function status(array $item): string
    {
        $state = is_string($item['sms_state'] ?? null) ? trim($item['sms_state']) : '';
        if (in_array($state, ['성공', '전송성공', '발송성공', '전송완료', '발송완료'], true)) {
            return 'sent';
        }
        if ($state === '' || in_array($state, ['전송중', '발송중', '대기', '대기중', '발송대기', '전송대기',
            '예약대기', '예약중', '접수', '접수완료', '요청중', '처리중'], true)) {
            return 'accepted';
        }
        if (preg_match('/실패|거부|없음|불가|결번|초과|만료|취소|오류|에러|제한|차단|전원꺼짐|통신장애/u', $state)) {
            return 'failed';
        }
        // 새 응답값이나 불완전한 결과는 재조회한다. 기존 조회 기한이 지나면 unknown이 된다.
        return 'accepted';
    }
}
