<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Validation\Validator;

final class Settings
{
    public const CHANNELS = ['at', 'sms'];

    private SettingsRepository $repository;
    private SecretCipher $cipher;

    public function __construct(SettingsRepository $repository, SecretCipher $cipher)
    {
        $this->repository = $repository;
        $this->cipher = $cipher;
    }

    public function formValues(): array
    {
        $stored = $this->repository->all();

        return [
            'user_id' => (string) ($stored['user_id'] ?? ''),
            'sender' => (string) ($stored['sender'] ?? ''),
            'senderkey' => (string) ($stored['senderkey'] ?? ''),
            'channel_name' => (string) ($stored['channel_name'] ?? ''),
            'api_key' => '',
            'api_key_set' => ($stored['api_key'] ?? '') !== '',
            'alimtalk_api_key' => '',
            'alimtalk_api_key_set' => ($stored['alimtalk_api_key'] ?? '') !== '',
            'test_mode' => ($stored['test_mode'] ?? '0') === '1',
            'sms_enabled' => ($stored['sms_enabled'] ?? '0') === '1',
            'alimtalk_enabled' => ($stored['alimtalk_enabled'] ?? '0') === '1',
        ];
    }

    /**
     * 계정 설정을 저장한다.
     *
     * @param (callable(list<string>): void)|null $beforeChannelsOff 이 저장으로 꺼지는 채널이
     *   있으면 **저장 직전에** 그 목록으로 한 번 부른다. 자리가 저장 뒤가 아니라 앞인 것이
     *   핵심이다: 이 갈고리를 다는 쪽(AligoService::saveSettings())이 하는 일은 그 채널에
     *   걸린 예약을 알리고에서 취소하는 것인데, 새 계정·새 키를 저장한 **뒤에** 취소를
     *   부르면 옛 계정이 접수한 예약을 새 자격증명으로 취소하려 들어 알리고가 거절한다 —
     *   고치려던 결함(계정을 바꾸면 예약이 영영 멈추지 않는다)이 그대로 남는다.
     *   검증(위의 $v->check() 와 키 규칙)은 이 갈고리보다 먼저 끝나므로, 422 로 거절되는
     *   저장은 아무것도 취소하지 않는다.
     */
    public function save(array $input, ?callable $beforeChannelsOff = null): void
    {
        $current = $this->repository->all();
        $v = new Validator($input);
        $userId = $v->requiredString('user_id', 60);
        $senderkey = $v->optionalString('senderkey', 64, '') ?? '';
        $channelName = $v->optionalString('channel_name', 60, '') ?? '';
        $testMode = $v->bool('test_mode', false);
        $v->check();

        $sender = PhoneNumber::normalizeSender((string) ($input['sender'] ?? ''));
        if ($senderkey !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $senderkey) !== 1) {
            throw DomainError::validation(['senderkey' => '발신프로필키를 확인해 주세요.']);
        }

        $keys = [];
        foreach (['api_key', 'alimtalk_api_key'] as $field) {
            $keys[$field] = $this->nextSecret($field, $input, $current);
        }
        if ($keys['api_key'] === '' && $keys['alimtalk_api_key'] !== '') {
            throw DomainError::validation(['api_key' => '알림톡 전용 키만 저장할 수는 없습니다. API 키를 먼저 입력해 주세요.']);
        }

        // 계정이 바뀌면 이전 계정으로 확인한 상태를 물려받지 않는다. 암호문은 매번
        // 임의의 IV 로 달라지므로, 같은 키를 다시 입력한 것인지는 복호화한 평문으로
        // 비교해야 한다. 그렇지 않으면 기존 키를 그대로 재입력만 해도 두 채널이
        // 모두 꺼져버린다.
        $accountChanged = $userId !== (string) ($current['user_id'] ?? '')
            || $this->decryptedOrEmpty($keys['api_key']) !== $this->decryptedOrEmpty((string) ($current['api_key'] ?? ''));

