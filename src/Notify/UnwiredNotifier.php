<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Mail\MailerInterface;

/**
 * 알림 발송기를 받지 못한 서비스의 대체 출구.
 *
 * AccountService·SocialAuthService·LinkingService 는 App 밖에서도(시험이 특히 그렇다)
 * 조립되고, 그 자리마다 발송기 한 벌(설정 저장소 + 채널 넷 + 알리고 사본)을 만들게 하면
 * 계정 조립이 알림 전체 배선을 끌고 들어온다. 그래서 발송기는 세터로 받고, 세터를 거치지
 * 않은 조립에서는 이 클래스가 대신 선다.
 *
 * **무엇을 하는가.** 설정을 읽을 저장소가 없으므로 "설정하기 전의 동작"(NotifySettings 의
 * 기본값)만 따른다 — 현재 코어 알림 일곱 개는 모두 기본 메일을 보낸다.
 * 문구는 MailBodies 가 만든다. 결과는 Notifier 를 들이기 전 이 서비스들이 하던 일과
 * 정확히 같다.
 *
 * **왜 한 줄을 적는가.** 여기로 온다는 것은 배선이 빠졌다는 뜻이고, 그러면 관리자가
 * 관리 화면에서 켜고 끈 채널 설정이 통째로 무시된다 — 기능이 조용히 꺼진 채로 나가는
 * 가장 알아채기 어려운 방식이다. 대체 경로가 "제대로 배선된 것"과 겉으로 구별되지
 * 않으면 아무도 모른다. 그래서 운영자 로그에 최소 한 줄은 남긴다. 실패한 채널이 한 줄을
 * 남기는데(Notifier::recordFailure) 배선이 통째로 빠진 쪽이 아무 줄도 안 남길 수는 없다.
 *
 * **왜 딱 한 줄인가.** 배선 여부는 프로세스가 사는 동안 바뀌지 않는 사실이라, 발송마다
 * 되풀이해도 새 정보가 없고 정작 읽어야 할 다른 줄을 덮는다. 그래서 서비스 이름마다
 * 프로세스당 한 번만 적는다. php-fpm 워커는 요청을 여러 개 잇따라 처리하므로 이것은
 * "요청당 한 줄"이 아니라 **워커 하나가 사는 동안 한 줄**이다 — 배선이 빠졌다는 사실을
 * 알리는 데에는 그것으로 충분하고(고치기 전까지 워커가 새로 뜰 때마다 다시 적힌다),
 * 요청마다 적으면 로그가 같은 문장으로 덮인다. 이 기록이 static 인 이유도 그것뿐이다 —
 * 이 클래스는 발송할 때마다 새로 만들어 쓰는 값 없는 객체다.
 */
final class UnwiredNotifier
{
    /** @var array<string,true> 이미 한 줄 적은 서비스 이름. */
    private static array $warned = [];

    private ?MailerInterface $mailer;
    private string $owner;

    /** @var \Closure(string): void */
    private \Closure $log;

    /**
     * @param MailerInterface|null $mailer 대체 경로로 쓸 메일 전송기. null 이면 대체
     *   경로조차 없다는 뜻이다(LinkingService 가 그렇다 — 메일러를 쥐고 있지 않다).
     *   그때도 한 줄은 남긴다: 배선이 빠졌다는 사실이 사라지면 안 된다.
     * @param string $owner 배선이 빠진 서비스의 이름. 로그에 그대로 적힌다.
     * @param (callable(string): void)|null $log 기본은 PHP 오류 로그. 시험이 바꿔 끼운다.
     */
    public function __construct(?MailerInterface $mailer, string $owner, ?callable $log = null)
    {
        $this->mailer = $mailer;
        $this->owner = $owner;
        $this->log = $log === null
            ? static function (string $line): void {
                error_log('[' . GNUCMS_ID . '] ' . $line);
            }
            : \Closure::fromCallable($log);
    }

    /**
     * Notifier::canReach() 와 같은 질문. 이 길에는 채널이 메일 하나뿐이므로(그마저 없을
     * 수도 있다) 답도 하나다. 묻기만 하는 자리라 배선 경고는 남기지 않는다 — 경고는
     * 실제로 알림이 이 길로 나갈 때 적는다.
     */
    public function canReach(string $event, Recipient $to): bool
    {
        return $this->mailer !== null
            && in_array('mail', NotifySettings::defaultChannels($event), true)
            && (new MailChannel($this->mailer))->available($event, $to);
    }

    /** @return bool 메일 한 통이라도 실제로 나갔는가. Notifier::notify() 와 같은 뜻이다. */
    public function notify(string $event, Recipient $to, array $vars): bool
    {
        $this->warnOnce();
        if ($this->mailer === null
            || !in_array('mail', NotifySettings::defaultChannels($event), true)) {
            return false;
        }
        (new MailChannel($this->mailer))->send($event, $to, $vars);

        return true;
    }

    private function warnOnce(): void
    {
        if (isset(self::$warned[$this->owner])) {
            return;
        }
        self::$warned[$this->owner] = true;
        // 이 줄이 운영자가 받는 유일한 진단이다. 메일러조차 없는 서비스에까지 "메일로
        // 보냅니다"라고 적으면, 읽는 사람은 메일 설정을 뒤지며 없는 문제를 찾게 된다.
        ($this->log)($this->owner . ' 에 알림 발송기가 배선되지 않았습니다 —'
            . ' 관리자가 켜 둔 채널 설정을 따르지 못하고 '
            . ($this->mailer === null
                ? '대신 보낼 메일 전송기도 없어 이 서비스의 알림은 아무 데도 나가지 않습니다.'
                : '기본값(메일)으로만 보냅니다.'));
    }
}
