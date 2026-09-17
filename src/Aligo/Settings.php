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

    public function save(array $input): void
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

    public function isEnabled(string $channel): bool
    {
        $key = $channel === 'at' ? 'alimtalk_enabled' : 'sms_enabled';

        return ($this->repository->all()[$key] ?? '0') === '1' && $this->runtime() !== null;
    }

    public function setEnabled(string $channel, bool $on): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw DomainError::validation(['channel' => '알림톡 또는 문자를 선택해 주세요.']);
        }
        if ($on && $this->runtime() === null) {
            throw DomainError::validation(['api_key' => '계정을 먼저 저장해 주세요.']);
        }
        $this->repository->save([($channel === 'at' ? 'alimtalk_enabled' : 'sms_enabled') => $on ? '1' : '0']);
    }
}
