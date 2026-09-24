<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

/** NHN KCP 표준결제: 웹/모바일 인증, 서버 승인·조회·취소. */
final class KcpGateway extends DirectGateway
{
    private const METHOD = ['card' => ['pc' => '100000000000', 'mobile' => 'CARD', 'pay_type' => 'PACA'],
        'bank_transfer' => ['pc' => '010000000000', 'mobile' => 'BANK', 'pay_type' => 'PABK'],
        'mobile' => ['pc' => '000010000000', 'mobile' => 'MOBX', 'pay_type' => 'PAMC']];

    public function checkout(array $order, array $customer, string $returnUrl, string $callbackUrl, string $device = 'web'): array
    {
        if ((int) $order['total'] > 999999999999) throw DomainError::validation(['amount' => 'KCP 결제 한도를 초과했습니다.']);
        $config = $this->prepare($order, $returnUrl, $callbackUrl);
        $method = self::METHOD[$order['method']] ?? throw DomainError::validation(['payment_method' => 'KCP 결제수단을 확인해 주세요.']);
        $mobile = $device === 'mobile';
        $fields = ['site_cd' => $config['site_cd'], 'pay_method' => $mobile ? $method['mobile'] : $method['pc'],
            'ordr_idxx' => $order['id'], 'good_name' => mb_strcut($order['order_name'], 0, 100, 'UTF-8'),
            'good_mny' => (string) $order['total'], 'currency' => $mobile ? '410' : 'WON',
            'buyr_name' => mb_strcut($customer['name'], 0, 40, 'UTF-8'), 'buyr_mail' => mb_strcut($customer['email'], 0, 100, 'UTF-8'),
            'buyr_tel2' => mb_strcut($customer['phone'], 0, 20, 'UTF-8'), 'Ret_URL' => $callbackUrl];
        if (!$mobile) {
            $fields['site_name'] = 'GNUCMS';
            $fields['quotaopt'] = '12';
            return ['kind' => 'kcp-web', 'script' => 'https://' . ($config['environment'] === 'test' ? 'testspay' : 'spay') . '.kcp.co.kr/plugin/kcp_spay_hub.js', 'fields' => $fields];
        }

        $registration = $this->api('https://' . ($config['environment'] === 'test' ? 'testsmpay' : 'smpay') . '.kcp.co.kr/trade/register.do', [
            'site_cd' => $config['site_cd'], 'ordr_idxx' => $order['id'], 'good_mny' => (string) $order['total'],
            'good_name' => $fields['good_name'], 'pay_method' => $method['mobile'], 'Ret_URL' => $callbackUrl,
            'escw_used' => 'N',
        ]);
        if (($registration['Code'] ?? '') !== '0000') $this->declined($registration);
        $payUrl = self::value($registration, 'PayUrl', 2048);
        $host = $config['environment'] === 'test' ? 'testsmpay.kcp.co.kr' : 'smpay.kcp.co.kr';
        if (parse_url($payUrl, PHP_URL_SCHEME) !== 'https' || parse_url($payUrl, PHP_URL_HOST) !== $host
            || parse_url($payUrl, PHP_URL_PATH) !== '/pay/mobileGW.kcp' || parse_url($payUrl, PHP_URL_USER) !== null) {
            throw DomainError::serviceUnavailable('KCP 모바일 결제 주소를 확인하지 못했습니다.');
        }
        $fields += ['approval_key' => self::value($registration, 'approvalKey', 2048), 'PayUrl' => $payUrl,
            'shop_name' => 'GNUCMS', 'shop_user_id' => $order['id']];
        $action = 'https://' . $host . '/pay/jsp/encodingFilter/encodingFilter.jsp';
        return ['kind' => 'kcp-mobile', 'action' => $action, 'fields' => $fields];
    }

