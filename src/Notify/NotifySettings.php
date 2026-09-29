<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Aligo\MessageText;
use GnuCms\Aligo\Templates;
use GnuCms\Aligo\Variables;
use GnuCms\Error\DomainError;

/**
 * 모든 이벤트의 메일을 켜고, 이벤트마다 알림톡·문자·인앱 사용 여부와 알림톡 승인
 * 템플릿의 변수 연결을 저장·검증한다. 발송 자체는 하지 않는다.
 *
 * **알 수 없는 이벤트 키.** 관리자가 저장한 값은 업그레이드로 카탈로그(Events::ALL)에서
 * 빠진 이벤트를 계속 가리킬 수 있다 — 저장소에는 그 값이 그대로 남아 있는데 코드는 더는
 * 그 이벤트를 모르는 상태다. 이 클래스는 그런 이벤트를 "아무것도 설정하지 않은 것"과
 * 완전히 같게 다룬다: channelsFor()·isOn()·smsBody() 는 빈 값을, templateFor() 는 null 을
 * 돌려주고, formValues() 의 묶음에도 나타나지 않는다(카탈로그를 훑어 만들기 때문에
 * 자연히 빠진다). save() 만 예외다 — 알 수 없는 이벤트를 새로 저장하려는 시도는 거절한다.
 * 저장소에 남은 낡은 값은 읽지 않을 뿐 지우지도 않는다: 지우는 것은 이 클래스의 책임이
 * 아니고, 같은 키가 나중에 카탈로그로 돌아오면(드문 일이지만) 그 값이 다시 뜻을 갖는다.
 *
 * **저장된 값은 약속이 아니다.** save() 가 알림톡 템플릿을 검증하는 것은 저장하는
 * 그 순간뿐이다. 그 뒤 Templates::fetch() 가(카카오 승인이 풀리거나 목록에서 사라져)
 * 조용히 그 템플릿을 disable 하거나 내용을 바꿀 수 있다 — 관리자가 아무것도 다시
 * 손대지 않아도 저장된 tpl_code 가 더는 쓸 수 없는 것을 가리키게 된다. 그래서
 * channelsFor()·isOn()·templateFor() 는 저장된 alimtalk 설정을 그대로 믿지 않고, 읽을
 * 때마다 Templates::find() 로 그 템플릿이 지금도 존재·enabled 이고 지금의 본문 변수를
 * 저장된 var_map 이 빠짐없이 덮는지 다시 확인한다(validTemplate()). 셋 중 하나라도
 * 이 재확인을 건너뛰면 나머지와 어긋난 답을 하게 되므로, 셋 다 같은 private 메서드
 * 하나로 판단을 모은다. 템플릿이 죽으면 channelsFor() 는 alimtalk 를 빼고,
 * templateFor() 는 null 을 돌려준다 — 관리자가 켰다고 저장한 값과 지금 실제로 쓸 수
 * 있는 값이 다를 수 있다는 뜻을 formValues() 의 alimtalk_tpl_code(원본, 검증 안 함)로
 * 화면에 남긴다.
 *
 * **채널이 꺼져 있으면 그 채널의 내용도 안 내준다.** save() 는 채널을 끌 때 그 채널의
 * tpl_code·var_map·sms_body 를 지우지 않는다 — 관리자가 알림톡·문자를 다시 켤 때 매핑과
 * 본문을 다시 만들지 않아도 되게 하려는 의도된 동작이다. 하지만 그래서 templateFor()·
 * smsBody() 를 channelsFor()/isOn() 과 따로 물으면 "꺼진 채널의 멀쩡한 설정"이 나올 수
 * 있다 — 부르는 쪽이 isOn() 을 먼저 확인하지 않으면 꺼진 채널을 켜진 것처럼 믿게 된다.
 * 그래서 templateFor() 는 isOn($event,'alimtalk') 을, smsBody() 는 isOn($event,'sms') 를
 * 먼저 확인하고, 꺼져 있으면 저장된 값이 무엇이든 null·''을 돌려준다. 저장소의 값은
 * 그대로 남아 있으므로 다시 켜면 바로 돌아온다.
 */
final class NotifySettings
{
    public const CHANNELS = ['mail', 'alimtalk', 'sms', 'inbox'];

