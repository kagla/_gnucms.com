<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

/**
 * message_jobs.status 를 정하는 규칙 한 곳. Dispatch(접수 직후)와 History(결과가
 * 들어올 때마다)가 같은 규칙을 써야 이력 화면이 스스로 모순되지 않는다.
 *
 * 수신자 한 명이 작업 집계에 무엇으로 들어가는지:
 *
 *   queued   아직 알리고에 넘기지도 못했다      → 대기(pending). 성공도 실패도 아니다.
 *   accepted 알리고가 접수했고 결과를 기다린다  → 대기(pending). 성공도 실패도 아니다.
 *   sent     전달 성공을 확인했다               → 성공
 *   failed   전달 실패를 확인했다               → 실패
 *   unknown  7일이 지나 조회를 포기했다         → 성공도 실패도 아니다. 모르는 것은 모른다고 둔다.
 *
 * 대체발송을 켠 알림톡은 fallback_status 가 최종 답이다 — 알림톡이 실패해도 대체문자가
 * 도착했으면 그 사람은 메시지를 받았다(History::tallyOf() 의 COALESCE 참고).
 *
 * 상태 우선순위와 그 이유:
 *   1. 대기가 하나라도 남았으면 최종 상태로 넘어가지 않는다. 결과를 모르는 채로
 *      '성공'·'실패'를 확정하면 이력이 거짓말을 한다.
 *   2. 다만 대기 중이라도 이미 확인된 실패가 있으면 'partial'(일부 실패)이다 — 실패가
 *      있다는 사실 자체는 지금 이미 참이고, 관리자가 바로 알아야 한다.
 *   3. 포기한 건(unknown)이 남아 있으면 'unknown' 이다. 이것을 실패로 접으면 관리자가
 *      "실패"를 보고 다시 보내 중복 발송이 되고, 성공으로 접으면 오지 않은 메시지를
 *      갔다고 말하게 된다. 확인하지 못한 것은 확인하지 못했다고 보여준다.
 */
final class JobStatus
{
    /** 이 다섯 값이 message_jobs.status 가 가질 수 있는 전부다. 이력 화면은 다섯을 모두 구분해 보여준다. */
    public const VALUES = ['sending', 'sent', 'failed', 'partial', 'unknown'];

    public static function of(int $success, int $failure, int $pending, int $unknown): string
    {
        if ($pending > 0) {
            return $failure > 0 ? 'partial' : 'sending';
        }
        if ($unknown > 0) {
            return 'unknown';
        }
        if ($failure === 0) {
            return 'sent';
        }

        return $success === 0 ? 'failed' : 'partial';
    }
}
