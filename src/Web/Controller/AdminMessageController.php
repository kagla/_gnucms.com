<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\Aligo\History;
use GnuCms\Aligo\MessageText;
use GnuCms\Aligo\PhoneNumber;
use GnuCms\Aligo\SendTime;
use GnuCms\Aligo\TransportFailure;
use GnuCms\Aligo\Variables;
use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Notify\Events;
use GnuCms\Notify\SmsEditor;
use GnuCms\Support\Clock;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * 운영 › 알림톡·문자 화면. 발송·템플릿·이력 3개 탭을 모두 이 컨트롤러가 담당한다.
 * 발송 탭에서는 여기서 메시지를 만들거나 승인하지 않는다 — 실제 거절(미승인
 * 템플릿·빈 변수·꺼진 채널)은 모두 AligoService::send() 가 맡고, 이 화면은 그 앞에서
 * 무엇이 나갈지 보여줄 뿐이다. 이력 탭은 알리고에 결과 웹훅이 없어 History::refresh()
 * 를 화면을 열 때마다 조용히 한 번 불러 조금씩 갱신한다 — 실패해도 목록은 저장된
 * 값으로 그대로 보여주고, 다음 방문에서 다시 시도한다(실패 사유는 화면에 적는다).
 *
 * 화면에 그대로 찍을 문장을 쿼리 문자열로 받지 않는다. 받으면 공격자가 만든 URL 을
 * 관리자가 열었을 때 우리가 그 문장을 시스템 성공 알림처럼 보여주게 된다. 그래서
 * 리다이렉트에는 숫자·작업 번호 같은 구조화된 값만 싣고 문장은 여기서 만든다.
 */
final class AdminMessageController
{
    /**
     * 같은 내용을 이 시간 안에 다시 보내면 두 번째 요청은 실제로 보내지 않는다.
     * 발송 버튼을 두 번 누르거나 결과 화면에서 새로고침을 눌렀을 때 실제 전화기에
     * 두 번 가고 요금도 두 번 나가는 것을 막는다. 토큰을 따로 발급·소모·만료시키는
     * 장치를 새로 만들지 않고, 세션에 "방금 무엇을 보냈는지"만 적어 둔다.
     */
    private const DOUBLE_SUBMIT_SECONDS = 10;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function templates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();