    /** 전화 채널(알림톡·문자)에 속한 채널 키. Events::phoneCapable() 이 거짓인 이벤트에는
     *  이 채널들을 절대 켤 수 없다 — save() 는 거절하고, channelsFor() 는 저장소에 무엇이
     *  남아 있든 걸러낸다. 알림함도 Events::inboxCapable() 로 똑같이 다룬다. */
    private const PHONE_CHANNELS = ['alimtalk', 'sms'];

    /** 설치 직후에도 모든 코어 알림은 기본 메일로 보낸다. */
    private const DEFAULTS = [
        'password_reset' => ['mail'], 'password_changed' => ['mail'], 'welcome' => ['mail'],
        'comment_new' => ['mail', 'inbox'], 'email_verify' => ['mail'],
        'signup_attempt' => ['mail'], 'social_email_verify' => ['mail'],
    ];

    private SettingsRepository $repository;
    private Templates $templates;

    public function __construct(SettingsRepository $repository, Templates $templates)
    {
        $this->repository = $repository;
        $this->templates = $templates;
    }

    /**
     * 저장소를 읽을 수 없는 자리에서 "설정하기 전의 동작"만 묻는 통로. 알림 발송기가
     * 배선되지 않은 채 조립된 서비스(AccountService::notify() 주석)가 쓴다 — 그 자리가
     * DEFAULTS 를 다시 적으면 기본값이 두 군데로 갈라진다.
     *
     * @return list<string>
     */
    public static function defaultChannels(string $event): array
    {
        return self::DEFAULTS[$event] ?? [];
    }

    /**
     * **관리자가 켜 둔 것으로 저장돼 있는 채널.** channelsFor() 와 달리 그 채널을 지금
     * 실제로 쓸 수 있는지는 따지지 않는다.
     *
     * 이 메서드가 있는 이유는 하나다: 두 답이 갈리는 순간을 부르는 쪽이 알아볼 수 있어야
     * 하기 때문이다. channelsFor() 의 재확인(전화 가능·알림함 가능·템플릿 유효)은 저장된
     * 설정을 **비울 수 있고**, 그때 결과는 "관리자가 아무것도 켜 두지 않았다"와 구별되지
     * 않는다. 앞은 설정이고 뒤는 사고에 가깝다(카카오 승인이 풀린 알림톡 템플릿 하나로
     * 댓글 알림이 통째로 멈춘다). 발송기가 그 둘을 다르게 다루려면 재확인을 거치지 않은
     * 답이 하나 필요하다 — formValues() 가 화면을 위해 저장 원본을 함께 내주는 것과
     * 같은 이유의 같은 통로다.
     *
     * @return list<string>
     */
    public function storedChannelsFor(string $event): array
    {
        if (!Events::exists($event)) {
            return [];
        }

        return array_values(array_intersect(self::CHANNELS,
            self::configured($event, $this->repository->all())));
    }

    /**
     * 저장된 설정(또는 저장 전의 기본값)이 켜 둔 채널. 재확인은 하지 않는다 —
     * 그것은 channelsFor() 의 일이다.
     *
     * @return list<string>
     */
    private static function configured(string $event, array $stored): array
    {
        return isset($stored[$event . '.configured'])
            ? array_values(array_filter(self::CHANNELS,
                static fn (string $channel): bool => ($stored[$event . '.' . $channel] ?? '0') === '1'))
            : (self::DEFAULTS[$event] ?? []);
    }

    /** @return list<string> */
    public function channelsFor(string $event): array
    {
        if (!Events::exists($event)) {
            return [];
        }

        $stored = $this->repository->all();
        $on = self::configured($event, $stored);

        // 저장소에 남은 값이 지금 이 이벤트가 쓸 수 없는 전화 채널을 가리켜도(수동 DB
        // 편집이나 검증을 우회한 과거 버전의 흔적일 수 있다) 여기서 한 번 더 걸러낸다.
        // save() 가 이미 막아 두었더라도, 읽는 쪽이 스스로를 지키는 편이 안전하다.
        if (!Events::phoneCapable($event)) {
            $on = array_diff($on, self::PHONE_CHANNELS);
        }
        // 알림함도 같은 이유로 같은 자리에서 한 번 더 거른다. save() 가 막기 전에 저장된
        // 값이나 DB 를 직접 고쳐 넣은 값이 남아 있을 수 있는데, 그 값을 그대로 믿으면
        // "켜져 있다"는 답만 참이고 알림은 영영 아무 데도 가지 않는다.
        if (!Events::inboxCapable($event)) {
            $on = array_diff($on, ['inbox']);
        }
        if (in_array('alimtalk', $on, true) && $this->validTemplate($event, $stored) === null) {
            $on = array_diff($on, ['alimtalk']);
        }

        // 이전 설치에서 메일을 꺼 둔 값이 남아 있어도 코어 알림은 항상 메일을 시도한다.
        return array_values(array_intersect(self::CHANNELS, array_merge(['mail'], $on)));
    }

