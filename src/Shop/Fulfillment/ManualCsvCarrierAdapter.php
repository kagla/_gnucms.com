<?php

declare(strict_types=1);

namespace GnuCms\Shop\Fulfillment;

use GnuCms\Error\DomainError;

final class ManualCsvCarrierAdapter implements CarrierAdapter
{
    private const HEADERS = ['주문번호', '택배사', '운송장번호', '받는분', '연락처', '우편번호', '주소', '상세주소', '상품', '배송요청사항'];

    public function id(): string { return 'manual_csv'; }

    public function label(): string { return 'CSV 일괄 처리'; }

    public function export(array $orders): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw DomainError::internal('CSV를 만들 수 없습니다.');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::HEADERS, ',', '"', '');
        foreach ($orders as $order) {
            fputcsv($stream, array_map(self::safeCell(...), [
                $order['number'], '', '', $order['recipient'], $order['recipient_phone'], $order['postcode'],
                $order['address'], $order['address_detail'], implode(' / ', $order['item_summary'] ?? []), $order['delivery_note'],
            ]), ',', '"', '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) throw DomainError::internal('CSV를 만들 수 없습니다.');
        return $contents;
    }

    public function import(string $contents): array
    {
        if ($contents === '' || strlen($contents) > 2 * 1024 * 1024) {
            throw DomainError::validation(['file' => '2MB 이하 CSV 파일을 선택해 주세요.']);
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) $contents = substr($contents, 3);
        if (!mb_check_encoding($contents, 'UTF-8')) throw DomainError::validation(['file' => 'CSV 파일을 UTF-8로 저장해 주세요.']);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw DomainError::internal('CSV를 읽을 수 없습니다.');
        fwrite($stream, $contents); rewind($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        if (!is_array($header)) throw DomainError::validation(['file' => 'CSV 머리글이 없습니다.']);
        $positions = [];
        foreach ($header as $index => $name) $positions[trim((string) $name)] = $index;
        foreach (array_slice(self::HEADERS, 0, 3) as $required) {
            if (!array_key_exists($required, $positions)) throw DomainError::validation(['file' => 'CSV 머리글에 ' . $required . ' 항목이 필요합니다.']);
        }
        $rows = []; $line = 1;
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $line++;
            if ($line > 5001) throw DomainError::validation(['file' => '한 번에 5,000건까지 처리할 수 있습니다.']);
            if ($row === [null] || implode('', array_map('strval', $row)) === '') continue;
            $read = static fn (string $name): string => trim((string) ($row[$positions[$name]] ?? ''));
            $number = ltrim($read('주문번호'), "'");
            $carrier = ltrim($read('택배사'), "'");
            $tracking = ltrim($read('운송장번호'), "'");
            if ($number === '' || $carrier === '' || $tracking === '') {
                throw DomainError::validation(['file' => $line . '행의 주문번호·택배사·운송장번호를 모두 입력해 주세요.']);
            }
            if (mb_strlen($number) > 32 || mb_strlen($carrier) > 100 || preg_match('/^[A-Za-z0-9-]{4,100}$/D', $tracking) !== 1) {
                throw DomainError::validation(['file' => $line . '행의 주문번호·택배사·운송장번호 형식을 확인해 주세요.']);
            }
            if (isset($rows[$number])) throw DomainError::validation(['file' => $line . '행의 주문번호가 앞 행과 중복됩니다.']);
            $rows[$number] = ['number' => $number, 'carrier' => $carrier, 'tracking_number' => $tracking];
        }
        fclose($stream);
        if ($rows === []) throw DomainError::validation(['file' => '처리할 운송장 자료가 없습니다.']);
        return array_values($rows);
    }

    private static function safeCell(mixed $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        return preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'" . $value : $value;
    }
}