        return $this->render($request, $response, null,
            $this->templatesNotice($request->getQueryParams()));
    }

    public function fetchTemplates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $counts = $this->app->aligo()->importTemplates();
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->render($request, $response->withStatus(422), $this->firstError($e));
        }

        $query = [
            'imported' => (string) (int) $counts['imported'],
            'updated' => (string) (int) $counts['updated'],
            'disabled' => (string) (int) $counts['disabled'],
        ];
        // 승인·정상 상태를 잃어 자동으로 꺼진 템플릿에 걸려 있던 예약의 취소 결과.
        // AdminAligoController::toggle() 과 같은 규칙으로, 취소할 것이 아예 없었으면
        // (cancelled=0, failed=0) 문장에 더할 것이 없으므로 숫자 자체를 싣지 않는다
        // (templatesNotice() 도 이 값이 없으면 문장에 취소 얘기를 더하지 않는다).
        if ($counts['cancelled'] > 0 || $counts['failed'] > 0) {
            $query['cancel_ok'] = (string) (int) $counts['cancelled'];
            $query['cancel_failed'] = (string) (int) $counts['failed'];
        }

        return $this->redirect($request, $response, 'admin.messages.templates', $query);
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();

        $query = $request->getQueryParams();
        $input = ['channel' => 'sms',
            'var_문의처' => $this->app->cmsService()->notificationContact(
                (string) $this->app->config('app.url', GNUCMS_URL)
            )];
        $member = is_string($query['member'] ?? null) && preg_match('/^[1-9][0-9]{0,18}$/D', $query['member']) ? $query['member'] : '';
        if ($member !== '') $input['members'] = [$member];
        $preset = is_string($query['preset'] ?? null) ? $query['preset'] : '';
        if (Events::phoneCapable($preset)) {
            $values = $this->app->notifySettings()->formValues()[$preset];
            $input['body'] = $values['sms_body_stored'] !== '' ? $values['sms_body_stored'] : Events::defaultSmsBody($preset);
            $input['title'] = $values['sms_title_stored'];
            $input['var_사이트명'] = (string) $this->app->cmsService()->settings()['site_name'];
            $input['template_event'] = $preset;
            // 재설정 링크 등 비밀이나 예시 데이터를 직접 발송 칸에 자동으로 넣지 않는다.
        }
        // 상세 화면의 링크는 수신 회원과 실제 업무 값을 서버에서 가져온다.
        $orderId = is_string($query['order'] ?? null) && preg_match('/^[1-9][0-9]{0,18}$/D', $query['order']) ? $query['order'] : '';
        if ($orderId !== '') {
            $order = $this->app->db()->selectOne('SELECT user_id, number, total, paid_amount, carrier, tracking_number FROM '
                . $this->app->db()->table('yc_orders') . ' WHERE id = ?', [$orderId]);
            if ($order !== null) {
                $input['members'] = [(string) $order['user_id']];
                $input['var_주문번호'] = (string) $order['number'];
                $input['var_주문금액'] = number_format((int) $order['total']) . '원';
                $input['var_결제금액'] = number_format((int) $order['paid_amount']) . '원';
                $input['var_택배사'] = (string) $order['carrier'];
                $input['var_운송장번호'] = (string) $order['tracking_number'];
                $input['var_배송정보'] = trim((string) $order['carrier'] . ' / ' . (string) $order['tracking_number'], ' /');
                $input['var_링크'] = rtrim((string) $this->app->config('app.url', GNUCMS_URL), '/') . '/shop/order?number=' . rawurlencode((string) $order['number']);
            }
        }
        $inquiryId = is_string($query['inquiry'] ?? null) && preg_match('/^[1-9][0-9]{0,18}$/D', $query['inquiry']) ? $query['inquiry'] : '';
        if ($inquiryId !== '' && $orderId === '') {
            $inquiry = $this->app->db()->selectOne('SELECT f.user_id, p.name FROM ' . $this->app->db()->table('yc_product_feedback')
                . ' f JOIN ' . $this->app->db()->table('yc_products') . " p ON p.id = f.product_id WHERE f.id = ? AND f.kind = 'inquiry'", [$inquiryId]);
            if ($inquiry !== null) {
                $input['members'] = [(string) $inquiry['user_id']];
                $input['var_상품명'] = (string) $inquiry['name'];
                $input['var_링크'] = rtrim((string) $this->app->config('app.url', GNUCMS_URL), '/') . '/shop/inquiry/' . $inquiryId;
            }
        }
        if (count($input['members'] ?? []) === 1) {
            $user = $this->app->users()->findById((int) $input['members'][0]);
            if ($user !== null && $user['status'] === 'active') $input['var_이름'] = (string) $user['display_name'];
        }
        $error = null;
        $tplCode = is_string($query['tpl_code'] ?? null) ? trim($query['tpl_code']) : '';
        if ($tplCode !== '') {
            $input['channel'] = 'at';
            $template = strlen($tplCode) <= 64 ? $this->app->aligo()->templates->find($tplCode) : null;
            if ($template === null || !$this->app->aligo()->templates->canUse($template)) {
                $error = '이 템플릿은 현재 채널에서 발송할 수 없습니다. 승인 상태와 채널을 확인해 주세요.';
            } else {
                $input['tpl_code'] = $tplCode;
            }
        }
        return $this->renderSend($request, $response, $input, $error, [], null);
    }

    /**
     * 미리보기. collect() 로 만든 첫 수신자의 본문만 Variables::apply() 로 채워 보여주고
     * 절대 보내지 않는다 — AligoService::send() 는 여기서 부르지 않는다.
     */
    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();

        // collect() 도 이 try 안에 있다 — scheduled_at 검증(SendTime::parse(), collect()
        // 가 위임한다)이 여기서 실패할 수 있고, 그 실패도 buildPreview() 의 실패(빈 변수
        // 거절 등)와 똑같이 422 로 다시 그려 입력을 지키지 않으면 안 된다.
        try {
            $collected = $this->collect($input);
            $preview = $this->buildPreview($collected);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->renderSend(
                $request, $response->withStatus(422), $input, $this->firstError($e), $e->details(), null
            );
        }

        return $this->renderSend($request, $response, $input, null, [], $preview);
    }

    /**
     * 실제 발송. collect() 가 만든 요청을 그대로 AligoService::send() 하나에만 넘긴다.
     *
     * 보내기 전에 중복 발송을 한 번 막는다. 발송 버튼은 평범한 submit 이라 두 번
     * 누르면 요청도 두 번 가고, 그러면 작업도 두 개 만들어져 실제 전화기에 두 번
     * 가고 요금도 두 번 나간다. 화면 쪽에서도 누르는 즉시 버튼을 잠그지만(send.php),
     * 자바스크립트를 믿고 돈을 걸 수는 없으므로 여기서도 막는다.
     *
     * 막는 방법은 세션에 "방금 무엇을 보냈는지"(내용 지문·시각·작업 번호)를 적어 두고,
     * 같은 지문이 DOUBLE_SUBMIT_SECONDS 안에 다시 오면 보내지 않고 그때 만든 작업으로
     * 보내는 것이다. 토큰을 따로 발급·보관·소모·만료시키는 장치를 새로 들이지 않는다.
     * PHP 세션은 요청마다 잠기므로 두 번째 클릭은 첫 번째가 끝난 뒤에야 들어오고,
     * 그때는 이 기록이 이미 남아 있다. 내용이 조금이라도 다르면 지문이 달라지므로
     * 일부러 다시 보내는 것은 막지 않는다.
     */
    public function dispatch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();

        // collect() 도 이 try 안에 있다 — scheduled_at 검증(SendTime::parse(), collect()
        // 가 위임한다)이 여기서 실패할 수 있고, 지문을 만들거나 알리고를 부르기도 전에
        // 같은 422 로 다시 그려 입력을 지켜야 한다.
        try {
            $collected = $this->collect($input);
            // 별도 예시 발송 버튼을 선택한 요청에만 빈 변수의 예시 값을 적용한다.
            if (($input['send_example'] ?? '') === '1') {
                $collected['request'] = $this->withExampleValues($collected['request']);
            }
            $fingerprint = self::fingerprint($collected['request']);
            $alreadySent = $this->recentlySentJob($fingerprint);
            if ($alreadySent !== null) {
                return $this->redirect($request, $response, 'admin.messages.history.detail',
                    ['sent' => (string) $alreadySent, 'duplicate' => '1'], ['id' => (string) $alreadySent]);
            }

            $jobId = $this->app->aligo()->send($collected['request']);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->renderSend(
                $request, $response->withStatus(422), $input, $this->firstError($e), $e->details(), null
            );
        }
        $this->rememberSend($fingerprint, $jobId);

        // 방금 만든 작업의 이력 상세로 보낸다. 알리고는 결과 웹훅이 없으므로 이 시점엔
        // 아직 결과를 모른다 — 그 화면 자체가 "결과를 기다리는 중"이라고 정직하게 말한다.
        // 문장이 아니라 숫자만 넘긴다(클래스 주석 참고). 제외 인원은 collect() 가 이미
        // 세어 뒀으므로 버리지 않고 그대로 실어 보낸다 — 관리자는 "누가 빠졌는지"를
        // 미리보기에서만이 아니라 보낸 뒤에도 알 수 있어야 한다.
        return $this->redirect($request, $response, 'admin.messages.history.detail', [
            'sent' => (string) $jobId,
            'to' => (string) $this->jobTotal($jobId),
            'nophone' => (string) $collected['skipped'],
            'blocked' => (string) $collected['ineligible'],
            'missing' => (string) $collected['missing'],
        ], ['id' => (string) $jobId]);
    }

    /** 실제로 보낸 사람 수. 번호 정규화·중복 제거가 끝난 뒤의 수라 화면 입력 수와 다를 수 있다. */
    private function jobTotal(int $jobId): int
    {
        $row = $this->app->db()->selectOne('SELECT total FROM '
            . $this->app->db()->table('message_jobs') . ' WHERE id = ?', [$jobId]);

        return $row === null ? 0 : (int) $row['total'];
    }

    /** 같은 발송 요청인지 가리는 지문. 번호·본문·변수까지 들어가므로 내용이 다르면 달라진다. */
    private static function fingerprint(array $request): string
    {
        return hash('sha256', serialize($request));
    }

    /** 방금 같은 내용을 보냈으면 그때 만든 작업 번호. 아니면 null. */
    private function recentlySentJob(string $fingerprint): ?int
    {
        $memo = $_SESSION['aligo_last_send'] ?? null;
        if (!is_array($memo) || ($memo['hash'] ?? null) !== $fingerprint) {
            return null;
        }
        if (Clock::timestamp() - (int) ($memo['at'] ?? 0) > self::DOUBLE_SUBMIT_SECONDS) {
            return null;
        }
        $jobId = (int) ($memo['job'] ?? 0);

        return $jobId > 0 ? $jobId : null;
    }

    private function rememberSend(string $fingerprint, int $jobId): void
    {
        $_SESSION['aligo_last_send'] = ['hash' => $fingerprint, 'at' => Clock::timestamp(), 'job' => $jobId];
    }

    /**
     * 이력 목록. 화면을 그리기 전에 결과 조회를 한 번 시도한다 — 알리고가 웹훅을 주지
     * 않으므로 관리자가 들를 때마다 조금씩 갱신하는 것이 유일한 갱신 수단이다. 조회
     * 자체가 실패해도(알리고 접속 불가 등) 목록은 저장된 값 그대로 보여준다.
     */
    public function history(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        // 방금 누른 갱신이 실패했다면(아래 refresh()) 그 사실만 깃발로 넘어온다.
        // 아래 조회가 이번에도 실패하면 구체적인 사유로 덮인다 — 재확인 주기(60초)
        // 안이라 이번 방문에서는 아무것도 묻지 않고 지나갈 수 있기 때문에, 그때도
        // "조금 전 실패했다"는 사실만은 남아야 한다. 문장은 여기서 만든다.
        $refreshError = (($request->getQueryParams()['failed'] ?? '') === '1')
            ? '결과 조회에 실패했습니다. 잠시 뒤 다시 시도해 주세요.'
            : null;
        try {
            $this->app->aligo()->history->refresh();
            // refresh() 자체는 개별 조회 실패를 삼키고 다음 방문에 다시 시도한다.
            // 그 실패를 여기서도 버리면, API 키가 취소된 사이트의 관리자는 결과가
            // 천천히 "결과를 알 수 없음"으로 바뀌는 것만 볼 뿐 이유를 끝내 알 수 없다.
            $refreshError = $this->app->aligo()->history->lastFailure() ?? $refreshError;
        } catch (DomainError | TransportFailure $e) {
            // 조회에 실패해도 이력 목록은 보여준다. 다음 방문에 다시 시도한다.
            // 사유는 화면에 적는다 — DomainError·TransportFailure 메시지에는 API 키가
            // 실리지 않는다(원문 대신 ResultCodes 가 정리한 사유만 담긴다).
            $refreshError = $e instanceof DomainError ? $this->firstError($e) : $e->getMessage();
        }
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $event = self::eventFilter($request->getQueryParams());
        $listing = $this->app->aligo()->history->jobs($page, 20, $event);
        $listing['items'] = array_map([$this, 'withTemplateLabel'], $listing['items']);

        return View::fromRequest($request)->render($response, 'admin/message/history', [
            'listing' => $listing,
            'pending' => $this->app->aligo()->history->pendingCount(),
            'refresh_error' => $refreshError,
            'event_filter' => $event ?? '',
            'event_labels' => Events::labels(),
            'query' => $request->getQueryParams(),
        ]);
    }

    /**
     * 이력을 거를 값. 쿼리에서 받는 것은 문장이 아니라 **열거값 하나**다 — 빈 값(전부),
     * History::MANUAL(관리자가 손으로 보낸 것), 또는 카탈로그가 아는 이벤트 키. 그 밖의
     * 값은 거르지 않은 것으로 본다: 우리 화면의 <select> 가 낼 수 없는 값이므로 URL 을
     * 손으로 고친 것이고, 모르는 값으로 빈 목록을 보여 주면 "그 알림은 한 번도 나가지
     * 않았다"는 거짓을 말하게 된다.
     */
    private static function eventFilter(array $query): ?string
    {
        $value = is_scalar($query['event'] ?? null) ? (string) $query['event'] : '';
        if ($value === History::MANUAL || Events::exists($value)) {
            return $value;
        }

        return null;
    }

    /**
     * 관리자가 손으로 한 번 더 결과를 조회한다. 실패해도(알리고 접속 불가 등) 화면은
     * 깨지지 않고 목록으로 그대로 돌아간다 — 다음 방문에서 또 시도할 수 있다.
     */
    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        $failed = false;
        try {
            $this->app->aligo()->history->refresh();
            $failed = $this->app->aligo()->history->lastFailure() !== null;
        } catch (DomainError | TransportFailure $e) {
            // 갱신 실패로 화면이 깨지지는 않는다. 목록은 저장된 값으로 다시 그려진다.
            $failed = true;
        }
        $page = max(1, (int) ($input['page'] ?? 1));
        // 실패했다는 사실만 깃발로 넘긴다. 사유 문장을 URL 에 실어 보내면 그 자리가
        // 공격자에게 열린다(클래스 주석 참고) — 목록 화면이 문장을 만든다.
        $query = $page > 1 ? ['page' => (string) $page] : [];
        // 갱신하고 돌아왔더니 거르기가 풀려 있으면, 관리자는 방금 보던 목록을 잃는다.
        // 폼이 실어 보낸 값도 목록 화면과 똑같이 열거값으로만 받아들인다.
        $event = self::eventFilter(is_array($input) ? $input : []);
        if ($event !== null) {
            $query['event'] = $event;
        }
        if ($failed) {
            $query['failed'] = '1';
        }

        return $this->redirect($request, $response, 'admin.messages.history', $query);
    }

    /** 작업 하나의 상세. 수신자별 결과를 전체 번호로 보여준다(목록과 달리 여기서만 전체를 보여준다). */
    public function historyDetail(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $this->app->guestAcl()->assertGlobalAdmin();
        $job = $this->app->aligo()->history->job((int) $args['id']);
        if ($job === null) {
            throw DomainError::notFound('이력을 찾을 수 없습니다.');
        }
        $job = $this->withTemplateLabel($job);
        $job['sender_display'] = PhoneNumber::format((string) $job['sender']);
        $job['recipients'] = array_map(static function (array $r): array {
            // 상세 화면에서만 전체 번호를 보여준다 — 목록에서는 절대 이 값을 쓰지 않는다.
            $r['phone_display'] = PhoneNumber::format((string) $r['phone']);

            return $r;
        }, $job['recipients']);

        return View::fromRequest($request)->render($response, 'admin/message/history_detail', [
            'job' => $job,
            'event_labels' => Events::labels(),
            'notice' => $this->dispatchNotice($request->getQueryParams(), (int) $job['id']),
            'cancel_notice' => $this->cancelNotice($request->getQueryParams(), (int) $job['id']),
        ]);
    }

    /**
     * 예약된 작업의 취소. AligoService::cancel() 이 돌려주는 결과(부분 취소 포함)를
     * 문장이 아니라 숫자로만 리다이렉트에 싣는다(클래스 주석 참고) — 문장은
     * cancelNotice() 가 이력 상세에서 조립한다. 부분 취소는 절대 숨기지 않는다: 몇 개가
     * 취소되고 몇 개가 취소되지 못했는지 둘 다 싣는다.
     *
     * 더는 'scheduled'가 아닌 작업(이미 나갔거나, 이미 취소됐거나)을 취소하려 하면
     * AligoService::cancel() 이 422 로 거절한다 — 그 사실도 숫자 대신 깃발
     * (cancel_blocked)로만 넘긴다.
     */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        $jobId = (int) $args['id'];

        try {
            $result = $this->app->aligo()->cancel($jobId);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->redirect($request, $response, 'admin.messages.history.detail', [
                'cancel' => (string) $jobId,
                'cancel_ok' => '0',
                'cancel_failed' => '0',
                'cancel_blocked' => '1',
            ], ['id' => (string) $jobId]);
        }

        return $this->redirect($request, $response, 'admin.messages.history.detail', [
            'cancel' => (string) $jobId,
            'cancel_ok' => (string) $result['cancelled'],
            'cancel_failed' => (string) $result['failed'],
        ], ['id' => (string) $jobId]);
    }

    /**
     * 목록 표의 "템플릿" 칸에 쓸 이름. 알림톡만 템플릿을 쓰므로 문자 작업은 null 로 둬
     * 뷰가 "-"를 보여주게 한다. 사본이 지워지거나 못 찾으면 코드라도 그대로 보여준다.
     */
    private function withTemplateLabel(array $job): array
    {
        $tplCode = (string) ($job['tpl_code'] ?? '');
        if ($job['channel'] !== 'at' || $tplCode === '') {
            $job['template_label'] = null;

            return $job;
        }
        $template = $this->app->aligo()->templates->find($tplCode);
        $job['template_label'] = $template !== null ? (string) $template['name'] : $tplCode;

        return $job;
    }

    /** 화면 입력을 Dispatch 가 받는 모양으로 바꾼다. 미리보기와 발송이 같은 것을 쓴다. */
    private function collect(array $input): array
    {
        $vars = [];
        foreach ($input as $name => $value) {
            if (str_starts_with((string) $name, 'var_') && is_scalar($value)) {
                $vars[substr((string) $name, 4)] = (string) $value;
            }
        }

        $isAlimtalk = ($input['channel'] ?? 'sms') === 'at';
        $siteDefaults = $isAlimtalk ? $this->app->alimtalkMessageInfo()->resolved() : [
            '사이트명' => (string) $this->app->cmsService()->settings()['site_name'],
            '사이트주소' => rtrim((string) $this->app->config('app.url', GNUCMS_URL), '/'),
            '문의처' => $this->app->cmsService()->notificationContact((string) $this->app->config('app.url', GNUCMS_URL)),
        ];
        foreach ($this->variableNames($input) as $name) {
            if (isset($siteDefaults[$name]) && ($isAlimtalk || trim($vars[$name] ?? '') === '')) {
                $vars[$name] = $siteDefaults[$name];
            }
        }
        $recipients = [];
        $skipped = 0;
        $ineligible = 0;
        $missing = 0;
        foreach ((array) ($input['members'] ?? []) as $userId) {
            $row = $this->app->db()->selectOne('SELECT id, display_name, phone, status FROM '
                . $this->app->db()->table('users') . ' WHERE id = ?', [(string) $userId]);
            if ($row === null) {
                // 그 회원이 아예 없다. "번호가 없어 제외"와는 다른 사실이므로 같은
                // 집계에 섞지 않는다 — 화면을 열어 둔 사이에 지워졌거나, 목록에 없던
                // 회원 ID 가 그대로 들어온 경우다.
                $missing++;
                continue;
            }
            // 탈퇴·차단 회원은 번호가 남아 있어도(차단은 번호를 지우지 않는다) 고를 수
            // 없다 — CommentService 가 명시하는 원칙과 같다: "차단된 회원은 없는 회원과
            // 같게 다룬다", 이 화면 밖의 모든 회원용 게이트도 status === 'active' 만 통과
            // 시킨다. "번호 없음"과 다른 사유이므로 같은 집계에 섞지 않는다 — 관리자가
            // 어느 쪽인지 구분해서 볼 수 있어야 한다.
            if ((string) $row['status'] !== 'active') {
                $ineligible++;
                continue;
            }
            if (($row['phone'] ?? '') === '') {
                $skipped++;
                continue;
            }
            $recipients[] = ['phone' => (string) $row['phone'], 'name' => (string) $row['display_name'],
                'user_id' => (string) $row['id'], 'vars' => $vars];
        }
        foreach (preg_split('/[\r\n,]+/', (string) ($input['numbers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $recipients[] = ['phone' => $line, 'vars' => $vars];
            }
        }

        return [
            'request' => [
                'channel' => (string) ($input['channel'] ?? 'sms'),
                'body' => (string) ($input['body'] ?? ''),
                'title' => (string) ($input['title'] ?? ''),
                'tpl_code' => (string) ($input['tpl_code'] ?? ''),
                'failover' => ($input['failover'] ?? '') === '1',
                'created_by' => $this->app->guestAcl()->identity()->displayName() ?? '',
                'secret_vars' => Events::secretVars(is_string($input['template_event'] ?? null) ? $input['template_event'] : ''),
                'recipients' => $recipients,
                'scheduled_at' => $this->scheduledAtForRequest((string) ($input['scheduled_at'] ?? '')),
            ],
            // 수신자가 없어도 본문을 미리 볼 값. 실제 발송 request에는 넣지 않는다.
            'preview_vars' => $vars,
            'skipped' => $skipped,
            'ineligible' => $ineligible,
            'missing' => $missing,
        ];
    }

    /**
     * 발송 시각 입력을 SendTime 에 그대로 맡긴다. 이 화면의 datetime-local 입력값은
     * 오프셋을 낼 수 없으므로 SendTime::parse() 가 이 값을 언제나 한국 표준시(KST)
     * 벽시계로 읽는다 — 이 화면의 다른 모든 시각(요청 시각 등)과 같은 기준이다.
     * KST→UTC 변환·형식 검증·하한(10분)·상한(30일) 검증은 모두 SendTime::parse()
     * 하나가 맡는다(그 클래스 문서 주석 참고: 시간대는 그 클래스 밖에서 다루지
     * 않는다). 형식이 잘못됐거나 범위를 벗어나면 SendTime::parse() 가 DomainError 를
     * 던진다 — 여기서 잡지 않고 그대로 올려보낸다(collect() 호출부가 잡는다).
     *
     * 여기서 UTC로 바꾼 값은 buildPreview() 의 표시용으로도 쓰이지만, dispatch() 는
     * 이 값을 담은 request 배열을 그대로 $app->aligo()->send() 에 넘기고, 그 안의
     * Dispatch::send() 가 검증을 위해 SendTime::parse() 를 한 번 더 부른다(확장이
     * 부르는 것과 같은 문을 관리자 화면도 그대로 쓰기 때문이다). 오프셋을 붙이지
     * 않고 그대로 돌려주면, 이미 UTC로 바꿔 둔 값이 오프셋 없는 문자열로 보여 그
     * 두 번째 parse() 에서 다시 KST로 읽혀 9시간이 또 빠진다. SendTime::withUtcOffset()
     * 이 이 값은 이미 절대 시각(UTC)이라는 표식을 붙여 주므로 두 번째 parse() 는 있는
     * 그대로 존중할 뿐 다시 변환하지 않는다 — 그 표식의 문자열 형식을 아는 것도
     * SendTime 하나뿐이다(시간대는 그 클래스 밖에서 다루지 않는다는 규칙 그대로다).
     */
    private function scheduledAtForRequest(string $raw): ?string
    {
        $utc = SendTime::parse($raw);

        return $utc === null ? null : SendTime::withUtcOffset($utc);
    }

    /**
     * 템플릿 탭 안내. 가져오기 결과를 숫자로만 받아 문장은 여기서 만든다 — 문장 자체를
     * 쿼리로 받으면 공격자가 만든 URL 이 시스템 알림처럼 보이게 된다.
     *
     * 문장과 함께 'ok'(성공인가)를 돌려준다. 화면은 이 값으로 초록 체크와 노랑 주의를
     * 가른다 — "취소하지 못해 예정대로 나갑니다" 위에 초록 체크가 붙으면 관리자는
     * 문장을 끝까지 읽기 전에 다 끝난 줄 안다. cancelNotice() 와 같은 모양이다.
     *
     * @return array{ok:bool,message:string}|null
     */
    private function templatesNotice(array $query): ?array
    {
        if (!isset($query['imported'])) {
            return null;
        }

        $message = sprintf('가져오기 %d건, 갱신 %d건, 발송 불가 %d건',
            self::countParam($query, 'imported'),
            self::countParam($query, 'updated'),
            self::countParam($query, 'disabled'))
            . $this->templateCancellationSentence($query);

        return ['ok' => self::countParam($query, 'cancel_failed') === 0, 'message' => $message];
    }

    /**
     * 승인·정상 상태를 잃어 자동으로 꺼진 템플릿에 걸려 있던 예약의 취소 결과.
     * fetchTemplates() 가 cancel_ok·cancel_failed 로 실어 넘긴 숫자만으로 여기서
     * 문장을 짓는다(클래스 주석의 원칙 그대로 — 문장 자체를 쿼리로 받지 않는다).
     * AdminAligoController::savedNotice() 와 같은 규칙을 이 탭의 말투로 옮긴 것이다 —
     * 채널을 끌 때와 마찬가지로 템플릿이 승인을 잃을 때도 예약이 함께 취소되므로,
     * 두 화면이 같은 사실을 서로 다르게(한쪽은 숫자로, 한쪽은 침묵으로) 말하면 안
     * 된다. 부분 취소("일부만 취소")는 절대 "취소했습니다"로 뭉개지 않는다 — 취소
     * 요청은 갔지만 그중 일부가 여전히 예약된 채로 남아 있다는 사실이 이 기능 전체의
     * 존재 이유다. 다만 왜 남았는지는 말하지 않고, 사유가 적혀 있을 곳을 가리키기만
     * 한다(AdminAligoController::savedNotice() 와 같은 이유·같은 말투 — 우리는 그 이유를
     * 모르고, 가드절에 막힌 작업이라면 행에 적힌 사유도 재시도할 버튼도 없다).
     */
    private function templateCancellationSentence(array $query): string
    {
        $ok = self::countParam($query, 'cancel_ok');
        $failed = self::countParam($query, 'cancel_failed');
        if ($ok === 0 && $failed === 0) {
            return '.';
        }
        if ($failed === 0) {
            return sprintf(' 승인을 잃어 예약돼 있던 발송 %d개를 함께 취소했습니다.', $ok);
        }
        if ($ok === 0) {
            return sprintf(
                ' 승인을 잃은 템플릿에 걸린 예약 %d개는 취소하지 못했습니다.'
                . ' 이력 화면에서 그 작업의 상태와 사유를 확인해 주세요.', $failed
            );
        }

        return sprintf(
            ' 승인을 잃은 템플릿에 걸린 예약 %d개 중 %d개를 취소했고, %d개는 취소하지 못했습니다.'
            . ' 이력 화면에서 그 작업의 상태와 사유를 확인해 주세요.',
            $ok + $failed, $ok, $failed
        );
    }

    /**
     * 발송 직후 이력 상세에 보여줄 안내 문장. sent 가 지금 보고 있는 작업과 같을 때만
     * 보여준다 — 아무 작업 상세에나 붙여 성공 알림을 띄울 수 있으면 안 된다.
     */
    private function dispatchNotice(array $query, int $jobId): ?string
    {
        if ($jobId <= 0 || self::countParam($query, 'sent') !== $jobId) {
            return null;
        }
        $sentence = (($query['duplicate'] ?? '') === '1')
            ? sprintf('같은 내용을 방금 보냈기 때문에 다시 보내지 않았습니다. 그때 만들어진 작업 #%d 입니다.', $jobId)
            : sprintf('발송을 시작했습니다. 작업 번호 #%d.', $jobId);

        $parts = [];
        foreach ([
            'to' => '받는 사람 %d명',
            'nophone' => '번호가 없어 제외 %d명',
            'blocked' => '탈퇴·차단으로 제외 %d명',
            'missing' => '회원을 찾지 못해 제외 %d명',
        ] as $name => $format) {
            $count = self::countParam($query, $name);
            if ($count > 0) {
                $parts[] = sprintf($format, $count);
            }
        }

        return $parts === [] ? $sentence : $sentence . ' ' . implode(' · ', $parts) . '.';
    }

    /**
     * 이력 상세에 보여줄 취소 결과 문장. cancel() 이 넘긴 숫자·깃발만으로 여기서
     * 조립한다 — 클래스 주석의 원칙 그대로, 문장 자체를 쿼리로 받지 않는다.
     *
     * 부분 취소("2개 취소, 1개는 …")는 절대 "취소했습니다"로 뭉개지 않는다 — 아직 나갈
     * 발송이 남아 있다는 사실이 이 기능 전체의 존재 이유다. 반환값의 'ok' 는 화면이
     * 성공(초록)과 주의(노랑) 배지를 가르는 데만 쓴다 — 문장 자체는 이미 정확하다.
     *
     * 왜 취소하지 못했는지는 단정하지 않는다(Dispatch::cancel() 참고 — 시한 초과일
     * 수도, 통신 실패나 키·IP 문제일 수도 있고 알림톡은 가려낼 코드조차 없다). 대신
     * 실제 사유가 적혀 있는 곳을 가리킨다: 이 화면 아래 수신자 표의 "사유" 칸이다.
     * 그 자리에 있는 취소 버튼으로 다시 시도할 수도 있다 — 지어낸 "시한이 지났습니다"
     * 는 그 시도를 하지 말라는 지시가 되어, 다시 눌렀으면 멈출 수 있었을 발송을 내보낸다.
     *
     * @return array{ok:bool,message:string}|null
     */
    private function cancelNotice(array $query, int $jobId): ?array
    {
        if ($jobId <= 0 || self::countParam($query, 'cancel') !== $jobId) {
            return null;
        }
        if (($query['cancel_blocked'] ?? '') === '1') {
            return ['ok' => false, 'message' => '이미 처리되었거나 예약 상태가 아니어서 취소할 수 없습니다.'];
        }
        $ok = self::countParam($query, 'cancel_ok');
        $failed = self::countParam($query, 'cancel_failed');
        if ($ok > 0 && $failed === 0) {
            return ['ok' => true, 'message' => '예약을 취소했습니다.'];
        }
        if ($ok === 0 && $failed === 0) {
            return ['ok' => false, 'message' => '취소할 예약이 없습니다.'];
        }
        if ($ok === 0) {
            return ['ok' => false, 'message' => sprintf(
                '취소하지 못했습니다. %d개 묶음 모두 그대로 남아 예정대로 발송됩니다.'
                . ' 사유는 아래 수신자 표의 "사유" 칸에 있습니다 — 다시 취소할 수 있습니다.', $failed
            )];
        }

        return ['ok' => false, 'message' => sprintf(
            '%d개 묶음 중 %d개 취소, %d개는 취소하지 못했습니다. 남은 발송은 예정대로 나갑니다.'
            . ' 사유는 아래 수신자 표의 "사유" 칸에 있습니다 — 다시 취소할 수 있습니다.',
            $ok + $failed, $ok, $failed
        )];
    }

    /** 쿼리에서 0 이상의 정수만 읽는다. 숫자가 아니면 0 으로 본다. */
    private static function countParam(array $query, string $name): int
    {
        $value = $query[$name] ?? null;

        return is_scalar($value) && ctype_digit(trim((string) $value)) ? (int) $value : 0;
    }

    /**
     * 입력된 값은 보존하고 빈 변수만 표시용 예시로 채운다. 수신자가 없어도 확인한다.
     * 미리보기만으로 예시를 폼이나 설정에 저장하지 않는다. 예시 발송을 명시한 경우에만
     * 같은 예시 생성 함수를 실제 발송에 적용한다. 일반 발송의 빈 변수 거절은 유지한다.
     */
    private function buildPreview(array $collected): array
    {
        $request = $collected['request'];
        $scheduledAt = $request['scheduled_at'] ?? null;
        $recipients = $request['recipients'];
        $isAlimtalk = $request['channel'] === 'at';
        $body = $isAlimtalk ? $this->templateBody($request['tpl_code']) : $request['body'];
        if (trim($body) === '') {
            throw DomainError::validation([($isAlimtalk ? 'tpl_code' : 'body') =>
                $isAlimtalk ? '미리 볼 템플릿을 선택해 주세요.' : '미리 볼 본문을 입력해 주세요.']);
        }

        $values = $recipients === [] ? $collected['preview_vars'] : (array) $recipients[0]['vars'];
        $missing = Variables::missing($body, $values);
        $sample = Variables::apply($body, $this->exampleValues($body, $values));

        return [
            'sample' => $sample,
            'example_mode' => $missing !== [] || $recipients === [],
            'example_variables' => $missing,
            'can_send' => $missing === [] && $recipients !== [],
            'count' => count($recipients),
            'skipped' => $collected['skipped'],
            'ineligible' => $collected['ineligible'],
            'missing' => $collected['missing'],
            'bytes' => MessageText::byteLength($sample),
            'classify' => MessageText::channelFor($sample),
            'scheduled_at' => $scheduledAt,
        ];
    }

    /** 미리보기와 예시 발송이 같은 값으로 빈 변수만 채운다. 입력값은 덮어쓰지 않는다. */
    private function exampleValues(string $body, array $values): array
    {
        $examples = SmsEditor::samples();
        foreach (Variables::missing($body, $values) as $name) {
            $values[$name] = $examples[$name] ?? '[' . $name . ' 예시]';
        }
        return $values;
    }

    /** 관리자 직접 발송의 명시적인 예시 선택에만 적용하며, Dispatch의 검증은 그대로 거친다. */
    private function withExampleValues(array $request): array
    {
        $body = $request['channel'] === 'at' ? $this->templateBody($request['tpl_code']) : $request['body'];
        foreach ($request['recipients'] as &$recipient) {
            $recipient['vars'] = $this->exampleValues($body, (array) $recipient['vars']);
        }
        unset($recipient);
        return $request;
    }

    /** 알림톡 템플릿 사본의 본문. 코드가 비어 있거나 가져온 적 없으면 빈 문자열이다. */
    private function templateBody(string $tplCode): string
    {
        if ($tplCode === '') {
            return '';
        }
        $template = $this->app->aligo()->templates->find($tplCode);

        return $template !== null ? (string) $template['content'] : '';
    }

    /** 변수 칸(var_이름)을 만들 이름 목록. 알림톡은 고른 템플릿에서, 문자는 입력한 본문에서 뽑는다. */
    private function variableNames(array $input): array
    {
        $channel = (string) ($input['channel'] ?? 'sms');
        $body = $channel === 'at'
            ? $this->templateBody((string) ($input['tpl_code'] ?? ''))
            : (string) ($input['body'] ?? '');

        return Variables::names($body);
    }

    /**
     * 입력에 담긴 회원 ID 각각의 이름·번호. 검색어를 다시 치지 않아도 "선택됨" 표시를
     * 유지한다. 검색 결과와 같은 이유로 활성 회원만 보여준다 — 체크한 뒤 검증 오류로
     * 다시 그릴 때까지 그 사이에 차단되거나 탈퇴했을 수도 있고, 그런 회원은 이 목록에도
     * 더는 남지 않아야 한다. 걸러진 회원은 사라지는 대신 미리보기의 "보낼 수 없는 회원"
     * 집계에 잡힌다.
     */
    private function selectedMembers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->app->db()->select('SELECT id, display_name, phone FROM '
            . $this->app->db()->table('users')
            . ' WHERE id IN (' . $placeholders . ') AND status = ? ORDER BY display_name',
            [...$ids, 'active']);

        return array_map([$this, 'withPhoneDisplay'], $rows);
    }

    /**
     * 이름·이메일·휴대폰번호로 회원을 찾는다. 관리자 회원 목록과 같은 검색 조건을
     * UserRepository::searchActive() 에서 함께 쓴다 — 번호를 들고 있는 운영자가 그
     * 번호로 바로 고를 수 있어야 하고(스펙 §7), 번호 일치 규칙을 두 곳에 베껴 두면
     * 한쪽만 고쳐지기 때문이다. 대신 "누구를 보여 주는가"는 공유하지 않는다:
     * searchActive() 는 활성 회원만 돌려주고, 회원 관리 목록(listForAdmin)은 차단·탈퇴
     * 회원까지 일부러 보여 준다. 발송 화면이 활성 회원만 보는 것은 CommentService 의
     * 원칙("차단된 회원은 없는 회원과 같게 다룬다")을 따르는 것이다.
     *
     * 이미 선택된 회원은 "선택된 회원" 목록에 있으므로 여기 또 보여주지 않는다.
     */
    private function searchMembers(string $q, array $excludeIds): array
    {
        $rows = array_values(array_filter(
            $this->app->users()->searchActive($q),
            static fn (array $row): bool => !in_array((string) $row['id'], $excludeIds, true)
        ));

        return array_map([$this, 'withPhoneDisplay'], $rows);
    }

    /** 화면에 보여줄 번호 표시값을 붙인다. 번호가 없으면 null 로 둬 뷰가 "번호 없음"을 보여주게 한다. */
    private function withPhoneDisplay(array $row): array
    {
        $phone = (string) ($row['phone'] ?? '');
        $row['phone_display'] = $phone === '' ? null : PhoneNumber::format($phone);

        return $row;
    }

    /**
     * 발송 탭에는 성공 알림이 없다. 이 화면으로 리다이렉트하며 안내를 남기는 코드가
     * 코드베이스에 하나도 없었으므로, 그 자리를 남겨 두면 공격자가 만든 URL 만이
     * 그것을 채울 수 있다.
     */
    private function renderSend(ServerRequestInterface $request, ResponseInterface $response, array $input,
        ?string $error, array $fieldErrors, ?array $preview): ResponseInterface
    {
        $isAlimtalk = ($input['channel'] ?? 'sms') === 'at';
        $siteDefaults = $isAlimtalk ? $this->app->alimtalkMessageInfo()->resolved() : [
            '사이트명' => (string) $this->app->cmsService()->settings()['site_name'],
            '사이트주소' => rtrim((string) $this->app->config('app.url', GNUCMS_URL), '/'),
            '문의처' => $this->app->cmsService()->notificationContact((string) $this->app->config('app.url', GNUCMS_URL)),
        ];
        foreach ($siteDefaults as $name => $value) {
            $field = 'var_' . $name;
            if ($isAlimtalk || !isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
                $input[$field] = $value;
            }
        }
        $selectedIds = array_map('strval', (array) ($input['members'] ?? []));
        $query = $request->getQueryParams();
        $searchQuery = is_string($query['q'] ?? null) ? trim($query['q']) : '';

        return View::fromRequest($request)->render($response, 'admin/message/send', [
            'values' => $input,
            'templates' => $this->app->aligo()->templates->usable(),
            'status' => $this->app->aligo()->status(),
            'search_query' => $searchQuery,
            'search_results' => $this->searchMembers($searchQuery, $selectedIds),
            'selected_members' => $this->selectedMembers($selectedIds),
            'variable_names' => $this->variableNames($input),
            'template_content' => $isAlimtalk ? $this->templateBody((string) ($input['tpl_code'] ?? '')) : '',
            'preview' => $preview,
            'error' => $error,
            'field_errors' => $fieldErrors,
        ]);
    }

    /** 필드 오류 배열에서 화면 상단 알림에 쓸 문장 하나를 고른다. */
    private function firstError(DomainError $e): string
    {
        $details = $e->details();

        return $details === [] ? $e->getMessage() : (string) reset($details);
    }

    /** @param array{ok:bool,message:string}|null $notice */
    private function render(ServerRequestInterface $request, ResponseInterface $response,
        ?string $error, ?array $notice = null): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'admin/message/templates', [
            'copies' => $this->app->aligo()->templates->all(),
            'usable_codes' => array_column($this->app->aligo()->templates->usable(), 'tpl_code'),
            'error' => $error,
            'notice' => $notice,
        ]);
    }

    private function input(ServerRequestInterface $request): array
    {
        $input = $request->getParsedBody();

        return is_array($input) ? $input : [];
    }

    private function assertCsrf(array $input): void
    {
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        $given = isset($input['csrf_token']) && is_scalar($input['csrf_token']) ? (string) $input['csrf_token'] : '';
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
    }

    private function redirect(ServerRequestInterface $request, ResponseInterface $response, string $route,
        array $query = [], array $routeParams = []): ResponseInterface
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($route, $routeParams);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $response->withHeader('Location', $url)->withStatus(303);
    }
}
