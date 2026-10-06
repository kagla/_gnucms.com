<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Auth\Acl;
use GnuCms\Cms\CmsRepository;
use GnuCms\Validation\Validator;

/** 알림톡 본문의 공통 정보. 계정·발신번호·발송 스위치와 별도로 저장한다. */
final class MessageInfo
{
    private const FIELDS = ['name' => ['사이트명', 100], 'url' => ['사이트주소', 1000], 'contact' => ['문의처', 500]];
    private CmsRepository $repository;
    private \Closure $defaults;

    /** @param callable(): array<string,string> $defaults */
    public function __construct(CmsRepository $repository, callable $defaults)
    {
        $this->repository = $repository;
        $this->defaults = \Closure::fromCallable($defaults);
    }

    public function formValues(): array
    {
        $stored = $this->repository->settings();
        $values = [];
        foreach (self::FIELDS as $field => [$variable, $limit]) {
            $values[$field] = (string) ($stored['alimtalk.info.' . $field] ?? '');
        }
        return $values;
    }

    /** 빈 항목은 사이트 기본 정보로 대신하며, 템플릿 변수 이름으로 반환한다. */
    public function defaults(): array
    {
        return ($this->defaults)();
    }

    public function resolved(): array
    {
        $stored = $this->formValues();
        $defaults = $this->defaults();
        $values = [];
        foreach (self::FIELDS as $field => [$variable, $limit]) {
            $value = trim($stored[$field]);
            $values[$variable] = $value !== '' ? $value : (string) ($defaults[$variable] ?? '');
        }
        return $values;
    }

    public function save(Acl $acl, array $input): void
    {
        $acl->assertGlobalAdmin();
        $validator = new Validator($input);
        $settings = [];
        foreach (self::FIELDS as $field => [$variable, $limit]) {
            $value = $validator->optionalString($field, $limit, '') ?? '';
            if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                $validator->fail($field, '줄바꿈 없이 입력해 주세요.');
            }
            if ($field === 'url' && $value !== '') {
                $url = parse_url($value);
                if (filter_var($value, FILTER_VALIDATE_URL) === false || !is_array($url)
                    || !in_array(strtolower((string) ($url['scheme'] ?? '')), ['http', 'https'], true)
                    || !isset($url['host']) || isset($url['user']) || isset($url['pass'])) {
                    $validator->fail($field, 'http:// 또는 https://로 시작하는 홈페이지 주소를 입력해 주세요.');
                }
            }
            $settings['alimtalk.info.' . $field] = $value;
        }
        $validator->check();
        $this->repository->saveSettings($settings);
    }
}
