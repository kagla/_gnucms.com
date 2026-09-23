<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/** KG이니시스 웹표준/모바일 인증 후 서버 승인, INIAPI v2 조회·환불. */
final class InicisGateway extends DirectGateway
{
    public function checkout(array $order, array $customer, string $returnUrl, string $callbackUrl, string $device = 'web'): array
    {
        if ((int) $order['total'] > 999999999) throw DomainError::validation(['amount' => '카드 결제 한도를 초과했습니다.']);
        $config = $this->prepare($order, $returnUrl, $callbackUrl);
        $timestamp = (string) (int) (microtime(true) * 1000);
        return ['kind' => 'inicis-pro', 'script' => 'https://paypro.inicis.com/std/payment/js/INIPayPro_v2.js', 'fields' => [
            'P_MID' => $config['merchant_id'], 'P_OID' => $order['id'], 'P_PAY_TYPE' => 'CARD',
            'P_DEVICE_TYPE' => $device === 'mobile' ? 'MOBILE' : 'WEB', 'P_IDCCODE' => 'Y',
            'P_AMT' => (string) $order['total'], 'P_GOODS' => mb_strcut($order['order_name'], 0, 80, 'UTF-8'),
            'P_UNAME' => mb_strcut($customer['name'], 0, 30, 'UTF-8'), 'P_NEXT_URL' => $callbackUrl,
            'P_RESERVED' => json_encode(['email' => $customer['email']], JSON_THROW_ON_ERROR),
            // PayPro는 결과 콜백 뒤에도 닫기 주소로 이동할 수 있어 주문 완료 이동을 덮어쓴다.
            'P_CHARSET' => 'UTF-8', 'P_TIMESTAMP' => $timestamp,
            'P_CHKFAKE' => self::mobileHash($config, $order, $timestamp),
        ]];
    }

    private static function signature(array $fields): string
    {
        $pairs = []; foreach ($fields as $key => $value) $pairs[] = $key . '=' . $value;
        return hash('sha256', implode('&', $pairs));
    }

    private static function mobileHash(array $config, array $order, string $timestamp): string
    {
        return base64_encode(hash('sha512', $order['total'] . $order['id'] . $timestamp . $config['hash_key'], true));
    }

    private function callbackUrl(array $config, array $callback, bool $mobile, bool $cancel = false): string
    {
        $idc = self::value($callback, 'idc_name', 3);
        if (!in_array($idc, $config['environment'] === 'test' ? ['stg'] : ['fc', 'ks'], true)) throw DomainError::forbidden('결제 IDC 환경이 다릅니다.');
        $url = self::value($callback, $mobile ? 'P_REQ_URL' : ($cancel ? 'netCancelUrl' : 'authUrl'), 200);
        $host = $idc . ($mobile ? 'mobile' : 'stdpay') . '.inicis.com';
        if (parse_url($url, PHP_URL_HOST) !== $host || !StreamTransport::allowed($url)) throw DomainError::forbidden('이니시스 승인 경로가 아닙니다.');
        return $mobile && $cancel ? 'https://' . $host . '/smart/payNetCancel.ini' : $url;
    }

    private function proUrl(array $callback, bool $cancel = false): string
    {
        $idc = strtolower(self::value($callback, 'P_IDCNAME', 3));
        // PayPro 안내대로 응답의 IDC를 쓰되, 요청 가능한 호스트는 알려진 세 IDC로 제한한다.
        if (!in_array($idc, ['fc', 'ks', 'stg'], true)) {
            throw new DomainError('PAY_IDC_INVALID', '이니시스 IDC 코드를 확인할 수 없습니다.', 403);
        }
        return 'https://' . $idc . 'paypro.inicis.com/payment/v1/rest/' . ($cancel ? 'payNetCancel' : 'payAppl') . '.ini';
    }

    protected function validateCallback(array $config, array $order, array $callback): void
    {
        if (isset($callback['P_AUTH_TID'])) {
            if (self::value($callback, 'P_STATUS', 4) !== '00'
                || self::value($callback, 'P_MID', 10) !== $config['merchant_id']
                || self::value($callback, 'P_OID', 40) !== $order['id']
                || self::amount($callback['P_AMT'] ?? null) !== (int) $order['total']) {
                throw DomainError::validation(['payment' => '인증 결과의 상점·주문·금액을 확인해 주세요.']);
            }
            self::value($callback, 'P_AUTH_TID', 40);
            $this->proUrl($callback);
            return;
        }
        $mobile = isset($callback['P_STATUS']);
        if ($mobile) {
            if (self::value($callback, 'P_STATUS', 4) !== '00' || self::amount($callback['P_AMT'] ?? null) !== (int) $order['total']) throw DomainError::validation(['payment' => '인증이 완료되지 않았거나 결제 금액이 다릅니다.']);
            self::value($callback, 'P_TID', 40);
        } else {
            if (($callback['resultCode'] ?? '') !== '0000' || ($callback['mid'] ?? '') !== $config['merchant_id'] || ($callback['orderNumber'] ?? '') !== $order['id']) throw DomainError::validation(['payment' => '인증 결과의 상점과 주문번호를 확인해 주세요.']);
            self::value($callback, 'authToken');
        }
        $this->callbackUrl($config, $callback, $mobile);
        $this->callbackUrl($config, $callback, $mobile, true);
    }

