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
        if (($order['method'] ?? '') !== 'card') throw DomainError::validation(['payment_method' => '온라인 결제는 신용카드만 지원합니다.']);
        if ((int) $order['total'] > 999999999) throw DomainError::validation(['amount' => '결제 한도를 초과했습니다.']);
        $credentials = $this->credentials($order);
        if (($credentials['mode'] ?? 'general') === 'escrow' && in_array($order['method'], ['bank_transfer', 'virtual_account'], true)) {
            throw DomainError::serviceUnavailable('에스크로 계좌이체·가상계좌 결제 실행은 아직 연결되지 않았습니다.');
        }
        $config = $this->prepare($order, $returnUrl, $callbackUrl);
        $timestamp = (string) (int) (microtime(true) * 1000);
        $method = 'CARD';
        $reserved = ['email' => $customer['email'], 'phonenum' => $customer['phone']];
        $fields = [
            'P_MID' => $config['merchant_id'], 'P_OID' => $order['id'], 'P_PAY_TYPE' => $method,
            'P_DEVICE_TYPE' => $device === 'mobile' ? 'MOBILE' : 'WEB', 'P_IDCCODE' => 'Y',
            'P_AMT' => (string) $order['total'], 'P_GOODS' => mb_strcut($order['order_name'], 0, 80, 'UTF-8'),
            'P_UNAME' => mb_strcut($customer['name'], 0, 30, 'UTF-8'), 'P_NEXT_URL' => $callbackUrl,
            'P_RESERVED' => json_encode($reserved, JSON_THROW_ON_ERROR),
            // PayPro는 결과 콜백 뒤에도 닫기 주소로 이동할 수 있어 주문 완료 이동을 덮어쓴다.
            'P_CHARSET' => 'UTF-8', 'P_TIMESTAMP' => $timestamp,
            'P_CHKFAKE' => self::mobileHash($config, $order, $timestamp),
        ];
        return ['kind' => 'inicis-pro', 'script' => 'https://paypro.inicis.com/std/payment/js/INIPayPro_v2.js', 'fields' => $fields];
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
            $status = $response['P_STATUS'] ?? null;
            if (is_string($status) && $status !== '' && $status !== '00') {
                $message = $response['P_RMESG'] ?? $response['P_RMESG1'] ?? '';
                if (!is_string($message)) $message = '';
                $message = trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $message) ?? '');
                if ($message !== '') $message = mb_substr($message, 0, 200, 'UTF-8');
                throw new DomainError('PAYMENT_DECLINED', '이니시스에서 결제를 거절했습니다.', 422,
                    ['pg_status' => preg_match('/^[A-Za-z0-9_-]{1,16}$/D', $status) ? $status : '', 'pg_message' => $message]);
            }
            $expectedType = match ($order['method']) { 'bank_transfer' => 'BANK', 'virtual_account' => 'VBANK', 'mobile' => 'HPP', default => 'CARD' };
            if (($response['P_STATUS'] ?? '') !== '00'
                || ($response['P_MID'] ?? '') !== $config['merchant_id']
                || ($response['P_OID'] ?? '') !== $order['id']
                || self::amount($response['P_AMT'] ?? null) !== (int) $order['total']
                || ($response['P_TYPE'] ?? '') !== $expectedType) {
                throw DomainError::serviceUnavailable('승인 결과가 주문과 일치하지 않습니다. PG에서 상태를 확인해 주세요.');
            }
            $payment = ['tid' => self::value($response, 'P_APPL_TID', 40)];
            if ($expectedType === 'VBANK') {
                $payment['virtual_account'] = [
                    'bank' => self::value($response, 'P_FN_NM', 100), 'account' => self::value($response, 'P_VACT_NUM', 40),
                    'holder' => self::value($response, 'P_VACT_NAME', 40), 'due_date' => self::value($response, 'P_VACT_DATE', 8),
                    'due_time' => self::value($response, 'P_VACT_TIME', 6),
                ];
            }
            return $payment;
        } catch (\Throwable $error) {
            // PG가 명시적으로 승인 거절을 반환한 경우에는 승인된 거래가 아니므로 망취소하지 않는다.
            if ($error instanceof DomainError && $error->code() === 'PAYMENT_DECLINED') throw $error;
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
        $method = (string) ($order['method'] ?? 'card');
        $transactionStatus = (string) ($data['transactionStatus'] ?? '');
        $status = match ($transactionStatus) {
            'APPROVAL', 'DEPOSIT_COMPLETED', 'WAITING_FOR_REFUND' => 'PAID',
            'NON_DEPOSIT' => 'PENDING',
            'PART_CANCEL' => 'PARTIAL_CANCELLED',
            'CANCEL', 'REFUND_COMPLETED' => 'CANCELLED',
            'DEPOSIT_CANCELED' => 'PENDING',
            default => throw DomainError::serviceUnavailable('결제 상태가 확정되지 않았습니다.'),
        };
        $total = (int) $order['total'];
        $vacct = is_array($data['vacctInfo'] ?? null) ? $data['vacctInfo'] : [];
        $paidAt = self::date(($method === 'virtual_account' ? ($vacct['depositDate'] ?? '') : ($data['approvedDate'] ?? ''))
            . ($method === 'virtual_account' ? ($vacct['depositTime'] ?? '') : ($data['approvedTime'] ?? '')));
        $expectedMethod = match ($method) { 'bank_transfer' => 'DirectBank', 'virtual_account' => 'VBank', 'mobile' => 'HPP', default => ['Card', 'VCard'] };
        $methodValid = is_array($expectedMethod) ? in_array($data['paymethod'] ?? '', $expectedMethod, true) : ($data['paymethod'] ?? '') === $expectedMethod;
        // 거래조회는 입금 전 가상계좌번호를 마스킹해 반환할 수 있다. 고객에게 전달할
        // 발급 정보는 앞 단계에서 결제 승인 응답과 함께 원장에 검증·보관한 값을 쓴다.
        $issuedAccount = is_array($state['approved']['virtual_account'] ?? null) ? $state['approved']['virtual_account'] : [];
        $accountNumber = (string) ($issuedAccount['account'] ?? '');
        $accountBank = (string) ($issuedAccount['bank'] ?? '');
        $accountName = (string) ($issuedAccount['holder'] ?? '');
        $validDate = (string) ($issuedAccount['due_date'] ?? '');
        $validTime = (string) ($issuedAccount['due_time'] ?? '');
        $virtualAccount = ['bank' => $accountBank, 'account' => $accountNumber, 'holder' => $accountName, 'due_date' => $validDate, 'due_time' => $validTime];
        $accountValid = $method !== 'virtual_account' || (preg_match('/^[0-9]{1,16}$/D', $accountNumber)
            && $accountBank !== '' && strlen($accountBank) <= 100 && $accountName !== '' && strlen($accountName) <= 40
            && preg_match('/^[0-9]{8}$/D', $validDate) && preg_match('/^[0-9]{6}$/D', $validTime));
        $valid = ($data['mid'] ?? '') === $config['merchant_id'] && ($data['oid'] ?? '') === $order['id'] && self::amount($data['price'] ?? null) === $total
            && ($tid === '' || ($data['tid'] ?? '') === $tid) && $methodValid && $accountValid
            && ($status === 'PENDING' ? $method === 'virtual_account' : ($paidAt > 0 || $status === 'CANCELLED'))
            && ($method !== 'card' || in_array($data['cardInfo']['currencyCode'] ?? '', ['WON', 'KRW', '410'], true));
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
                $cancelDate = $method === 'virtual_account' && $transactionStatus === 'REFUND_COMPLETED'
                    ? ($data['refundDate'] ?? '') : ($data['cancelDate'] ?? '');
                $cancelTime = $method === 'virtual_account' && $transactionStatus === 'REFUND_COMPLETED'
                    ? ($data['refundTime'] ?? '') : ($data['cancelTime'] ?? '');
                $at = self::date($cancelDate . $cancelTime);
                if ($at < 1) $valid = false;
                $cancellations[] = ['id' => $data['tid'] . '-full', 'amount' => $remaining, 'at' => $at, 'reason' => ''];
            }
        }
        $card = is_array($data['cardInfo'] ?? null) ? $data['cardInfo'] : [];
        $cardNumber = is_string($card['cardNumber'] ?? null) ? $card['cardNumber'] : '';
        $lastFour = preg_match('/([0-9]{4})\D*$/', $cardNumber, $match) ? $match[1] : '';
        $quota = is_string($card['cardQuota'] ?? null) || is_int($card['cardQuota'] ?? null) ? (string) $card['cardQuota'] : '';
        $quota = preg_match('/^[0-9]{2}$/D', $quota) ? (int) $quota : null;
        $cardName = is_string($card['cardName'] ?? null) && strlen($card['cardName']) <= 32
            && !preg_match('/[\x00-\x1f\x7f]/', $card['cardName']) ? $card['cardName'] : '';
        $approvalNumber = is_string($card['approvedNumber'] ?? null) && strlen($card['approvedNumber']) <= 8
            && preg_match('/^[A-Za-z0-9]+$/D', $card['approvedNumber']) ? $card['approvedNumber'] : '';
        $detail = $method === 'virtual_account' ? ['virtual_account' => $virtualAccount] : [];
        $result = ['status' => $status, 'valid' => $valid && $cancelled >= 0 && $cancelled <= $total, 'transaction_id' => self::value($data, 'tid', 40), 'paid_at' => $paidAt, 'cancelled' => $cancelled, 'cancellations' => $cancellations, 'detail' => $detail];
        if ($method === 'card') $result['card'] = ['name' => $cardName, 'last_four' => $lastFour, 'quota' => $quota, 'interest_free' => ($card['isInterestFree'] ?? null) === true, 'approval_number' => $approvalNumber];
        return $result;
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