    public function isOn(string $event, string $channel): bool
    {
        return in_array($channel, $this->channelsFor($event), true);
    }

    /**
     * 알림톡이 꺼져 있으면 매핑이 아무리 멀쩡해도 null 이다 — channelsFor()·isOn() 이
     * "꺼졌다"고 답하는데 이 메서드만 tpl_code 를 내주면, 부르는 쪽이 굳이 isOn() 을
     * 먼저 물어보지 않는 한 "쓸 수 있다"고 믿어 버린다. 저장된 매핑 자체는 save() 가
     * 지우지 않는다(다시 켤 때 다시 고르지 않아도 되게) — 여기서는 그 값을 안 내줄
     * 뿐이다.
     *
     * @return array{tpl_code:string,var_map:array}|null
     */
    public function templateFor(string $event): ?array
    {
        if (!Events::exists($event) || !$this->isOn($event, 'alimtalk')) {
            return null;
        }

        return $this->validTemplate($event, $this->repository->all());
    }

    /**
     * 저장된 tpl_code 가 지금도 실제로 보낼 수 있는 템플릿을 가리키는지 다시 확인한다.
     * find() 가 못 찾거나(삭제됨) enabled 가 아니거나(승인·정상을 잃음), 그 사이 알리고
     * 쪽에서 본문이 바뀌어 저장된 var_map 이 지금의 변수를 다 덮지 못하면 전부 "쓸 수
     * 없음"이다 — save() 가 저장할 때 검증한 것과 정확히 같은 기준을, 읽을 때 지금의
     * Templates 사본을 대상으로 다시 적용할 뿐이다.
     *
     * @return array{tpl_code:string,var_map:array}|null
     */
    private function validTemplate(string $event, array $stored): ?array
    {
        $code = (string) ($stored[$event . '.tpl_code'] ?? '');
        if ($code === '') {
            return null;
        }
        $template = $this->templates->find($code);
        if ($template === null || (int) $template['enabled'] !== 1) {
            return null;
        }

        $map = json_decode((string) ($stored[$event . '.var_map'] ?? '[]'), true);
        $map = is_array($map) ? $map : [];
        $allowed = Events::variables($event);
        foreach (Variables::names((string) $template['content']) as $name) {
            $core = $map[$name] ?? null;
            if (!is_string($core) || $core === '' || !in_array($core, $allowed, true)) {
                return null;
            }
        }

        return ['tpl_code' => $code, 'var_map' => $map];
    }

    /** 같은 이유로 문자도 꺼져 있으면 본문을 내주지 않는다 — 저장된 본문 자체는
     *  save() 가 지우지 않는다. */
    public function smsBody(string $event): string
    {
        if (!Events::exists($event) || !$this->isOn($event, 'sms')) {
            return '';
        }

        return (string) ($this->repository->all()[$event . '.sms_body'] ?? '');
    }

