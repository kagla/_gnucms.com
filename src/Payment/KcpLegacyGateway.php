<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/** KCP 표준결제 웹 인증 결과를 구형 pp_cli TCP/IP 승인·취소 모듈로 처리한다. */
final class KcpLegacyGateway extends DirectGateway
{
    private const PC_CARD = '100000000000';

    public static function moduleAvailable(string $storageDir): bool
    {
        return self::module($storageDir) !== null && !self::commandDisabled();
    }

    public function checkout(array $order, array $customer, string $returnUrl, string $callbackUrl, string $device = 'web'): array
    {
        if (($order['method'] ?? '') !== 'card') throw DomainError::validation(['payment_method' => '온라인 결제는 신용카드만 지원합니다.']);
        if ((int) $order['total'] > 999999999) throw DomainError::validation(['amount' => 'KCP 결제 한도를 초과했습니다.']);
        if (!self::moduleAvailable($this->settings->app->storageDir())) {
            throw DomainError::serviceUnavailable('KCP TCP/IP 승인 모듈이 설치되지 않았습니다.');
        }
        $config = $this->prepare($order, $returnUrl, $callbackUrl);
        $fields = [
            'req_tx' => 'pay', 'site_cd' => $config['site_cd'], 'site_name' => 'GNUCMS',
            'pay_method' => self::PC_CARD,
            'ordr_idxx' => $order['id'], 'good_name' => mb_strcut($order['order_name'], 0, 100, 'UTF-8'),
            'good_mny' => (string) $order['total'], 'currency' => 'WON',
            'buyr_name' => mb_strcut($customer['name'], 0, 40, 'UTF-8'),
            'buyr_mail' => mb_strcut($customer['email'], 0, 100, 'UTF-8'),
            'buyr_tel1' => mb_strcut($customer['phone'], 0, 20, 'UTF-8'),
            'buyr_tel2' => mb_strcut($customer['phone'], 0, 20, 'UTF-8'),
            'quotaopt' => '12', 'module_type' => '01', 'res_cd' => '', 'res_msg' => '', 'tno' => '',
            'trace_no' => '', 'enc_info' => '', 'enc_data' => '', 'ret_pay_method' => '',
            'tran_cd' => '00100000', 'use_pay_method' => self::PC_CARD, 'good_expr' => '0',
        ];
        return ['kind' => 'kcp-legacy',
            'script' => 'https://' . ($config['environment'] === 'test' ? 'testpay' : 'pay') . '.kcp.co.kr/plugin/payplus_web.jsp',
            'action' => $callbackUrl, 'fields' => $fields];
    }

    protected function validateCallback(array $config, array $order, array $callback): void
    {
        if (($callback['res_cd'] ?? '') !== '0000') $this->declined($callback);
        if (($callback['ordr_idxx'] ?? '') !== $order['id']
            || ($callback['site_cd'] ?? '') !== $config['site_cd']
            || ($callback['tran_cd'] ?? '') !== '00100000'
            || self::amount($callback['good_mny'] ?? null) !== (int) $order['total']) {
            throw DomainError::forbidden('KCP 인증 결과의 사이트·주문·결제수단·금액을 확인해 주세요.');
        }
        self::token($callback, 'enc_data');
        self::token($callback, 'enc_info');
    }