    protected function validateCallback(array $config, array $order, array $callback): void
    {
        if (isset($callback['res_cd']) && $callback['res_cd'] !== '0000') $this->declined($callback);
        if (($callback['ordr_idxx'] ?? '') !== $order['id'] || ($callback['site_cd'] ?? $config['site_cd']) !== $config['site_cd']
            || ($callback['tran_cd'] ?? '') !== '00100000') {
            throw DomainError::forbidden('KCP 인증 결과의 사이트·주문·결제수단을 확인해 주세요.');
        }
        self::value($callback, 'enc_data');
        self::value($callback, 'enc_info');
    }

    protected function approve(array $config, array $order, array $callback): array
    {
        $body = ['tran_cd' => '00100000', 'kcp_cert_info' => self::certificate($config),
            'enc_data' => $callback['enc_data'], 'enc_info' => $callback['enc_info'],
            'ordr_mony' => (string) $order['total'], 'ordr_no' => $order['id'],
            'pay_type' => self::METHOD[$order['method']]['pay_type']];
        $response = $this->api($this->url($config, '/gw/enc/v1/payment'), $body);
        if (($response['res_cd'] ?? '') !== '0000') $this->declined($response);
        if (($response['order_no'] ?? '') !== $order['id'] || self::amount($response['amount'] ?? null) !== (int) $order['total']
            || ($response['pay_method'] ?? '') !== self::METHOD[$order['method']]['pay_type']) {
            throw DomainError::serviceUnavailable('KCP 승인 결과가 주문과 일치하지 않습니다. 결제 상태를 조회해 주세요.');
        }
        return ['tid' => self::value($response, 'tno', 14)];
    }

    protected function query(array $config, array $order, array $state): array
    {
        $tid = (string) ($state['approved']['tid'] ?? '');
        if ($tid === '') throw DomainError::serviceUnavailable('KCP 거래번호가 없어 결제 상태를 조회할 수 없습니다.');
        $payType = self::METHOD[$order['method']]['pay_type'] ?? '';
        $response = $this->api($this->url($config, '/std/inquery'), [
            'site_cd' => $config['site_cd'], 'kcp_cert_info' => self::certificate($config), 'tno' => $tid,
            'pay_type' => $payType,
            // NHN KCP 거래조회 Guide는 pay_type 서명을 안내합니다. API Reference의 서명 설명과 다른 점은 docs/payments.md에 기록합니다.
            'kcp_sign_data' => self::sign($config, $config['site_cd'] . '^' . $tid . '^' . $payType),
        ]);
        $statusCode = '';
        foreach (['stat_ca_cd', 'stat_bk_cd', 'stat_hp_cd', 'shop_status'] as $key) {
            if (is_string($response[$key] ?? null) && $response[$key] !== '') { $statusCode = $response[$key]; break; }
        }
        $remaining = self::amount($response['rem_mny'] ?? $response['bk_rem_mny'] ?? $response['hp_rem_mny'] ?? null);
        $total = self::amount($response['amount'] ?? null);
        $valid = ($response['res_cd'] ?? '') === '0000' && ($response['tno'] ?? '') === $tid
            && (!isset($response['order_no']) || $response['order_no'] === $order['id'])
            && ($response['pay_method'] ?? $payType) === $payType && $total === (int) $order['total']
            && $remaining >= 0 && $remaining <= $total;
        $paidAt = self::date($response['app_time'] ?? $response['hp_app_time'] ?? '');
        $cancelled = $total - $remaining;
        $status = $remaining === 0 || $statusCode === 'STSC' ? 'CANCELLED' : ($remaining > 0 && $paidAt > 0 ? 'PAID' : 'UNKNOWN');
        if (!in_array($statusCode, ['STSR', 'STPC', 'STSC'], true)) $valid = false;
        $result = ['status' => $status, 'valid' => $valid, 'transaction_id' => $tid,
            'paid_at' => $paidAt, 'cancelled' => $cancelled];
        if ($order['method'] === 'card') {
            $cardNumber = is_string($response['card_no'] ?? null) ? $response['card_no'] : '';
            $result['card'] = ['name' => self::optional($response, 'card_name', 80),
                'last_four' => preg_match('/\d{4}$/D', $cardNumber, $match) ? $match[0] : '',
                'quota' => self::optional($response, 'quota', 2), 'interest_free' => ($response['noinf'] ?? '') === 'Y',
                'approval_number' => self::optional($response, 'app_no', 20)];
        }
        return $result;
    }

