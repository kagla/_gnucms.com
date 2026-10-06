<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

/**
 * 알리고가 돌려주는 코드를 관리자가 읽고 조치할 수 있는 문장으로 바꾼다.
 * 응답 원문을 그대로 화면에 싣지 않기 위한 유일한 통로다.
 *
 * 코드 매핑은 알리고 문서 중 검증되지 않은 부분이므로 실제 오류 목록과 차이가 있을 수 있다.
 * 매핑에 없는 코드는 원본 코드와 메시지를 담아 돌려주므로 향후 확인과 수정이 가능하다.
 */
final class ResultCodes
{
    private const SMS = [
        '-101' => '등록되지 않은 발신번호입니다. 알리고에서 발신번호를 사전 등록한 뒤 설정에 같은 번호를 저장해 주세요.',
        '-102' => '수신번호 형식이 올바르지 않습니다.',
        '-103' => '본문이 비어 있거나 허용 길이를 넘었습니다.',
        '-104' => '인증에 실패했습니다. API 키와 사용자 ID를 확인해 주세요.',
        '-201' => '등록되지 않은 IP에서 요청했습니다. 알리고에서 서버 IP를 등록해 주세요.',
        '-804' => '발송 5분 전까지만 취소할 수 있습니다.',
    ];

    private const ALIMTALK = [
        '501' => '인증에 실패했습니다. API 키와 사용자 ID를 확인해 주세요.',
        '509' => '발신프로필을 확인할 수 없습니다. 카카오채널 관리자 알림 설정과 발신프로필키를 확인해 주세요.',
        '510' => '승인되지 않은 템플릿입니다. 카카오 검수가 끝난 템플릿만 보낼 수 있습니다.',
        '520' => '발신프로필이 차단되었거나 삭제되었습니다. 알리고에서 채널 상태를 확인해 주세요.',
    ];

    private const DELIVERY = [
        'U' => '승인된 템플릿 본문과 맞지 않습니다. 템플릿을 다시 가져오고 변수값을 확인해 주세요.',
        'K' => '수신자가 카카오톡을 쓰지 않거나 알림톡을 차단했습니다.',
        'M' => '메시지 형식이 올바르지 않습니다.',
        'P' => '발신프로필에 문제가 있습니다.',
        'S' => '정상 처리되었습니다.',
    ];

    public static function smsReason(string $code, string $message): string
    {
        return self::lookup(self::SMS, $code, $message);
    }

    public static function alimtalkReason(string $code, string $message): string
    {
        return self::lookup(self::ALIMTALK, $code, $message);
    }

    public static function deliveryReason(string $rslt, string $message): string
    {
        return self::lookup(self::DELIVERY, $rslt, $message);
    }

    private static function lookup(array $table, string $code, string $message): string
    {
        if (isset($table[$code])) {
            return $table[$code];
        }
        $original = trim($message);

        return '알리고가 처리하지 못했습니다 (코드 ' . $code . ')'
            . ($original !== '' ? '. ' . $original : '.');
    }
}
