<?php

declare(strict_types=1);

namespace GnuCms\Payment;

/** PG별 설정·결제창·통신 구현. 등록은 신뢰할 수 있는 서버 코드에서만 한다. */
interface Provider
{
    public function id(): string;
    public function label(): string;
    /** @return array<string,array{label:string,secret:bool,multiline:bool}> */
    public function fields(): array;
    public function manual(): string;
    public function validate(array $input, array $before, string $environment): array;
    /** 같은 가맹점의 키 교체만 과거 주문에 반영한다. 가맹점 변경은 반영하지 않는다. */
    public function credentials(array $revision, ?array $current): array;
    /** @return list<string> 쇼핑몰이 지원하는 수단과 교집합만 노출한다. */
    public function methods(): array;
    public function supportsPartialRefund(): bool;
    /** 기본 테마 및 테마 재정의에서 찾는 신뢰된 결제창 조각 이름. */
    public function checkoutTemplate(): string;
    public function gateway(Settings $settings): Gateway;
}