        // 지금 켜져 있는데 이 저장으로 꺼지는 채널. 끄기 버튼이 지나는 길
        // (AligoService::setChannelEnabled())과 같은 조율을 이 길에도 태우기 위해,
        // **무엇이 꺼지는지를 결정하는 이 자리**에서 알린다. 바깥에서 저장 전후를
        // 비교하게 하면 계정이 바뀌었는지 판단하는 규칙(위의 복호화 비교)이 두 곳에 생긴다.
        $switchedOff = [];
        if ($accountChanged) {
            foreach (self::CHANNELS as $channel) {
                if ((string) ($current[self::enabledKey($channel)] ?? '0') === '1') {
                    $switchedOff[] = $channel;
                }
            }
        }
        if ($switchedOff !== [] && $beforeChannelsOff !== null) {
            $beforeChannelsOff($switchedOff);
        }

        $this->repository->save([
            'user_id' => $userId,
            'api_key' => $keys['api_key'],
            'alimtalk_api_key' => $keys['alimtalk_api_key'],
            'sender' => $sender,
            'senderkey' => $senderkey,
            'channel_name' => $channelName,
            'test_mode' => $testMode ? '1' : '0',
            'sms_enabled' => $accountChanged ? '0' : (string) ($current['sms_enabled'] ?? '0'),
            'alimtalk_enabled' => $accountChanged ? '0' : (string) ($current['alimtalk_enabled'] ?? '0'),
        ]);
    }

    /** 빈 문자열은 암호화하지 않고 그대로 "키 없음"으로 다룬다. */
    private function decryptedOrEmpty(string $cipherText): string
    {
        return $cipherText === '' ? '' : $this->cipher->decrypt($cipherText);
    }

    /** 빈 입력은 기존 값을 유지하고, 삭제 체크는 지운다. 저장은 암호문으로 한다. */
    private function nextSecret(string $field, array $input, array $current): string
    {
        if (($input[$field . '_delete'] ?? '') === '1') {
            return '';
        }
        $given = trim((string) ($input[$field] ?? ''));
        if ($given === '') {
            return (string) ($current[$field] ?? '');
        }

        return $this->cipher->encrypt($given);
    }

    public function runtime(): ?array
    {
        $stored = $this->repository->all();
        if (($stored['user_id'] ?? '') === '' || ($stored['api_key'] ?? '') === '') {
            return null;
        }
        $apiKey = $this->cipher->decrypt((string) $stored['api_key']);
        $alimtalkKey = ($stored['alimtalk_api_key'] ?? '') !== ''
            ? $this->cipher->decrypt((string) $stored['alimtalk_api_key'])
            : $apiKey;

        return [
            'user_id' => (string) $stored['user_id'],
            'api_key' => $apiKey,
            'alimtalk_api_key' => $alimtalkKey,
            'sender' => (string) ($stored['sender'] ?? ''),
            'senderkey' => (string) ($stored['senderkey'] ?? ''),
            'test_mode' => ($stored['test_mode'] ?? '0') === '1',
        ];
    }

    /** 채널의 허용 스위치가 저장되는 칸 이름. 세 자리가 같은 이름을 각자 적지 않게 여기 하나에 둔다. */
    private static function enabledKey(string $channel): string
    {
        return $channel === 'at' ? 'alimtalk_enabled' : 'sms_enabled';
    }

    public function isEnabled(string $channel): bool
    {
        return ($this->repository->all()[self::enabledKey($channel)] ?? '0') === '1'
            && $this->runtime() !== null;
    }

    public function setEnabled(string $channel, bool $on): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw DomainError::validation(['channel' => '알림톡 또는 문자를 선택해 주세요.']);
        }
        if ($on && $this->runtime() === null) {
            throw DomainError::validation(['api_key' => '계정을 먼저 저장해 주세요.']);
        }
        $this->repository->save([self::enabledKey($channel) => $on ? '1' : '0']);
    }
}