    protected function refund(array $config, array $order, array $state, int $amount, int $remaining, string $reason, string $key): array
    {
        $tid = (string) ($order['transaction_id'] ?? $state['approved']['tid'] ?? '');
        if ($tid === '') throw DomainError::validation(['refund' => 'KCP 승인 거래번호가 필요합니다.']);
        $full = $amount === (int) $order['total'];
        $type = $full ? 'STSC' : 'STPC';
        $body = ['site_cd' => $config['site_cd'], 'kcp_cert_info' => self::certificate($config), 'tno' => $tid,
            'mod_type' => $type, 'mod_desc' => mb_strcut($reason, 0, 100, 'UTF-8'),
            'kcp_sign_data' => self::sign($config, $config['site_cd'] . '^' . $tid . '^' . $type)];
        if (!$full) $body += ['mod_mny' => (string) $amount, 'rem_mny' => (string) $remaining];
        $response = $this->api($this->url($config, '/gw/mod/v1/cancel'), $body);
        if (($response['res_cd'] ?? '') !== '0000' || ($response['tno'] ?? '') !== $tid) {
            throw DomainError::serviceUnavailable('KCP 취소가 확정되지 않았습니다. 결제 내역에서 처리 여부를 확인해 주세요.');
        }
        if (!$full && (self::amount($response['mod_mny'] ?? null) !== $amount
            || self::amount($response['rem_mny'] ?? null) !== $remaining - $amount)) {
            throw DomainError::serviceUnavailable('KCP 부분취소 결과 금액을 확인하지 못했습니다.');
        }
        $at = self::date($response['canc_time'] ?? '');
        if ($at < 1) throw DomainError::serviceUnavailable('KCP 취소 시각을 확인하지 못했습니다.');
        return ['id' => $full ? $tid . '-full' : self::value($response, 'mod_pcan_seq_no', 40), 'amount' => $amount, 'at' => $at];
    }

    private function api(string $url, array $body): array
    {
        $response = $this->request($url, $body, false, ['Content-Type' => 'application/json; charset=UTF-8']);
        return $response;
    }

    private function url(array $config, string $path): string
    {
        return 'https://' . ($config['environment'] === 'test' ? 'stg-' : '') . 'spl.kcp.co.kr' . $path;
    }

    private static function certificate(array $config): string
    {
        return preg_replace('/\s+/', '', $config['certificate']) ?? '';
    }

    private static function sign(array $config, string $message): string
    {
        $key = openssl_pkey_get_private($config['private_key'], $config['private_key_password']);
        if ($key === false || !openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw DomainError::serviceUnavailable('KCP 개인키 설정을 확인해 주세요.');
        }
        return base64_encode($signature);
    }

    private function declined(array $response): never
    {
        $code = is_string($response['res_cd'] ?? $response['Code'] ?? null) ? (string) ($response['res_cd'] ?? $response['Code']) : '';
        $message = is_string($response['res_msg'] ?? $response['Message'] ?? null) ? (string) ($response['res_msg'] ?? $response['Message']) : '';
        $message = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $message) ?? ''), 0, 200, 'UTF-8');
        throw new DomainError('PAYMENT_DECLINED', 'NHN KCP에서 결제를 거절했습니다.', 422,
            ['pg_status' => preg_match('/^[A-Za-z0-9_-]{1,16}$/D', $code) ? $code : '', 'pg_message' => $message]);
    }

    private static function optional(array $input, string $key, int $max): string
    {
        $value = $input[$key] ?? '';
        return is_string($value) && strlen($value) <= $max && !preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : '';
    }
}