    protected function approve(array $config, array $order, array $callback): array
    {
        if (isset($callback['P_AUTH_TID'])) return $this->approvePro($config, $order, $callback);
        $mobile = isset($callback['P_STATUS']); $timestamp = (string) (Clock::timestamp() * 1000);
        $body = $mobile ? ['P_MID' => $config['merchant_id'], 'P_TID' => $callback['P_TID']] : [
            'mid' => $config['merchant_id'], 'authToken' => $callback['authToken'], 'timestamp' => $timestamp,
            'signature' => self::signature(['authToken' => $callback['authToken'], 'timestamp' => $timestamp]),
            'verification' => self::signature(['authToken' => $callback['authToken'], 'signKey' => $config['sign_key'], 'timestamp' => $timestamp]),
            'charset' => 'UTF-8', 'format' => 'JSON', 'price' => (string) $order['total'],
        ];
        try {
            $response = $this->request($this->callbackUrl($config, $callback, $mobile), $body, true);
            $valid = $mobile
                ? ($response['P_STATUS'] ?? '') === '00' && ($response['P_MID'] ?? '') === $config['merchant_id'] && ($response['P_OID'] ?? '') === $order['id'] && self::amount($response['P_AMT'] ?? null) === (int) $order['total'] && ($response['P_TYPE'] ?? '') === 'CARD'
                : ($response['resultCode'] ?? '') === '0000' && ($response['mid'] ?? '') === $config['merchant_id'] && ($response['MOID'] ?? '') === $order['id'] && self::amount($response['TotPrice'] ?? null) === (int) $order['total'] && in_array($response['payMethod'] ?? '', ['Card', 'VCard'], true) && in_array($response['currency'] ?? '', ['WON', 'KRW', '410'], true);
            if (!$valid) throw DomainError::serviceUnavailable('승인 결과가 주문과 일치하지 않습니다. PG에서 상태를 확인해 주세요.');
            return ['tid' => self::value($response, $mobile ? 'P_TID' : 'tid', 40)];
        } catch (\Throwable $error) {
            // 응답 유실·검증 실패 시 공식 망취소. 일반 환불에 재사용하지 않는다.
            $cancelBody = $mobile ? $body + ['P_AMT' => (string) $order['total'], 'P_OID' => $order['id'], 'P_TIMESTAMP' => $timestamp, 'P_CHKFAKE' => self::mobileHash($config, $order, $timestamp)] : $body;
            try { $this->request($this->callbackUrl($config, $callback, $mobile, true), $cancelBody, true); } catch (\Throwable) {}
            throw $error;
        }
    }

    private function approvePro(array $config, array $order, array $callback): array
    {
        $body = ['P_MID' => $config['merchant_id'], 'P_AUTH_TID' => $callback['P_AUTH_TID'],
            'P_AMT' => (string) $order['total'], 'P_CHARSET' => 'UTF-8'];
        try {
            $response = $this->request($this->proUrl($callback), $body, true);
            if (($response['P_STATUS'] ?? '') !== '00'
                || ($response['P_MID'] ?? '') !== $config['merchant_id']
                || ($response['P_OID'] ?? '') !== $order['id']
                || self::amount($response['P_AMT'] ?? null) !== (int) $order['total']
                || ($response['P_TYPE'] ?? '') !== 'CARD') {
                throw DomainError::serviceUnavailable('승인 결과가 주문과 일치하지 않습니다. PG에서 상태를 확인해 주세요.');
            }
            return ['tid' => self::value($response, 'P_APPL_TID', 40)];
        } catch (\Throwable $error) {
            $timestamp = (string) (int) (microtime(true) * 1000);
            $cancel = $body + ['P_OID' => $order['id'], 'P_CANCEL_MSG' => 'Merchant approval verification failed',
                'P_TIMESTAMP' => $timestamp, 'P_CHKFAKE' => self::mobileHash($config, $order, $timestamp)];
            try { $this->request($this->proUrl($callback, true), $cancel, true); } catch (\Throwable) {}
            throw $error;
        }
    }

    private function api(array $config, string $type, array $data): array
    {
        $timestamp = self::now();
        $serverIp = $this->settings->app->config('payment.inicis.client_ip');
        $clientIp = $serverIp === null || $serverIp === '' ? $config['client_ip'] : $serverIp;
        if (!is_string($clientIp) || !filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw DomainError::serviceUnavailable('이니시스 요청 서버 IPv4 주소를 확인해 주세요.');
        }
        return $this->request('https://' . ($config['environment'] === 'test' ? 'stginiapi' : 'iniapi') . '.inicis.com/v2/pg/' . $type,
            ['mid' => $config['merchant_id'], 'type' => $type, 'timestamp' => $timestamp, 'clientIp' => $clientIp,
                'hashData' => hash('sha512', $config['api_key'] . $config['merchant_id'] . $type . $timestamp . StreamTransport::json($data)), 'data' => $data]);
    }

