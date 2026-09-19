<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Aligo\AligoService;
use GnuCms\Error\DomainError;

/**
 * 방금 만든 알리고 작업이 실제로 접수됐는지 확인한다. 문자·알림톡 채널이 send() 의
 * 마지막 줄에서 부르고, 접수되지 못했으면 던진다.
 *
 * **왜 이 확인이 따로 필요한가.** Dispatch::send() 는 알리고가 거절하거나 응답이 없어도
 * 예외를 올리지 않는다 — 그 묶음을 'failed' 로 적고 작업 번호를 그대로 돌려준다
 * (Dispatch 의 catch 주석). 관리자 발송 화면에는 그것이 옳다: 500명 중 일부만 실패한
 * 작업도 행으로 남아 이력에 보여야 하고, 다시 보낼지는 사람이 정한다. 그런데 Notifier 는
 * "send() 가 예외 없이 돌아왔다"를 "나갔다"로 센다 — 메일·알림함에는 맞는 판단이지만
 * 전화 채널에는 틀린다. 그 틈으로 실제 결함이 나갔다: password_changed 를 문자 하나로만
 * 켜 둔 사이트에서 알리고가 죽어 있으면 회원 화면과 관리자 화면이 모두
 * 「비밀번호 변경 알림을 보냈습니다.」를 그렸고, 같은 요청이 만든 message_jobs 행은
 * status=failed 였다. 비밀번호 재설정에서는 한 술 더 떠서, "아무 데도 나가지 않았다"고
 * 운영자에게 알리는 로그 한 줄까지 함께 사라졌다(AccountService::requestPasswordReset).
 *
 * **그래서 Dispatch 를 던지게 고치지 않는다.** 삼키는 쪽이 옳은 호출부(관리자 발송)가
 * 있으므로, 알아야 하는 쪽(알림)이 작업 행을 직접 읽는다. 작업 번호는 send() 가 이미
 * 돌려주고 있다.
 *
 * **무엇을 실패로 보는가 — 지금 이 자리에서 확실히 아는 것만이다.**
 *   예약(scheduled)   아직 보낼 때가 아니다. 접수는 됐고 그 시각에 나간다. "아직 안
 *                     나갔다"는 "못 나갔다"가 아니므로 실패가 아니다. 지금 알림은 예약을
 *                     걸지 않지만, 이 갈래가 없으면 언젠가 거는 호출부가 생겼을 때 모든
 *                     예약 알림이 실패로 보고된다.
 *   접수 하나라도 있음 알리고가 받았다. 그 뒤 전달이 실패하는지는 지금 알 수 없고
 *                     (결과 조회가 나중에 이력을 고친다), 모르는 것을 실패라고 하지 않는다.
 *   접수 0 · 실패 > 0 알리고가 한 건도 받지 않았다. 지금 확실히 아는 실패다.
 *
 * 작업 행 자체를 읽지 못하면 아무 말도 하지 않는다 — 모르는 것을 실패로 단정하면 실제로
 * 나간 발송을 실패로 그리게 되고, 그것은 이 클래스가 막으려는 거짓말의 반대 방향이다.
 */
final class PhoneOutcome
{
    /**
     * @param string $channel 실패를 적을 칸 이름. 채널 키를 그대로 쓴다 — 로그 한 줄에서
     *   어느 채널이 접수되지 못했는지가 details() 로 드러난다(Notifier::reason()).
     */
    public static function assertAccepted(AligoService $aligo, string $channel, int $jobId): void
    {
        $job = $aligo->history->job($jobId);
        if ($job === null
            || (string) $job['status'] === 'scheduled'
            || (int) $job['success'] > 0
            || (int) $job['failure'] === 0) {
            return;
        }

        throw DomainError::validation([$channel =>
            '알리고가 이 발송을 접수하지 못했습니다(작업 #' . $jobId . ')' . self::reason($job) . '.']);
    }

    /**
     * 수신자 행에 적힌 실패 사유. Dispatch 가 markChunk() 로 남긴 알리고 쪽 문구이고,
     * 이력 상세의 "사유" 칸이 보여 주는 것과 같은 값이다. 여러 건이면 첫 사유만 쓴다 —
     * 알림 작업의 수신자는 언제나 한 명이고, 그 한 줄이 로그에 필요한 전부다.
     */
    private static function reason(array $job): string
    {
        foreach ($job['recipients'] ?? [] as $row) {
            $reason = trim((string) ($row['rslt_message'] ?? ''));
            if ($reason !== '') {
                return ': ' . $reason;
            }
        }

        return '';
    }
}