    protected function approve(array $config, array $order, array $callback): array
    {
        $module = self::module($this->settings->app->storageDir());
        if ($module === null || self::commandDisabled()) throw DomainError::serviceUnavailable('KCP TCP/IP 승인 모듈을 실행할 수 없습니다.');
        $ip = $callback['_remote_addr'] ?? '';
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) $ip = '127.0.0.1';
        $response = $this->run($module, $config, [
            'tx_cd' => $callback['tran_cd'], 'pa_url' => $config['environment'] === 'test' ? 'testpaygw.kcp.co.kr' : 'paygw.kcp.co.kr',
            'pa_port' => '8090', 'ordr_idxx' => $order['id'],
            'ordr_data' => 'ordr_mony=' . $order['total'] . "\x1f",
            'enc_data' => $callback['enc_data'], 'enc_info' => $callback['enc_info'],
            'trace_no' => self::safeOptional($callback['trace_no'] ?? '', 64), 'cust_ip' => $ip,
        ]);
        if (($response['res_cd'] ?? '') !== '0000') $this->declined($response);
        $tid = $response['tno'] ?? '';
        $responseOrder = $response['ordr_idxx'] ?? $response['ordr_no'] ?? $order['id'];
        $responseAmount = $response['good_mny'] ?? $response['ordr_mony'] ?? $order['total'];
        if (!is_string($tid) || !preg_match('/^[0-9]{14}$/D', $tid)
            || $responseOrder !== $order['id'] || self::amount($responseAmount) !== (int) $order['total']) {
            throw DomainError::serviceUnavailable('KCP 승인 결과가 주문과 일치하지 않습니다. 결제 상태를 확인해 주세요.');
        }
        $appTime = self::date($response['app_time'] ?? '');
        $cardNumber = is_string($response['card_no'] ?? null) ? $response['card_no'] : '';
        return ['tid' => $tid, 'paid_at' => $appTime > 0 ? $appTime : Clock::timestamp(),
            'card' => ['name' => self::safeOptional($response['card_name'] ?? $response['card_name_eng'] ?? '', 80),
                'last_four' => preg_match('/[0-9]{4}$/D', $cardNumber, $match) ? $match[0] : '',
                'quota' => self::safeOptional($response['quota'] ?? '', 2),
                'interest_free' => ($response['noinf'] ?? '') === 'Y',
                'approval_number' => self::safeOptional($response['app_no'] ?? '', 20)]];
    }

    /** 구형 TCP/IP 계약은 조회 API를 제공하지 않아 이 어댑터가 처리한 승인·취소 원장으로만 조회한다. */
    protected function query(array $config, array $order, array $state): array
    {
        $approved = $state['approved'] ?? [];
        $tid = (string) ($approved['tid'] ?? '');
        if (!preg_match('/^[0-9]{14}$/D', $tid)) throw DomainError::serviceUnavailable('KCP 거래번호가 없어 결제 상태를 확인할 수 없습니다.');
        $cancellations = [];
        foreach ($state['refunds'] ?? [] as $refund) {
            if (($refund['status'] ?? '') !== 'succeeded' || !is_array($refund['result'] ?? null)) continue;
            $cancellations[] = $refund['result'];
        }
        return ['status' => array_sum(array_column($cancellations, 'amount')) >= (int) $order['total'] ? 'CANCELLED' : 'PAID',
            'valid' => true, 'transaction_id' => $tid, 'paid_at' => (int) ($approved['paid_at'] ?? 0),
            'cancelled' => array_sum(array_column($cancellations, 'amount')), 'cancellations' => $cancellations,
            'card' => is_array($approved['card'] ?? null) ? $approved['card'] : []];
    }

    protected function refund(array $config, array $order, array $state, int $amount, int $remaining, string $reason, string $key): array
    {
        if ($amount !== (int) $order['total'] || $remaining !== (int) $order['total']) {
            throw DomainError::validation(['refund' => 'KCP 기존 TCP/IP 연동은 부분 취소를 지원하지 않습니다.']);
        }
        $module = self::module($this->settings->app->storageDir());
        if ($module === null || self::commandDisabled()) throw DomainError::serviceUnavailable('KCP TCP/IP 취소 모듈을 실행할 수 없습니다.');
        $tid = (string) ($state['approved']['tid'] ?? '');
        if (!preg_match('/^[0-9]{14}$/D', $tid)) throw DomainError::validation(['refund' => 'KCP 승인 거래번호를 확인해 주세요.']);
        $response = $this->run($module, $config, [
            'tx_cd' => '00200000', 'pa_url' => $config['environment'] === 'test' ? 'testpaygw.kcp.co.kr' : 'paygw.kcp.co.kr',
            'pa_port' => '8090', 'ordr_idxx' => $order['id'],
            'mod_data' => 'tno=' . $tid . "\x1fmod_type=STSC\x1fmod_ip=" . self::clientIp() . "\x1fmod_desc=GNUCMS order refund\x1f",
            'cust_ip' => self::clientIp(), 'trace_no' => '',
        ]);
        if (($response['res_cd'] ?? '') !== '0000' || ($response['tno'] ?? '') !== $tid) {
            throw DomainError::serviceUnavailable('KCP 취소가 확정되지 않았습니다. 결제 상태를 확인해 주세요.');
        }
        return ['id' => self::safeOptional($response['mod_pcan_seq_no'] ?? $tid . '-full', 40),
            'amount' => $amount, 'at' => self::date($response['canc_time'] ?? '') ?: Clock::timestamp()];
    }

    /** @return array{directory:string,binary:string}|null */
    private static function module(string $storageDir): ?array
    {
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Windows'], true)) return null;
        $realStorage = realpath($storageDir);
        $home = rtrim($storageDir, '/\\') . '/payment/kcp_legacy';
        $realHome = realpath($home);
        $binDir = $realHome === false ? false : realpath($realHome . '/bin');
        if ($realStorage === false || $realHome === false || !str_starts_with($realHome, $realStorage . DIRECTORY_SEPARATOR)
            || $binDir === false || !str_starts_with($binDir, $realHome . DIRECTORY_SEPARATOR)
            || !is_dir($binDir) || !is_file($binDir . '/pub.key')) return null;
        $preferred = PHP_OS_FAMILY === 'Windows' ? ['pp_cli_exe.exe']
            : (PHP_INT_SIZE >= 8 ? ['pp_cli_x64', 'pp_cli'] : ['pp_cli']);
        foreach ($preferred as $name) {
            $binary = $binDir . '/' . $name;
            if (is_file($binary) && is_executable($binary)) return ['directory' => $realHome, 'binary' => $binary];
        }
        return null;
    }

    private static function commandDisabled(): bool
    {
        $function = PHP_OS_FAMILY === 'Windows' ? 'proc_open' : 'exec';
        return !function_exists($function)
            || in_array($function, array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    }

    /** pp_cli 인자를 단일 인수로 인용하고 ASCII 필드 구분자를 응답 맵으로 변환한다. */
    private function run(array $module, array $config, array $transaction): array
    {
        // KCP pp_cli may log card-related response fields. Give it a fresh nonexistent path so no transaction log is persisted.
        $logDir = $module['directory'] . '/.disabled-log-' . bin2hex(random_bytes(8));
        $args = ['home' => $module['directory'], 'site_cd' => $config['site_cd'], 'site_key' => $config['site_key'],
            'log_path' => $logDir, 'log_level' => '1', 'opt' => ''] + $transaction;
        foreach ($args as $key => $value) {
            if (!is_string($value) || preg_match('/[\x00-\x1e\x7f,]/', $value)) {
                throw DomainError::validation(['payment' => 'KCP 승인 요청 값을 확인해 주세요.']);
            }
        }
        $pairs = [];
        foreach ($args as $key => $value) $pairs[] = $key . '=' . $value;
        $lines = [];
        $status = 1;
        if (PHP_OS_FAMILY === 'Windows') {
            // Array arguments bypass cmd.exe, so KCP fields cannot be interpreted as shell commands.
            $process = proc_open([$module['binary'], '-h', implode(',', $pairs)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, $module['directory']);
            if (!is_resource($process)) throw DomainError::serviceUnavailable('KCP TCP/IP 승인 모듈을 실행할 수 없습니다.');
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            if (is_string($output)) $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];
        } else {
            $command = escapeshellarg($module['binary']) . ' -h ' . escapeshellarg(implode(',', $pairs)) . ' 2>/dev/null';
            exec($command, $lines, $status);
        }
        if ($status !== 0 || $lines === []) throw DomainError::serviceUnavailable('KCP TCP/IP 승인 모듈에서 응답을 받지 못했습니다.');
        foreach (array_reverse($lines) as $line) {
            if (function_exists('iconv')) $line = iconv('CP949', 'UTF-8//IGNORE', $line) ?: $line;
            $line = str_replace("\x1f", '&', $line);
            $parsed = [];
            parse_str($line, $parsed);
            if (isset($parsed['res_cd']) || isset($parsed['res_msg'])) return $parsed;
        }
        throw DomainError::serviceUnavailable('KCP TCP/IP 응답 형식을 확인할 수 없습니다.');
    }

    private static function token(array $callback, string $name): string
    {
        $value = self::value($callback, $name, 16384);
        // KCP's encrypted values are opaque and must reach pp_cli unchanged. A comma
        // cannot be passed because pp_cli uses it to separate its -h arguments.
        if (str_contains($value, ',')) throw DomainError::validation(['callback' => 'KCP 인증 결과를 확인해 주세요.']);
        return $value;
    }

    private function declined(array $response): never
    {
        $code = is_string($response['res_cd'] ?? null) ? $response['res_cd'] : '';
        $message = is_string($response['res_msg'] ?? null) ? $response['res_msg'] : '';
        $message = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $message) ?? ''), 0, 200, 'UTF-8');
        throw new DomainError('PAYMENT_DECLINED', 'NHN KCP에서 결제를 거절했습니다.', 422,
            ['pg_status' => preg_match('/^[A-Za-z0-9_-]{1,16}$/D', $code) ? $code : '', 'pg_message' => $message]);
    }

    private static function safeOptional(mixed $value, int $max): string
    {
        return is_string($value) && strlen($value) <= $max && !preg_match('/[\x00-\x1f\x7f,]/', $value) ? $value : '';
    }

    private static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : '127.0.0.1';
    }
}
