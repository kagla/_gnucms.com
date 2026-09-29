<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use DateTimeImmutable;
use GnuCms\Error\DomainError;

final class ManualSettlementAdapter implements SettlementAdapter
{
    public const HEADERS = ['provider', 'environment', 'merchant_id', 'payment_id', 'transaction_key', 'kind', 'amount',
        'fee_supply', 'fee_vat', 'payout_amount', 'sold_date', 'payout_date'];

    public function __construct(private ProviderRegistry $providers) {}

    public function id(): string { return 'normalized_csv'; }

    public function template(): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw DomainError::internal('CSV를 만들 수 없습니다.');
        fwrite($stream, "\xEF\xBB\xBF"); fputcsv($stream, self::HEADERS, ',', '"', '');
        rewind($stream); $contents = stream_get_contents($stream); fclose($stream);
        if ($contents === false) throw DomainError::internal('CSV를 만들 수 없습니다.');
        return $contents;
    }

    public function parse(string $contents): array
    {
        if ($contents === '' || strlen($contents) > 5 * 1024 * 1024) throw DomainError::validation(['file' => '5MB 이하 CSV 파일을 선택해 주세요.']);
        if (str_starts_with($contents, "\xEF\xBB\xBF")) $contents = substr($contents, 3);
        if (!mb_check_encoding($contents, 'UTF-8')) throw DomainError::validation(['file' => 'CSV 파일을 UTF-8로 저장해 주세요.']);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw DomainError::internal('CSV를 읽을 수 없습니다.');
        fwrite($stream, $contents); rewind($stream); $header = fgetcsv($stream, null, ',', '"', '');
        if (!is_array($header) || array_map('trim', $header) !== self::HEADERS) {
            throw DomainError::validation(['file' => '정산 CSV 머리글이 표준 양식과 다릅니다. 양식을 내려받아 작성해 주세요.']);
        }
        $providerIds = array_keys($this->providers->labels());
        $rows = []; $keys = []; $line = 1;
        while (($fields = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $line++;
            if ($line > 10001) throw DomainError::validation(['file' => '한 번에 10,000건까지 가져올 수 있습니다.']);
            if ($fields === [null] || implode('', array_map('strval', $fields)) === '') continue;
            $row = array_combine(self::HEADERS, array_pad(array_slice($fields, 0, count(self::HEADERS)), count(self::HEADERS), ''));
            if (!is_array($row)) throw DomainError::validation(['file' => $line . '행을 읽을 수 없습니다.']);
            foreach ($row as &$value) $value = trim((string) $value); unset($value);
            if (!in_array($row['provider'], $providerIds, true)) throw DomainError::validation(['file' => $line . '행의 provider를 확인해 주세요.']);
            if (!in_array($row['environment'], ['test', 'live'], true)) throw DomainError::validation(['file' => $line . '행의 environment는 test 또는 live여야 합니다.']);
            if (!in_array($row['kind'], ['payment', 'refund'], true)) throw DomainError::validation(['file' => $line . '행의 kind는 payment 또는 refund여야 합니다.']);
            foreach (['merchant_id' => 64, 'payment_id' => 191, 'transaction_key' => 191] as $field => $max) {
                if (($field !== 'merchant_id' && $row[$field] === '') || mb_strlen($row[$field]) > $max || preg_match('/[\x00-\x1f\x7f]/u', $row[$field])) {
                    throw DomainError::validation(['file' => $line . '행의 ' . $field . ' 값을 확인해 주세요.']);
                }
            }
            foreach (['amount', 'fee_supply', 'fee_vat', 'payout_amount'] as $field) {
                if (preg_match('/^-?[0-9]{1,12}$/D', $row[$field]) !== 1) throw DomainError::validation(['file' => $line . '행의 ' . $field . ' 금액을 확인해 주세요.']);
                $row[$field] = (int) $row[$field];
            }
            if (($row['kind'] === 'payment' && $row['amount'] <= 0) || ($row['kind'] === 'refund' && $row['amount'] >= 0)) {
                throw DomainError::validation(['file' => $line . '행은 결제 금액을 양수, 환불 금액을 음수로 입력해 주세요.']);
            }
            if (!$this->validDate($row['sold_date']) || ($row['payout_date'] !== '' && !$this->validDate($row['payout_date']))) {
                throw DomainError::validation(['file' => $line . '행의 날짜를 YYYY-MM-DD 형식으로 입력해 주세요.']);
            }
            $unique = implode('|', [$row['provider'], $row['environment'], $row['merchant_id'], $row['transaction_key']]);
            if (isset($keys[$unique])) throw DomainError::validation(['file' => $line . '행의 거래 키가 앞 행과 중복됩니다.']);
            $keys[$unique] = true; $rows[] = $row;
        }
        fclose($stream);
        if ($rows === []) throw DomainError::validation(['file' => '가져올 정산 자료가 없습니다.']);
        return $rows;
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