    protected function query(array $config, array $order, array $state): array
    {
        $tid = $state['approved']['tid'] ?? $order['transaction_id'] ?? '';
        $data = $this->api($config, 'inquiry', $tid !== '' ? ['tid' => $tid] : ['oid' => $order['id']]);
        if (($data['resultCode'] ?? '') !== 'SUCCESS') throw DomainError::serviceUnavailable('이니시스 거래 조회를 확인하지 못했습니다. 주문번호 조회에는 중복방지 계약이 필요합니다.');
        $status = match ($data['transactionStatus'] ?? '') { 'APPROVAL' => 'PAID', 'PART_CANCEL' => 'PARTIAL_CANCELLED', 'CANCEL' => 'CANCELLED', default => throw DomainError::serviceUnavailable('카드결제 상태가 확정되지 않았습니다.') };
        $total = (int) $order['total'];
        $paidAt = self::date(($data['approvedDate'] ?? '') . ($data['approvedTime'] ?? ''));
        $valid = ($data['mid'] ?? '') === $config['merchant_id'] && ($data['oid'] ?? '') === $order['id'] && self::amount($data['price'] ?? null) === $total
            && ($tid === '' || ($data['tid'] ?? '') === $tid) && in_array($data['paymethod'] ?? '', ['Card', 'VCard'], true) && $paidAt > 0
            && in_array($data['cardInfo']['currencyCode'] ?? '', ['WON', 'KRW', '410'], true);
        $cancelled = $status === 'CANCELLED' ? $total : ($status === 'PARTIAL_CANCELLED' ? $total - self::amount($data['availablePartCancelPrice'] ?? null) : 0);
        $rows = $data['partCancelTransInfo'] ?? []; $cancellations = [];
        if (!is_array($rows) || (!array_is_list($rows) && $rows !== [])) $valid = false;
        else foreach ($rows as $row) {
            if (!is_array($row)) { $valid = false; continue; }
            $at = self::date(($row['requestDate'] ?? '') . ($row['requestTime'] ?? ''));
            $amount = self::amount($row['requestPrice'] ?? null);
            if ($at < 1 || $amount < 1) $valid = false;
            $cancellations[] = ['id' => self::value($row, 'tid', 40), 'amount' => $amount, 'at' => $at, 'reason' => ''];
        }
        if ($status === 'CANCELLED' && $cancellations === []) {
            foreach ($state['refunds'] ?? [] as $refund) if ($refund['status'] === 'succeeded') $cancellations[] = $refund['result'];
            $remaining = $total - array_sum(array_column($cancellations, 'amount'));
            if ($remaining > 0) {
                $at = self::date(($data['cancelDate'] ?? '') . ($data['cancelTime'] ?? ''));
                if ($at < 1) $valid = false;
                $cancellations[] = ['id' => $data['tid'] . '-full', 'amount' => $remaining, 'at' => $at, 'reason' => ''];
            }
        }
        return ['status' => $status, 'valid' => $valid && $cancelled >= 0 && $cancelled <= $total, 'transaction_id' => self::value($data, 'tid', 40), 'paid_at' => $paidAt, 'cancelled' => $cancelled, 'cancellations' => $cancellations];
    }

    protected function refund(array $config, array $order, array $state, int $amount, int $remaining, string $reason, string $key): array
    {
        $tid = $order['transaction_id'] ?? $state['approved']['tid'] ?? '';
        if ($tid === '') throw DomainError::validation(['refund' => '승인 거래번호가 필요합니다.']);
        $full = $amount === (int) $order['total'];
        $data = ['tid' => $tid, 'msg' => mb_strcut($reason, 0, 80, 'UTF-8')];
        if (!$full) $data += ['price' => (string) $amount, 'confirmPrice' => (string) ($remaining - $amount), 'currency' => 'WON', 'taxFree' => '0'];
        $result = $this->api($config, $full ? 'refund' : 'partialRefund', $data);
        if (($result['resultCode'] ?? '') !== '00') throw DomainError::serviceUnavailable('이니시스 환불이 확정되지 않았습니다. PG 내역을 확인해 주세요.');
        $at = self::date(($result[$full ? 'cancelDate' : 'prtcDate'] ?? '') . ($result[$full ? 'cancelTime' : 'prtcTime'] ?? ''));
        if ($at < 1) throw DomainError::serviceUnavailable('환불 시간을 확인하지 못했습니다.');
        if (!$full && (self::amount($result['prtcPrice'] ?? null) !== $amount || self::amount($result['prtcRemains'] ?? null) !== $remaining - $amount
            || ($result['prtcTid'] ?? null) !== $tid)) throw DomainError::serviceUnavailable('환불 금액과 원거래를 확인하지 못했습니다.');
        return ['id' => $full ? $tid . '-full' : self::value($result, 'tid', 40), 'amount' => $amount, 'at' => $at];
    }
}
