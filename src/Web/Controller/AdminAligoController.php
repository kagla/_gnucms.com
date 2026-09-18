<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Aligo\PhoneNumber;
use GnuCms\Aligo\TransportFailure;
use GnuCms\Aligo\Variables;
use GnuCms\Error\DomainError;
use GnuCms\Notify\Events;
use GnuCms\Notify\NotifySettings;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * 알리고 설정 화면. 계정(사용자ID·API 키)·발신번호·발신프로필을 저장하고, 채널별
 * 발송 허용 여부를 켜고 끈다. API 키는 저장된 뒤로는 화면·로그·오류 어디에도
 * 다시 나타나지 않는다 — 저장 여부만 보여 준다(AdminCmsController::mail() 과 같은 규칙).
 */
final class AdminAligoController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function form(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $query = $request->getQueryParams();
        $notice = ($query['saved'] ?? '') === '1' ? $this->savedNotice($query) : null;

        return $this->render($request, $response, null, [], null, null, [], $notice);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $this->app->aligo()->settings->save($input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->render(
                $request, $response->withStatus(422), $this->reshow($input), $e->details(), null, null, []
            );
        }

        return $this->redirect($request, $response, 'admin.aligo', ['saved' => '1']);
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $verified = $this->app->aligo()->verify();
        } catch (DomainError $e) {
            return $this->render($request, $response->withStatus($e->status() === 422 ? 422 : 502),
                null, $e->details(), $e->getMessage(), null, []);
        }

        return $this->render($request, $response, null, [], null, $verified, []);
    }

    public function profiles(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $profiles = $this->app->aligo()->profiles();
        } catch (DomainError | TransportFailure $e) {
            return $this->render($request, $response->withStatus(502), null, [], $e->getMessage(), null, []);
        }

        return $this->render($request, $response, null, [], null, null, $profiles);
    }

    /**
     * 채널을 끄면 이미 걸린 예약도 함께 취소된다(AligoService::setChannelEnabled()) —
     * 부분 취소는 흔하므로 그 결과를 저장 안내에 숫자로 싣는다. 문장은 savedNotice()
     * 가 조립한다.
     */
    public function toggle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $result = $this->app->aligo()->setChannelEnabled(
                (string) ($input['channel'] ?? ''), ($input['action'] ?? '') === 'enable'
            );
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->render($request, $response->withStatus(422), null, $e->details(), null, null, []);
        }

        $query = ['saved' => '1'];
        if ($result['cancelled'] > 0 || $result['failed'] > 0) {
            $query['cancel_ok'] = (string) $result['cancelled'];
            $query['cancel_failed'] = (string) $result['failed'];
        }

        return $this->redirect($request, $response, 'admin.aligo', $query);
    }

    /**
     * 알림 설정 화면. 이벤트 일곱 개마다 어느 채널로 보낼지를 고른다.
     *
     * **묶음마다 따로 저장한다.** NotifySettings::save() 가 이벤트 하나씩만 받기 때문만은
     * 아니다. 한 폼으로 일곱 개를 한꺼번에 저장하면 네 번째 이벤트의 검증이 실패했을 때
     * 앞의 셋은 이미 저장되고 뒤의 셋은 저장되지 않은 채로 422 를 돌려주게 된다 —
     * 트랜잭션이 없는 저장소에서 "반쯤 저장됨"은 관리자가 화면만 보고는 알아낼 수 없는
     * 상태다. 묶음마다 버튼을 따로 두면 실패한 묶음만 그대로 남고 나머지는 손대지 않는다.
     */
    public function notifications(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();

        return $this->renderNotifications($request, $response, null, [],
            $this->notifySavedNotice($request->getQueryParams()));
    }

    /**
     * 이벤트 하나를 저장한다. 422 면 관리자가 방금 고른 값을 그대로 되돌려 보여준다 —
     * 저장된 값으로 다시 그리면 방금 쓴 문자 본문과 고른 템플릿이 통째로 사라진다.
     */
    public function saveNotifications(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        $event = is_scalar($input['event'] ?? null) ? (string) $input['event'] : '';
        try {
            $this->app->notifySettings()->save($event, $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->renderNotifications(
                $request, $response->withStatus(422), $input, $e->details()
            );
        }

        // 쿼리에는 이벤트 키(카탈로그가 정한 열거값)만 싣는다. 문장은 아래에서 만든다 —
        // 화면에 그대로 찍을 문장을 쿼리로 받으면 공격자가 만든 URL 을 관리자가 열었을 때
        // 우리가 그 문장을 시스템 알림처럼 보여주게 된다(AdminMessageController 클래스 주석).
        return $this->redirect($request, $response, 'admin.settings.notifications', ['saved' => $event]);
    }

    /** 저장 안내. 쿼리에 실려 온 이벤트 키로 라벨을 찾아 문장을 여기서 만든다. */
    private function notifySavedNotice(array $query): ?string
    {
        $event = is_scalar($query['saved'] ?? null) ? (string) $query['saved'] : '';
        if (!Events::exists($event)) {
            return null;
        }

        return sprintf('「%s」 알림 설정을 저장했습니다.', Events::labels()[$event]);
    }

    /**
     * 화면이 쓸 값. 이벤트마다 "지금 실제로 어떻게 되어 있는가"와 "관리자가 무엇을
     * 저장해 두었는가"를 나란히 놓고, 둘이 어긋나는 자리마다 그 이유를 문장으로 만든다.
     * 그 어긋남을 말하지 않고 꺼진 칸만 보여주면, 관리자는 자기가 켠 채널이 이유 없이
     * 스스로 꺼진 것을 보게 된다 — 이 분기가 되풀이해 고쳐 온 결함과 같은 모양이다.
     *
     * $posted 가 있으면 그 이벤트 한 묶음만 방금 들어온 입력으로 덮는다(422 되보여주기).
     */
    private function notifyRows(?array $posted): array
    {
        $values = $this->app->notifySettings()->formValues();
        // 화면은 템플릿마다 "이 템플릿의 변수 목록"을 함께 필요로 한다 — 그 변수 하나하나에
        // 코어 변수를 이어 줘야 알림톡을 켤 수 있기 때문이다(NotifySettings::save()).
        // 본문을 뷰로 내려보내 거기서 파싱하게 하지 않는다: 판단은 컨트롤러가 한다.
        $usable = array_map(static fn (array $t): array => [
            'tpl_code' => (string) $t['tpl_code'],
            'name' => (string) $t['name'],
            'vars' => Variables::names((string) $t['content']),
        ], $this->app->aligo()->templates->usable());
        $postedEvent = is_array($posted) && is_scalar($posted['event'] ?? null)
            ? (string) $posted['event'] : '';
        $rows = [];
        foreach ($values as $key => $value) {
            $on = [];
            foreach (NotifySettings::CHANNELS as $channel) {
                $on[$channel] = in_array($channel, $value['channels'], true);
            }
            // 알림톡만은 저장 원본을 쓴다. channels 는 템플릿이 죽으면 알림톡을 빼는데,
            // 그 상태에서 체크를 꺼진 것으로 그려 두면 관리자가 다른 칸만 고쳐 저장했을 때
            // "켜 두었다"는 사실 자체가 조용히 지워진다. 체크는 관리자의 선택을 그대로
            // 두고, 지금 나가지 않는다는 사실은 아래 alimtalk_notice 가 말한다.
            $on['alimtalk'] = $on['alimtalk'] || ($value['alimtalk_on'] && $value['phone']);
            $row = [
                'key' => $key,
                'label' => $value['label'],
                'vars' => $value['vars'],
                'phone' => $value['phone'],
                'inbox_capable' => $value['inbox'],
                'on' => $on,
                'tpl_code' => $value['alimtalk_tpl_code'],
                // 꺼져 있어도 저장된 값을 그대로 보여준다 — 다시 켤 때 다시 만들지
                // 않아도 되는 것이 save() 가 이 값을 지우지 않는 이유다.
                'var_map' => $value['alimtalk_var_map'],
                'sms_body' => $value['sms_body_stored'],
                'alimtalk_notice' => self::alimtalkNotice($value),
                'sms_notice' => (!$on['sms'] && $value['sms_body_stored'] !== '')
                    ? '문자 채널이 꺼져 있어 이 본문은 지금 쓰이지 않습니다. 저장된 값은 그대로 남아'
                        . ' 있으니, 문자를 다시 켜고 저장하면 이 본문으로 나갑니다.'
                    : null,
            ];
            if ($postedEvent === $key) {
                $row = self::withPostedInput($row, $posted ?? []);
            }
            $rows[$key] = $row;
        }

        return ['rows' => $rows, 'templates' => $usable, 'open' => $postedEvent];
    }

    /**
     * 저장된 알림톡 설정이 지금은 쓸 수 없게 되었을 때 그 이유를 말하는 한 문장.
     * 관리자가 켜 두지 않았으면 아무 말도 하지 않는다 — 켜 두지 않은 이벤트에서
     * template 이 null 인 것은 templateFor() 의 isOn() 게이트 때문이지 템플릿이 죽어서가
     * 아니라서, 그때 "이 템플릿을 쓸 수 없습니다"라고 적으면 멀쩡한 템플릿을 두고
     * 거짓말을 하게 된다.
     */
    private static function alimtalkNotice(array $value): ?string
    {
        if (!$value['phone'] || !$value['alimtalk_on'] || $value['template'] !== null) {
            return null;
        }
        if ($value['alimtalk_tpl_code'] === '') {
            return '알림톡을 켜 두었지만 고른 템플릿이 없어 지금은 나가지 않습니다. 아래에서 템플릿을 고르고 저장해 주세요.';
        }

        return sprintf(
            '알림톡을 켜 두었지만 지금은 나가지 않습니다. 고르신 템플릿(%s)을 더는 쓸 수 없습니다 —'
            . ' 카카오 승인이 풀렸거나, 템플릿 목록에서 사라졌거나, 본문이 바뀌어 변수 연결이 어긋났습니다.'
            . ' 운영 → 알림톡·문자 → 템플릿에서 다시 가져오거나, 아래에서 다른 템플릿을 골라 주세요.',
            $value['alimtalk_tpl_code']
        );
    }

    /**
     * 422 로 되돌아온 묶음을 관리자가 방금 화면에서 고른 값으로 덮는다. 저장된 값이
     * 아니므로 "지금 쓸 수 있는가"를 말하는 안내문은 지운다 — 저장되지 않은 입력을 두고
     * 저장된 설정에 대한 문장을 붙여 두면 두 문장이 서로 다른 것을 가리키게 된다.
     */
    private static function withPostedInput(array $row, array $posted): array
    {
        foreach (NotifySettings::CHANNELS as $channel) {
            $row['on'][$channel] = ($posted[$channel] ?? '') === '1';
        }
        $row['tpl_code'] = is_scalar($posted['tpl_code'] ?? null) ? trim((string) $posted['tpl_code']) : '';
        $map = [];
        foreach (is_array($posted['var_map'] ?? null) ? $posted['var_map'] : [] as $name => $core) {
            if (is_scalar($core)) {
                $map[(string) $name] = trim((string) $core);
            }
        }
        $row['var_map'] = $map;
        $row['sms_body'] = is_scalar($posted['sms_body'] ?? null) ? (string) $posted['sms_body'] : '';
        $row['alimtalk_notice'] = null;
        $row['sms_notice'] = null;

        return $row;
    }

    private function renderNotifications(ServerRequestInterface $request, ResponseInterface $response,
        ?array $posted, array $errors, ?string $notice = null): ResponseInterface
    {
        $view = $this->notifyRows($posted);

        return View::fromRequest($request)->render($response, 'admin/notify_settings', [
            'events' => $view['rows'],
            'templates' => $view['templates'],
            'open' => $view['open'],
            'errors' => $errors,
            'notice' => $notice,
            'status' => $this->app->aligo()->status(),
        ]);
    }

    /**
     * 저장 안내. 채널을 끌 때 함께 취소된(또는 취소하지 못한) 예약이 있으면 그 숫자를
     * 문장에 더한다 — cancel_ok·cancel_failed 는 toggle() 이 숫자로만 실어 넘긴
     * 값이다(클래스 주석의 원칙: 문장은 쿼리로 받지 않고 여기서 만든다).
     *
     * 문장만이 아니라 'ok'(성공인가)도 함께 돌려준다. 화면이 이 값으로 초록 체크와
     * 노랑 주의를 가른다 — 취소하지 못한 예약이 남았다는 문장 위에 초록 체크가 붙으면,
     * 문장을 끝까지 읽지 않은 관리자는 다 끝난 줄 안다. 이 기능이 낼 수 있는 가장
     * 잘못된 신호다. AdminMessageController::cancelNotice() 와 같은 모양을 쓴다.
     *
     * 취소하지 못한 이유는 말하지 않는다. 예전에는 "발송 5분 전을 지나"라고 단정했지만
     * 그건 알 수 없는 사실이다 — 알리고에 닿지 못했거나 키가 취소됐거나 IP 가 등록돼
     * 있지 않아도 똑같이 실패하고, 알림톡은 시한 초과인지 가려낼 코드조차 없다
     * (Dispatch::cancel() 참고). 틀린 사유는 침묵보다 나쁘다: 시한이 지났다는 말은
     * 다시 시도하지 말라는 지시인데, 다시 시도하면 취소됐을 건에도 그렇게 말하게 된다.
     * 그래서 결과(몇 개가 남았는지)만 말하고 어디를 볼지 가리킨다.
     *
     * 가리키되 약속하지는 않는다. 이 일괄 취소는 작업 하나가 가드절에 막혀(그 사이 이미
     * 나갔거나 이미 취소돼) 실패로 세어질 수도 있는데(AligoService::cancelJobs()), 그
     * 경우에는 수신자 행에 적힌 사유가 아예 없고 상세 화면의 취소 버튼도 422 로 거절한다.
     * "사유가 적혀 있고 거기서 다시 취소할 수 있습니다"라고 쓰면 그 경로에서 두 약속이
     * 모두 거짓이 된다 — 그래서 "확인해 주세요"까지만 말한다.
     */
    private function savedNotice(array $query): array
    {
        $ok = self::countParam($query, 'cancel_ok');
        $failed = self::countParam($query, 'cancel_failed');
        if ($ok === 0 && $failed === 0) {
            return ['ok' => true, 'message' => '설정을 저장했습니다.'];
        }
        if ($failed === 0) {
            return ['ok' => true, 'message' => sprintf(
                '설정을 저장했습니다. 예약된 발송 %d개를 함께 취소했습니다.', $ok
            )];
        }
        if ($ok === 0) {
            return ['ok' => false, 'message' => sprintf(
                '설정을 저장했습니다. 예약된 발송 %d개는 취소하지 못했습니다.'
                . ' 이력 화면에서 그 작업의 상태와 사유를 확인해 주세요.', $failed
            )];
        }

        return ['ok' => false, 'message' => sprintf(
            '설정을 저장했습니다. 예약된 발송 %d개 중 %d개를 취소했고, %d개는 취소하지 못했습니다.'
            . ' 이력 화면에서 그 작업의 상태와 사유를 확인해 주세요.',
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
     * 저장이 실패했을 때 다시 보여줄 값. 관리자가 방금 입력한 값을 그대로 돌려주되
     * API 키 두 칸만은 절대 담지 않는다 — 입력이 잘못됐다고 해서 화면이 방금 받은
     * 평문 키를 그대로 되비추면 안 된다. "저장됨" 표시는 실패 전 저장소 상태를 쓴다.
     */
    private function reshow(array $input): array
    {
        $current = $this->app->aligo()->settings->formValues();

        $values = $input;
        $values['api_key'] = '';
        $values['api_key_set'] = $current['api_key_set'];
        $values['alimtalk_api_key'] = '';
        $values['alimtalk_api_key_set'] = $current['alimtalk_api_key_set'];
        $values['test_mode'] = !empty($input['test_mode']);
        // 채널 허용 스위치는 이 폼에 없다. 저장이 실패해도 바뀌지 않았으므로 그대로 보여준다.
        $values['sms_enabled'] = $current['sms_enabled'];
        $values['alimtalk_enabled'] = $current['alimtalk_enabled'];

        return $values;
    }

    private function render(ServerRequestInterface $request, ResponseInterface $response,
        ?array $values, array $errors, ?string $error, ?array $verified, array $profiles,
        ?array $notice = null): ResponseInterface
    {
        $values ??= $this->app->aligo()->settings->formValues();
        // 발신번호는 언제나 하이픈 붙은 표시용 형태로 보여준다. PhoneNumber::format() 은
        // 숫자가 아닌 문자를 걸러내고 패턴에 안 맞으면 숫자만 그대로 돌려주므로, 저장된
        // 값이든 방금 입력해 실패한 값이든 이 자리 하나에서 안전하게 처리된다.
        $values['sender'] = PhoneNumber::format((string) ($values['sender'] ?? ''));

        return View::fromRequest($request)->render($response, 'admin/aligo_settings', [
            'values' => $values,
            'status' => $this->app->aligo()->status(),
            'errors' => $errors,
            'error' => $error,
            'verified' => $verified,
            'profiles' => $profiles,
            'notice' => $notice,
            'query' => $request->getQueryParams(),
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
        array $query = []): ResponseInterface
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($route);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $response->withHeader('Location', $url)->withStatus(303);
    }
}