    public function save(string $event, array $input): void
    {
        if (!Events::exists($event)) {
            throw DomainError::validation(['event' => '알 수 없는 알림입니다.']);
        }
        $allowed = Events::variables($event);
        $saved = [$event . '.configured' => '1'];

        foreach (self::CHANNELS as $channel) {
            $on = ($input[$channel] ?? '') === '1';
            if ($on && in_array($channel, self::PHONE_CHANNELS, true) && !Events::phoneCapable($event)) {
                throw DomainError::validation([$channel =>
                    '이 알림은 이메일로만 보낼 수 있습니다. 받는 사람이 이메일로만 확인됩니다.']);
            }
            if ($on && $channel === 'inbox' && !Events::inboxCapable($event)) {
                throw DomainError::validation([$channel =>
                    '이 알림은 사이트 내 알림함에 쌓을 수 없습니다. 알림함은 로그인한 회원이 읽는 곳이라'
                    . ' 지금은 새 댓글·답글 알림만 받습니다.']);
            }
            $saved[$event . '.' . $channel] = ($channel === 'mail' || $on) ? '1' : '0';
        }

        // **채널이 꺼져 있어도 들어온 내용은 저장한다.** 예전에는 채널이 켜져 있을
        // 때만 본문·템플릿을 썼는데, 화면은 꺼진 채널의 칸도 고칠 수 있게 내준다
        // (꺼졌다고 감추면 관리자는 자기가 쓴 본문을 잃은 줄 안다 — 그것이 그 칸을
        // 보여 주는 이유다). 그 둘이 어긋나면 "고치고 저장 → 저장했습니다 → 옛 값이
        // 그대로"가 된다: 성공처럼 보이는 유실이다. 그래서 들어온 값은 채널 스위치와
        // 무관하게 저장하고, **들어오지 않은 값만** 건드리지 않는다 — 그래야 채널을
        // 끄는 저장(본문·템플릿을 아예 싣지 않는다)이 예전처럼 매핑을 지키면서도,
        // 값을 실어 보낸 저장은 그 값을 실제로 남긴다.
        // **"빈 값으로 보냈다"와 "아예 안 보냈다"는 다른 사실이다.** 이 둘을 한데 묶으면
        // 둘 중 하나는 반드시 거짓말이 된다 — 2계획의 전화번호 칸에서는 disabled 라
        // 빠진 값을 "지우라"로 읽어 저장된 번호를 말없이 지웠고, 여기서는 반대로 빈
        // 값을 "안 보냈다"로 읽어 **본문을 영영 못 지우게** 만들었다(문자를 켜면 빈
        // 본문은 거절되므로 지울 길이 아예 없었다). 그래서 무엇을 할지는 **키가
        // 왔는가**로 정하고, 무엇을 쓸지는 그 값으로 정한다.
        //   키 없음      — 건드리지 않는다. 채널만 끄는 저장(본문을 싣지 않는다)이 이 길이다.
        //   키 있고 빈 값 — 지운다. 관리자가 칸을 비우고 저장한 것이다(문자가 켜져 있으면
        //                   그 전에 거절된다 — 켠 채로 본문 없는 상태는 허용하지 않는다).
        //   키 있고 값 있음 — 검사하고 저장한다. 채널이 꺼져 있어도 그렇다.
        // 스칼라가 아닌 값(sms_body[]=x)은 stringInput() 이 예전부터 "값 없음"으로 다룬다.
        $bodyGiven = array_key_exists('sms_body', $input);
        $body = $this->stringInput($input, 'sms_body');
        if ($saved[$event . '.sms'] === '1' && $body === '') {
            throw DomainError::validation(['sms_body' => '문자로 보낼 본문을 입력해 주세요.']);
        }
        if ($bodyGiven && $body !== '') {
            $unknown = array_diff(Variables::names($body), $allowed);
            if ($unknown !== []) {
                throw DomainError::validation(['sms_body' =>
                    '이 알림이 제공하지 않는 변수가 있습니다: ' . implode(', ', $unknown)
                    . '. 쓸 수 있는 변수는 ' . implode(', ', $allowed) . ' 입니다.']);
            }
            // 보낼 수 없는 본문은 켜져 있든 꺼져 있든 저장하지 않는다. 저장해 두면
            // 켜는 순간 channelsFor() 가 sms 를 내주고 화면은 초록으로 칠하는데,
            // 실제 발송은 Dispatch 에서 전건 거절된다 — 켜졌다고 답하면서 아무것도
            // 보낼 수 없는 상태, 이 클래스가 알림톡에서 없애려고 애쓴 바로 그 상태다.
            self::assertSendable($body);
        }
        if ($bodyGiven) {
            $saved[$event . '.sms_body'] = $body;
        }

        // **tpl_code 는 일부러 다르게 다룬다 — 빈 값을 "지우라"로 읽지 않는다.** 본문
        // 칸과 달리 이 칸의 빈 값은 두 가지를 뜻할 수 있고, 화면은 그 둘을 구별해 보낼
        // 수 없다: 관리자가 "고르지 않음"을 고른 경우와, **저장된 템플릿이 죽어 고를
        // 목록에 없는** 경우다(후자에서는 어떤 option 도 selected 가 되지 않아 빈 값이
        // 나간다). 빈 값을 지우기로 읽으면 후자에서 다른 칸만 고쳐 저장하는 순간 죽은
        // tpl_code 가 사라지는데, 그 코드는 화면이 「저장해 두신 템플릿(T1)을 더는 쓸 수
        // 없습니다」라고 이유를 말할 수 있는 유일한 근거다(formValues 의 alimtalk_tpl_code).
        // 못 지워서 잃는 것도 없다: 알림톡을 끄면 templateFor() 는 어차피 null 이고,
        // 다른 템플릿으로 바꾸는 길은 열려 있다. 남은 코드는 아무 데도 나가지 않는다.
        // 그 대신 **명시적인 지우기**를 둔다. 빈 <select> 의 뜻을 추측하는 대신 뜻이
        // 하나뿐인 칸(tpl_clear 체크박스)을 관리자가 직접 누르게 하면 모호함이 아예
        // 생기지 않는다. 지울 수 없게 두는 것은 안전하지 않았다: 쓸 수 있는 템플릿이
        // 하나도 없는데 알림톡이 켜진 채로 저장돼 있으면 그 묶음의 **모든** 저장이
        // 거절되고(문자 본문을 고쳐도 함께 버려진다), 알림톡을 끄고 나면 죽은 참조가
        // 영영 남는다.
        $clearTemplate = ($input['tpl_clear'] ?? '') === '1';
        // 새로 고른 템플릿이 있으면 그쪽이 이긴다 — 지우기는 고를 것을 고르지 않았을
        // 때만 뜻이 있다. 이 한 줄이 그 우선순위를 담고, 아래 세 갈래가 모두 이것을 쓴다.
        $clearApplies = $clearTemplate && $this->stringInput($input, 'tpl_code') === '';
        if ($clearApplies) {
            // **"죽었다"를 여기서 다시 따진다.** 화면은 죽은 참조에만 이 칸을 그리지만,
            // 그 판단은 화면을 그린 순간의 것이다 — 그 사이 관리자가 템플릿을 다시
            // 사용으로 바꾸거나 Templates::fetch() 가 내용을 되돌려 놓으면(이 화면의
            // 안내문이 「다시 가져오거나」라고 권하는 바로 그 일이다) 살아난 설정을
            // 낡은 체크 하나가 말없이 지우게 된다. 읽는 쪽의 가드만 믿지 않는 것은
            // channelsFor() 가 저장된 행을 믿지 않고 validTemplate() 로 다시 묻는 것과
            // 같은 규칙이다. 살아 있으면 지우지 않고, 조용히 넘기지도 않고, 말한다.
            //
            // **이 검사가 아래 "켠 채로는 못 지운다"보다 먼저다.** 둘 다 걸리는 요청
            // (되살아났는데 알림톡도 켜져 있다)에서 순서가 반대면, 관리자는 템플릿이
            // 돌아왔다는 말은 듣지 못한 채 "알림톡을 끄라"는 지시만 받고, 그대로 따른
            // 뒤에야 진짜 이유를 듣는다. 두 문장 중 더 구체적인 쪽이 먼저 나와야 한다.
            $current = $this->validTemplate($event, $this->repository->all());
            if ($current !== null) {
                throw DomainError::validation(['tpl_clear' => sprintf(
                    '지우려던 템플릿(%s)을 지금은 다시 쓸 수 있습니다 — 그 사이 승인이 돌아왔거나'
                    . ' 템플릿을 다시 가져온 것으로 보입니다. 아무것도 지우지 않았습니다.'
                    . ' 화면을 새로 고쳐 확인한 뒤 다시 정해 주세요.', $current['tpl_code']
                )]);
            }
            if ($saved[$event . '.alimtalk'] === '1') {
                throw DomainError::validation(['tpl_clear' =>
                    '알림톡을 켠 채로는 템플릿 설정을 지울 수 없습니다. 알림톡을 끄고 저장하거나,'
                    . ' 쓸 수 있는 템플릿을 골라 주세요.']);
            }
        }
        if ($saved[$event . '.alimtalk'] === '1' || $this->stringInput($input, 'tpl_code') !== '') {
            // var_map 은 여기서 tpl_code 와 **함께 통째로** 쓰인다 — 한 칸씩 지워지거나
            // 남거나 하는 일이 없으므로 위와 같은 질문이 생기지 않는다.
            $saved += $this->alimtalkSettings($event, $input, $allowed);
        }
        // $clearApplies 는 tpl_code 가 비어 있었다는 뜻이고, 그때 위 분기는 알림톡이
        // 켜져 있으면 이미 거절했다 — 그러므로 여기 올 때 tpl_code 키는 아직 없다.
        if ($clearApplies) {
            $saved[$event . '.tpl_code'] = '';
            $saved[$event . '.var_map'] = '[]';
        }

        $this->repository->save($saved);
    }

