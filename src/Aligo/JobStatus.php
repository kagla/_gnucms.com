<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

/**
 * message_jobs.status 를 정하는 규칙 한 곳. Dispatch(접수 직후)와 History(결과가
 * 들어올 때마다)가 같은 규칙을 써야 이력 화면이 스스로 모순되지 않는다.
 *
 * 수신자 한 명이 작업 집계에 무엇으로 들어가는지:
 *
 *   queued    아직 알리고에 넘기지도 못했다      → 대기(pending). 성공도 실패도 아니다.
 *   accepted  알리고가 접수했고 결과를 기다린다  → 대기(pending). 성공도 실패도 아니다.
 *   sent      전달 성공을 확인했다               → 성공
 *   failed    전달 실패를 확인했다               → 실패
 *   unknown   7일이 지나 조회를 포기했다         → 성공도 실패도 아니다. 모르는 것은 모른다고 둔다.
 *   cancelled 관리자가 멈춰 알리고가 취소를 확인했다 → 취소. 아래 규칙을 따른다.
 *
 * 취소된 수신자가 작업 수준에서 뜻하는 것(이 규칙이 적힐 곳은 여기 한 군데다):
 * 이 사람은 메시지를 받지 못했고 앞으로도 받지 못한다. 관리자가 그렇게 정했기
 * 때문이다. 그래서 성공이 아니고(아무것도 전달되지 않았다), 실패도 아니며
 * (잘못된 것은 없다), 대기도 아니다(기다릴 결과가 영영 오지 않는다). 세 통에
 * 섞어 넣을 수 없으므로 자기 통(cancelled)을 갖고, message_jobs.cancelled 칸에
 * 그대로 적혀 화면에 숫자로 나온다 — total = 성공 + 실패 + 취소 + 대기 + 불명확 이
 * 언제나 맞아떨어져야 관리자가 "그럼 나머지 둘은 어디 갔나"를 묻지 않는다.
 * 그리고 취소가 하나라도 있으면 그 작업은 절대 '성공'(sent)이 아니다 — 작업이
 * 겨냥했던 사람 전원이 받았다는 뜻이기 때문이다. 전원이 취소됐으면 'cancelled',
 * 일부만 취소됐으면 'partial'(일부만 발송)이다.
 *
 * 대체발송을 켠 알림톡은 fallback_status 가 최종 답이다 — 알림톡이 실패해도 대체문자가
 * 도착했으면 그 사람은 메시지를 받았다(History::recomputeJob() 의 COALESCE 참고).
 *
 * 상태 우선순위와 그 이유:
 *   1. 대기가 하나라도 남았으면 최종 상태로 넘어가지 않는다. 결과를 모르는 채로
 *      '성공'·'실패'를 확정하면 이력이 거짓말을 한다.
 *   2. 다만 대기 중이라도 이미 확인된 실패나 취소가 있으면 'partial'(일부만 발송)이다 —
 *      실패·취소가 있다는 사실 자체는 지금 이미 참이고, 관리자가 바로 알아야 한다.
 *   3. 포기한 건(unknown)이 남아 있으면 'unknown' 이다. 이것을 실패로 접으면 관리자가
 *      "실패"를 보고 다시 보내 중복 발송이 되고, 성공으로 접으면 오지 않은 메시지를
 *      갔다고 말하게 된다. 확인하지 못한 것은 확인하지 못했다고 보여준다.
 *   4. 아무도 받지 못했는데 확인된 실패가 있으면 'failed' 다 — 취소가 섞여 있어도
 *      그렇다. 나간 것이 하나도 없는 작업을 '일부만 발송'이라 부르지 않는다.
 *
 * 'scheduled'(예약)는 이 집계에서 나오지 않는다 — 수신자 결과를 세어서 아는 사실이
 * 아니라, 호출부가 tally 를 보기도 전에 이미 알고 있는 작업 자체의 사실이다(예약해
 * 두었으니 예약이다). 그래서 of() 는 이 값을 만들지 않는다. 'cancelled'는 반대로 두
 * 길이 모두 있다: 관리자가 취소했다는 사실을 아는 호출부(Dispatch::cancel())가 직접
 * 적기도 하고, 수신자가 전부 취소로 정리된 작업을 of() 가 다시 세어 같은 결론에
 * 이르기도 한다 — 두 길이 같은 답을 내야 하므로 규칙은 여기 한 곳에만 둔다.
 */
final class JobStatus
{
    /**
     * message_jobs.status 가 가질 수 있는 값 전부. 이력 화면은 일곱을 모두 구분해 보여준다.
     * 이 중 'scheduled'는 of() 가 만들지 않는다 — 위 docblock 참고.
     */
    public const VALUES = ['scheduled', 'sending', 'sent', 'failed', 'partial', 'unknown', 'cancelled'];

    public static function of(int $success, int $failure, int $pending, int $unknown, int $cancelled = 0): string
    {
        if ($pending > 0) {
            return ($failure > 0 || $cancelled > 0) ? 'partial' : 'sending';
        }
        if ($unknown > 0) {
            return 'unknown';
        }
        if ($success === 0 && $failure > 0) {
            return 'failed';
        }
        if ($success === 0 && $cancelled > 0) {
            return 'cancelled';
        }
        if ($failure === 0 && $cancelled === 0) {
            return 'sent';
        }

        return 'partial';
    }
}
