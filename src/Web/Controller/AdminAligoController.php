<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Aligo\MessageText;
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
        return $this->renderSettings($request, $response);
    }

    /** 이전 통합 주소의 저장 결과를 새 메뉴로 보낸다. */
    public function messaging(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $query = $request->getQueryParams();
        if (($query['aligo_saved'] ?? '') === '1') {
            $params = ['saved' => '1'];
            foreach (['cancel_ok', 'cancel_failed', 'cancel_unknown'] as $key) {
                if (isset($query[$key]) && is_scalar($query[$key])) {
                    $params[$key] = (string) $query[$key];
                }
            }
            return $this->redirect($request, $response, 'admin.aligo', $params, 'aligo-result');
        }
        if (is_scalar($query['notify_saved'] ?? null)) {
            return $this->redirect($request, $response, 'admin.settings.notifications',
                ['saved' => (string) $query['notify_saved']], 'events');
        }
        if (($query['mail_tested'] ?? '') === '1') {
            return $this->redirect($request, $response, 'admin.mail', ['tested' => '1'], 'mail');
        }
        if (($query['mail_saved'] ?? '') === '1') {
            return $this->redirect($request, $response, 'admin.mail', ['saved' => '1'], 'mail');
        }

        return $this->redirect($request, $response, 'admin.mail');
    }

    /** 문자·알림톡 계정과 발송 허용만 표시한다. */
    private function renderSettings(ServerRequestInterface $request, ResponseInterface $response,
        array $overrides = []): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $query = $request->getQueryParams();
        $aligoValues = $overrides['aligo_values'] ?? $this->app->aligo()->settings->formValues();
        $aligoValues['sender'] = PhoneNumber::format((string) ($aligoValues['sender'] ?? ''));

        return View::fromRequest($request)->render($response, 'admin/aligo_settings', [
            'values' => $aligoValues,
            'status' => $this->app->aligo()->status(),
            'errors' => $overrides['aligo_errors'] ?? [],
            'error' => $overrides['aligo_error'] ?? null,
            'error_at' => $overrides['aligo_error_at'] ?? null,
            'notice' => ($query['saved'] ?? '') === '1' ? $this->savedNotice($query) : null,
            'profiles' => $overrides['aligo_profiles'] ?? [],
            'verified' => $overrides['aligo_verified'] ?? null,
        ]);
    }

    /**
     * 계정 저장. 사용자ID·API 키가 바뀌면 두 채널 스위치가 함께 꺼지고, 그때는 끄기
     * 버튼과 똑같이 걸려 있던 예약도 취소된다(AligoService::saveSettings()). 그래서
     * 저장도 toggle() 과 같은 숫자를 안내에 싣는다 — 한쪽 길만 숫자를 말하고 다른 쪽이
     * 침묵하면 관리자는 침묵하는 쪽을 "아무 일도 없었다"로 읽는다.
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $result = $this->app->aligo()->saveSettings($input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->renderSettings($request, $response->withStatus(422), [
                'aligo_values' => $this->reshow($input), 'aligo_errors' => $e->details(),
            ]);
        }

        return $this->redirect($request, $response, 'admin.aligo',
            self::savedQuery($result), 'aligo-result');
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $verified = $this->app->aligo()->verify();
        } catch (DomainError $e) {
            return $this->renderSettings($request, $response->withStatus($e->status() === 422 ? 422 : 502), [
                'aligo_errors' => $e->details(), 'aligo_error' => $e->getMessage(),
            ]);
        }

        return $this->renderSettings($request, $response, ['aligo_verified' => $verified]);
    }

    public function profiles(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $profiles = $this->app->aligo()->profiles();
        } catch (DomainError | TransportFailure $e) {
            return $this->renderSettings($request, $response->withStatus(502), [
                'aligo_error' => $e->getMessage(), 'aligo_error_at' => 'profiles',
            ]);
        }

        return $this->renderSettings($request, $response, ['aligo_profiles' => $profiles]);
    }

    /**
     * 채널을 끄면 이미 걸린 예약도 함께 취소된다(AligoService::setChannelEnabled()) —
     * 부분 취소는 흔하므로 그 결과를 저장 안내에 숫자로 싣는다. 문장은 savedNotice()
     * 가 조립한다.
     */
    public function toggle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $ajax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest'
            || stripos($request->getHeaderLine('Accept'), 'application/json') !== false;
        try {
            $this->assertCsrf($input);
            $this->app->guestAcl()->assertGlobalAdmin();
            $channel = is_scalar($input['channel'] ?? null) ? (string) $input['channel'] : '';
            $action = is_scalar($input['action'] ?? null) ? (string) $input['action'] : '';
            if (!in_array($action, ['enable', 'disable'], true)) {
                throw DomainError::validation(['action' => '켜기 또는 끄기를 선택해 주세요.']);
            }
            $result = $this->app->aligo()->setChannelEnabled(
                $channel, $action === 'enable'
            );
        } catch (DomainError $e) {
            if ($ajax) {
                $details = $e->details();
                $detail = $details === [] ? null : reset($details);
                return $this->toggleJson($response, [
                    'ok' => false,
                    'message' => is_scalar($detail) ? (string) $detail : $e->getMessage(),
                ], $e->status());
            }
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->renderSettings($request, $response->withStatus(422), [
                'aligo_errors' => $e->details(),
            ]);
        }

        if ($ajax) {
            return $this->toggleJson($response, [
                'ok' => true,
                'status' => $this->app->aligo()->channelStatus(),
                'notice' => $this->savedNotice(self::savedQuery($result)),
            ]);
        }

        return $this->redirect($request, $response, 'admin.aligo',
            self::savedQuery($result), 'aligo-result');
    }

    /** 토글 응답이 끊겨도 브라우저가 실제 저장값을 다시 확인할 수 있다. */
    public function channelStatus(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();

        return $this->toggleJson($response, [
            'ok' => true,
            'status' => $this->app->aligo()->channelStatus(),
        ]);
    }

    private function toggleJson(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * 저장된 API 키를 되돌려 준다 — 화면의 눈 버튼이 fetch 로 부른다. 메일 설정의 앱 비밀번호
     * 보기(AdminCmsController::mailPassword())와 같은 규칙이다: 페이지 HTML 에는 키를 싣지
     * 않고(소스 보기에 나오면 안 된다), 관리자가 눌렀을 때만 CSRF 를 확인한 POST 로 내주며,
     * 응답은 캐시하지 않는다.
     */
    public function apiKey(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->guestAcl()->assertGlobalAdmin();
        $account = $this->app->aligo()->settings->runtime();
        $response->getBody()->write((string) json_encode(
            ['api_key' => (string) ($account['api_key'] ?? '')],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * 저장·끄기가 함께 싣는 리다이렉트 쿼리. 두 길이 같은 취소 조율을 쓰므로 안내도
     * 같은 값으로 만든다 — 숫자만 싣고 문장은 savedNotice() 가 만든다.
     *
     * @param array{cancelled:int,failed:int,reasons:list<string>,cancel_unverified?:bool} $result
     * @return array<string,string>
     */
    private static function savedQuery(array $result): array
    {
        $query = ['saved' => '1'];
        if (!empty($result['cancel_unverified'])) {
            $query['cancel_unknown'] = '1';
        }
        if ($result['cancelled'] > 0 || $result['failed'] > 0) {
            $query['cancel_ok'] = (string) $result['cancelled'];
            $query['cancel_failed'] = (string) $result['failed'];
        }

        return $query;
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
        return $this->renderNotifications($request, $response);
    }

    private function renderNotifications(ServerRequestInterface $request, ResponseInterface $response,
        ?array $posted = null, array $errors = []): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $view = $this->notifyRows($posted);
        $error = ($errors !== [] && !isset($view['rows'][$view['open']]))
            ? (string) reset($errors) : null;

        return View::fromRequest($request)->render($response, 'admin/notify_settings', [
            'events' => $view['rows'],
            'templates' => $view['templates'],
            'open' => $view['open'],
            'errors' => $errors,
            'error' => $error,
            'notice' => $this->notifySavedNotice($request->getQueryParams()),
            'status' => $this->app->aligo()->status(),
        ]);
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

            return $this->renderNotifications($request, $response->withStatus(422),
                $input, $e->details());
        }

        // 쿼리에는 이벤트 키(카탈로그가 정한 열거값)만 싣는다. 문장은 아래에서 만든다 —
        // 화면에 그대로 찍을 문장을 쿼리로 받으면 공격자가 만든 URL 을 관리자가 열었을 때
        // 우리가 그 문장을 시스템 알림처럼 보여주게 된다(AdminMessageController 클래스 주석).
        return $this->redirect($request, $response, 'admin.settings.notifications',
            ['saved' => $event], 'events');
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
            $storedSmsBody = $value['sms_body_stored'];
            $defaultSmsBody = Events::defaultSmsBody($key);
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
                // 아직 저장한 문구가 없으면 이벤트에 맞는 기본 문구로 시작한다. 이 값은
                // 화면에서만 채우는 초깃값이며, 관리자가 저장한 문구는 그대로 우선한다.
                'sms_body' => $storedSmsBody !== '' ? $storedSmsBody : $defaultSmsBody,
                'sms_body_default' => $storedSmsBody === '' || $storedSmsBody === $defaultSmsBody,
                'sms_body_template' => $defaultSmsBody,
                // 아래 둘은 **저장된 것**을 말한다. 422 되보여주기에서 입력으로 덮이는
                // tpl_code 와 달리, 관리자가 방금 무엇을 골랐든 저장소에 남아 있는 참조는
                // 그대로다 — 그래서 입력 덮어쓰기와 섞지 않는다. 안내문 셋은 덮은 **뒤에**
                // 만든다(아래).
                'stored_tpl_code' => $value['alimtalk_tpl_code'],
                'tpl_dead' => $value['alimtalk_tpl_code'] !== '' && !$value['alimtalk_template_usable'],
                // 지우기 체크는 저장된 상태가 아니라 이번 요청의 입력이다. 저장에
                // 성공하면 참조 자체가 사라져 칸도 없어지므로 평소에는 늘 꺼진 채로
                // 그려지고, 422 되보여주기에서만 아래 withPostedInput() 이 되살린다.
                'tpl_clear' => false,
            ];
            $row += self::bodySize($row['sms_body']);
            if ($postedEvent === $key) {
                $row = self::withPostedInput($row, $posted ?? []);
            }
            // 안내문은 **덮어쓴 뒤에** 만든다. 체크박스가 지금 어떤 상태로 그려지는지에
            // 따라 문장이 달라지는데(켜 둔 채 못 나가는 것과 꺼 둔 것은 급한 정도가
            // 다르다), 덮기 전에 만들면 422 화면에서 문장과 체크박스가 서로 다른 것을
            // 가리킨다. 예전에는 그래서 덮을 때 문장을 통째로 지웠는데, 그러면 죽은
            // 템플릿이라는 가장 중요한 사실이 하필 저장이 거절된 화면에서만 사라졌다.
            $row['alimtalk_notice'] = self::alimtalkNotice($row);
            $row['sms_notice'] = self::smsNotice($row);
            $row['alimtalk_off_notice'] = self::alimtalkOffNotice($row);
            $rows[$key] = $row;
        }

        return ['rows' => $rows, 'templates' => $usable, 'open' => $postedEvent];
    }

    /**
     * 본문이 실제로 차지하는 크기. 문자의 한계는 글자 수가 아니라 **EUC-KR 바이트**이고
     * 한글은 한 자에 두 바이트다 — 브라우저의 maxlength 는 글자를 세므로 그 둘은 서로
     * 다른 것을 잰다. 관리자가 어디쯤 왔는지 보이지 않으면 한계는 저장 버튼을 누른
     * 뒤에야 나타난다. SMS·LMS 경계도 함께 보여준다: 요금이 갈리는 자리다.
     *
     * 변수 자리에 들어갈 값은 여기서 잴 수 없다(수신자마다 다르다). 화면이 그 사실을
     * 함께 적는다 — 숫자만 보여 주고 "여기까지는 안전하다"고 믿게 두지 않는다.
     *
     * @return array{sms_bytes:int,sms_kind:string,sms_limit:int}
     */
    private static function bodySize(string $body): array
    {
        return [
            'sms_bytes' => $body === '' ? 0 : MessageText::byteLength($body),
            'sms_kind' => $body === '' ? 'sms' : MessageText::channelFor($body),
            'sms_limit' => MessageText::LMS_BYTES,
            // 경계 숫자도 함께 내준다. 화면이 「90바이트」라고 적어 두면 그 숫자가
            // MessageText::SMS_BYTES 와 따로 살게 되고, 상수가 바뀌는 날 화면만 옛 숫자를
            // 말한다 — 바로 위 sms_limit 은 이미 그렇게 하고 있었다.
            'sms_boundary' => MessageText::SMS_BYTES,
        ];
    }

    /**
     * 저장된 알림톡 템플릿을 더는 쓸 수 없을 때 그 이유를 말하는 한 문장.
     *
     * **채널이 꺼져 있어도 말한다.** 예전에는 켜 두었을 때만 말했는데, 그 조건이 하필
     * 가장 거짓말하기 쉬운 상태를 침묵시켰다: 알림톡을 끄고 죽은 참조가 남은 카드가
     * 「켜는 순간 이대로 나갑니다」라고만 적고 있었다 — 켜면 422 로 거절당하는데도.
     * 죽었다는 사실은 스위치와 무관하므로 스위치와 무관하게 적고, 급한 정도만 가른다.
     *
     * 판단은 저장된 값(stored_tpl_code·tpl_dead)으로 한다 — 422 되보여주기에서 관리자가
     * 방금 무엇을 골랐든 저장소에 남은 참조는 그대로이기 때문이다.
     */
    private static function alimtalkNotice(array $row): ?string
    {
        if (!$row['phone']) {
            return null;
        }
        if (!$row['tpl_dead']) {
            // 여기서만 저장소가 아니라 **화면에 그려진 값**(tpl_code)을 본다. 이 문장은
            // 지금 고른 것이 없다는 말이고, 그 답은 <select> 에 있다 — 저장소를 보면
            // 422 되보여주기에서 T1 이 selected 로 그려진 바로 위에 「아직 고른 템플릿이
            // 없습니다」를 적게 된다(아직 저장되지 않았을 뿐이다). 아래 죽은 참조 문장이
            // 저장소를 보는 것은 반대 이유다: 그것은 저장된 참조에 대한 사실이다.
            return ($row['on']['alimtalk'] && $row['tpl_code'] === '')
                ? '알림톡을 켜려면 승인 템플릿과 변수 연결을 선택해 주세요.'
                : null;
        }

        return sprintf(
            '%s 저장된 템플릿(%s)을 사용할 수 없습니다. 템플릿을 다시 가져오거나 다른 템플릿을 고르세요.',
            $row['on']['alimtalk']
                ? '알림톡을 켜 두었지만 지금은 나가지 않습니다.'
                : '알림톡은 꺼져 있고, 지금 이대로는 켤 수도 없습니다.',
            $row['stored_tpl_code']
        );
    }

    /**
     * 꺼진 문자 채널의 본문에 붙는 안내. 저장한 본문이 없으면 화면에 채운 알림별 기본
     * 문구라는 점을 알려 주고, 직접 저장한 본문이면 다시 켤 때 그대로 쓰인다고 말한다.
     */
    private static function smsNotice(array $row): ?string
    {
        if ($row['on']['sms'] || $row['sms_body'] === '') {
            return null;
        }

        if ($row['sms_body_default']) {
            return '문자를 켜면 아래 기본 문구로 발송합니다.';
        }

        return '문자를 켜기 전까지 이 본문은 저장만 됩니다.';
    }

    /**
     * 꺼진 알림톡 채널의 템플릿에 붙는 안내. **지금도 쓸 수 있는 템플릿일 때만** 말한다 —
     * 죽은 참조를 두고 「켜는 순간 이대로 나갑니다」라고 적으면, 켜 봤자 422 로 거절당한다.
     * 그 경우의 진실은 위 alimtalkNotice() 가 말한다.
     */
    private static function alimtalkOffNotice(array $row): ?string
    {
        if ($row['on']['alimtalk'] || $row['stored_tpl_code'] === '' || $row['tpl_dead']) {
            return null;
        }

        return '알림톡을 켜기 전까지 이 템플릿 연결은 저장만 됩니다.';
    }

    /**
     * 422 로 되돌아온 묶음을 관리자가 방금 화면에서 고른 값으로 덮는다. 저장소를 말하는
     * 칸(stored_tpl_code·tpl_dead)은 덮지 않는다 — 저장이 거절됐으므로 저장소는 그대로이고,
     * 안내문 셋은 그 둘과 덮인 체크박스 상태로 이 뒤에 다시 만들어진다.
     */
    private static function withPostedInput(array $row, array $posted): array
    {
        foreach (NotifySettings::CHANNELS as $channel) {
            $row['on'][$channel] = $channel === 'mail' || ($posted[$channel] ?? '') === '1';
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
        $row['sms_body_default'] = trim($row['sms_body']) !== ''
            && trim($row['sms_body']) === Events::defaultSmsBody((string) $row['key']);
        // 이 칸이 빠지면, 「알림톡을 끄고 저장하세요」라는 422 의 지시를 그대로 따른
        // 관리자가 303 과 「저장했습니다」를 받고도 참조는 그대로인 화면을 보게 된다 —
        // 화면이 시킨 대로 했는데 조용히 버려지는 수정이다. 카드의 모든 칸은 422 를
        // 건너 살아남아야 하고, 이 칸만 예외였다.
        $row['tpl_clear'] = ($posted['tpl_clear'] ?? '') === '1';
        // 크기는 방금 들어온 본문으로 다시 잰다 — 거절당한 이유가 길이일 때 화면이
        // 저장된 옛 본문의 크기를 보여주면 관리자는 무엇을 줄여야 하는지 알 수 없다.
        $row = array_replace($row, self::bodySize($row['sms_body']));

        return $row;
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
        if (($query['cancel_unknown'] ?? '') === '1') {
            return ['ok' => false, 'message' => '발송을 껐습니다. 예약 발송의 취소 결과는 확인하지 못했습니다. 발송 이력을 확인해 주세요.'];
        }
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
     * API 키 칸만은 절대 담지 않는다 — 소스 보기에 키가 나오면 안 된다. 저장 여부 표시는
     * 실패 전 저장소 상태를 쓴다.
     */
    private function reshow(array $input): array
    {
        $current = $this->app->aligo()->settings->formValues();

        $values = $input;
        $values['api_key'] = '';
        $values['api_key_set'] = $current['api_key_set'];
        $values['test_mode'] = !empty($input['test_mode']);
        // 채널 허용 스위치는 이 폼에 없다. 저장이 실패해도 바뀌지 않았으므로 그대로 보여준다.
        $values['sms_enabled'] = $current['sms_enabled'];
        $values['alimtalk_enabled'] = $current['alimtalk_enabled'];

        return $values;
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

    /**
     * @param string $fragment 착지할 요소의 id. 폼을 보내면 새로 고침이 일어나는데 맨 위에
     *   착지하면 방금 누른 버튼의 결과를 보려고 다시 내려가야 한다. Location 에 조각을
     *   실으면 브라우저가 그 요소로 내려간 채 연다.
     */
    private function redirect(ServerRequestInterface $request, ResponseInterface $response, string $route,
        array $query = [], string $fragment = ''): ResponseInterface
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($route);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        if ($fragment !== '') {
            $url .= '#' . $fragment;
        }

        return $response->withHeader('Location', $url)->withStatus(303);
    }
}