    /**
     * 문자로 실제로 나갈 수 있는 본문인지. 길이와 인코딩 규칙은 MessageText 한 곳에만
     * 있다 — 여기서 다시 적으면 EUC-KR 바이트 경계를 두 군데서 따로 틀리게 된다.
     * 화면의 칸 이름이 sms_body 이므로 오류만 그 이름으로 바꿔 단다(MessageText 는
     * 관리자 발송 화면의 'body' 칸을 가리킨다).
     *
     * 변수 자리에 들어갈 값은 여기서 잴 수 없다 — 이 검사는 하한이다. 그래도 본문
     * 자체가 이미 한계를 넘었으면 그 알림은 무슨 값이 들어가든 절대 못 나간다.
     */
    private static function assertSendable(string $body): void
    {
        try {
            MessageText::assertFits($body, null);
        } catch (DomainError $e) {
            throw DomainError::validation(['sms_body' => implode(' ', $e->details())]);
        }
    }

    /**
     * 승인 템플릿의 변수명은 사이트마다 다르다. 템플릿의 변수 하나하나가 이 이벤트가
     * 실제로 갖고 있는 코어 변수와 이어져야만 켤 수 있다 — 매핑이 아예 없는 변수도,
     * 존재하지 않는 코어 변수를 가리키는 매핑도 "아직 못 이었다"는 점에서 똑같다: 어느
     * 쪽이든 발송 시점에 그 변수는 채울 값이 없다.
     */
    private function alimtalkSettings(string $event, array $input, array $allowed): array
    {
        $code = $this->stringInput($input, 'tpl_code');
        $template = $code === '' ? null : $this->templates->find($code);
        if ($template === null || (int) $template['enabled'] !== 1) {
            throw DomainError::validation(['tpl_code' => '사용 중인 승인 템플릿을 골라 주세요.']);
        }

        $rawMap = is_array($input['var_map'] ?? null) ? $input['var_map'] : [];
        $map = [];
        $unmapped = [];
        foreach (Variables::names((string) $template['content']) as $name) {
            $core = is_scalar($rawMap[$name] ?? null) ? trim((string) $rawMap[$name]) : '';
            if ($core === '' || !in_array($core, $allowed, true)) {
                $unmapped[] = $name;
                continue;
            }
            $map[$name] = $core;
        }
        if ($unmapped !== []) {
            throw DomainError::validation(['var_map' =>
                '템플릿 변수에 넣을 값을 모두 골라 주세요. 남은 변수: ' . implode(', ', $unmapped)]);
        }

        return [
            $event . '.tpl_code' => $code,
            $event . '.var_map' => (string) json_encode($map, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** 스칼라가 아닌 입력(배열 등)은 문자열로 캐스팅하지 않고 빈 문자열로 다룬다 —
     *  캐스팅 경고 없이 "값 없음"으로 취급해 뒤이은 검증이 자연히 거절하게 한다. */
    private function stringInput(array $input, string $key): string
    {
        return is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    }

    /**
     * 저장된 변수 연결 원본. 손으로 고친 값이나 옛 버전이 남긴 값이 JSON 이 아니거나
     * 배열이 아닐 수 있으므로 그때는 빈 배열로 본다 — 화면이 첨자로 쓰는 값이라,
     * 문자열 아닌 것이 섞여 들어오면 그 칸만 버린다. 검증은 하지 않는다(그것이
     * validTemplate() 의 일이다): 여기는 "무엇이 저장돼 있는가"만 답한다.
     *
     * @return array<string,string>
     */
    private static function storedMap(array $stored, string $event): array
    {
        $map = json_decode((string) ($stored[$event . '.var_map'] ?? '[]'), true);
        if (!is_array($map)) {
            return [];
        }
        $clean = [];
        foreach ($map as $name => $core) {
            if (is_string($core)) {
                $clean[(string) $name] = $core;
            }
        }

        return $clean;
    }

    public function formValues(): array
    {
        $stored = $this->repository->all();
        $values = [];
        foreach (Events::ALL as $key => $event) {
            $values[$key] = [
                'label' => $event['label'],
                'vars' => $event['vars'],
                'phone' => $event['phone'],
                // 화면이 켤 수 없는 칸을 꺼진 채로 그릴 수 있게 함께 내준다. 켤 수 없는
                // 칸을 멀쩡히 보여 주고 저장할 때만 거절하는 것은 같은 결함의 다른 모습이다.
                'inbox' => $event['inbox'],
                'channels' => $this->channelsFor($key),
                'template' => $this->templateFor($key),
                'sms_body' => $this->smsBody($key),
                // 아래 넷은 **검증을 거치지 않은 저장 원본**이다. 위의 channels·
                // template·sms_body 가 "지금 실제로 쓸 수 있는가"를 답한다면, 이 넷은
                // "관리자가 무엇을 저장해 두었는가"를 답한다. 둘을 나란히 내주는 이유는
                // 하나다 — 둘이 어긋날 때 화면이 그 사실을 말할 수 있어야 하기 때문이다.
                // 어긋난 상태를 그냥 "꺼짐"으로만 그리면, 관리자는 자기가 켜 둔 채널이
                // 스스로 꺼진 것을 이유 없이 보게 되거나(알림톡) 자기가 쓴 본문이
                // 사라진 것처럼 보게 된다(문자).
                //
                //  alimtalk_tpl_code — 고른 템플릿 코드. template 이 null 인데 이 값이
                //      비어 있지 않다면 그 템플릿이 그 사이 못 쓰게 된 것이다. 화면은
                //      "알림톡이 꺼졌습니다"가 아니라 "저장해 두신 템플릿(OOO)을 더는 쓸 수
                //      없습니다"라고 진짜 이유를 말한다.
                //  alimtalk_on — 관리자가 알림톡을 켜 두었는가. 이것 없이는 위 문장을
                //      말할 수 없다: 템플릿이 멀쩡한데 관리자가 알림톡을 그냥 꺼 둔
                //      경우에도 template 은 null 이라(isOn() 게이트), 두 경우를 가릴
                //      길이 없어 화면이 멀쩡한 템플릿을 죽었다고 말하게 된다.
                //  alimtalk_var_map — 저장된 변수 연결. save() 는 채널을 꺼도 이 값을
                //      지우지 않는다(다시 켤 때 다시 고르지 않아도 되게). 화면이 이 값을
                //      되살리지 않으면 그 의도가 화면에서 무너진다.
                //  sms_body_stored — 저장된 문자 본문. 같은 이유다. sms_body 는 채널이
                //      꺼지면 빈 문자열이므로, 이 값이 없으면 문자를 껐다 돌아온 관리자는
                //      자기 본문이 지워진 빈 칸을 보게 된다.
                'alimtalk_tpl_code' => (string) ($stored[$key . '.tpl_code'] ?? ''),
                // 저장된 tpl_code 를 **지금도 쓸 수 있는가**. template 과 달리 채널
                // 스위치를 보지 않는다 — 꺼져 있을 때 template 이 null 인 것은 isOn()
                // 게이트 때문이지 템플릿이 죽어서가 아니라서, 그 둘을 가리려면 게이트를
                // 지나지 않은 답이 하나 필요하다. 판정은 여전히 validTemplate() 하나가
                // 한다(게이트를 우회하는 것이지 재확인을 우회하는 것이 아니다). 이것이
                // 없으면 화면은 꺼진 채널의 죽은 템플릿을 두고 "켜면 이대로 나갑니다"라고
                // 말하게 된다 — 켜면 422 로 거절당하는데도.
                'alimtalk_template_usable' => $this->validTemplate($key, $stored) !== null,
                'alimtalk_on' => ($stored[$key . '.alimtalk'] ?? '0') === '1',
                'alimtalk_var_map' => self::storedMap($stored, $key),
                'sms_body_stored' => (string) ($stored[$key . '.sms_body'] ?? ''),
            ];
        }

        return $values;
    }
}
