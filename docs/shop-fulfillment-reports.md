# 쇼핑몰 배송·매출·정산 운영

## 처리 순서

1. 주문 상세에서 결제 완료 주문을 `상품 준비`로 바꾼다.
2. `주문 → 배송 작업`에서 주문을 선택해 피킹·포장 명세를 인쇄하거나 택배 CSV를 내려받는다.
3. 택배사 프로그램에서 송장을 발급한 뒤 CSV의 `택배사`, `운송장번호`를 채운다.
4. CSV를 다시 올리면 모든 행을 검증한 뒤 주문을 한 트랜잭션으로 `배송 중`으로 바꾼다.
5. `주문 → 매출 리포트`에서 결제액, 환불액, 과세·면세 순매출을 확인한다.
6. `주문 → 정산 대사`에서 표준 CSV를 내려받아 PG 정산 자료를 맞춘 뒤 가져온다.

배송 CSV는 UTF-8이며 한 번에 5,000행, 선택 출력은 한 번에 500건까지 처리한다. 택배사 이름은 `config/shop_carriers.json` 또는 기본 샘플 목록과 일치해야 한다. 행 하나라도 주문번호, 상태, 택배사, 운송장번호가 잘못되면 아무 주문도 변경하지 않는다.

## 매출 집계 기준

- 결제액과 주문 건수는 주문의 `paid_at`이 조회 기간에 속한 금액이다.
- 환불액은 건별 환불 원장의 `created_at`이 조회 기간에 속한 금액이다.
- 순매출은 `기간 내 결제액 - 기간 내 환불액`이다. 이전 기간 주문의 환불이 현재 기간에 발생하면 현재 기간 순매출에서 빠진다.
- 과세 공급가, 부가세, 면세액도 같은 방식으로 환불 원장의 세금 명세를 뺀다.
- 상품·분류별 표는 결제 당시 주문 상품을 집계한다. 부분 환불에 상품별 배분 정보가 없으므로 환불액을 상품에 임의 배분하지 않는다.
- 주문 상품에는 분류 스냅샷이 없으므로 분류별 표는 조회할 때의 대표 분류를 사용한다.
- 스키마 48 이전의 누적 환불은 당시 건별 처리 시각이 없으므로 마이그레이션 시 주문의 마지막 수정일에 한 건의 이전 원장으로 보존한다.

## 정산 공통 형식

정산 어댑터의 표준 열은 다음과 같다.

| 열 | 내용 |
|---|---|
| `provider` | GNUCMS 결제사 ID |
| `environment` | `test` 또는 `live` |
| `merchant_id` | PG 상점 ID, 없으면 빈 값 |
| `payment_id` | GNUCMS 결제 원장 키 또는 주문번호 |
| `transaction_key` | PG가 발급한 정산 거래 고유 키 |
| `kind` | `payment` 또는 `refund` |
| `amount` | 결제는 양수, 환불은 음수 |
| `fee_supply`, `fee_vat` | 수수료 공급가와 부가세 |
| `payout_amount` | 실제 지급 예정액 |
| `sold_date`, `payout_date` | 매출일과 지급일, `YYYY-MM-DD` |

같은 `provider + environment + merchant_id + transaction_key`는 한 번만 저장한다. 같은 키의 내용이 다르면 덮어쓰지 않고 오류로 막는다. 대사 상태는 주문 순결제액과 정산 거래액 합계가 같으면 `일치`, 자료가 없으면 `정산 없음`, 금액이 다르면 `불일치`, 주문을 찾지 못하면 `주문 없음`이다.

PG별 API 연동은 `GnuCms\Payment\SettlementAdapter` 계약에 구현을 추가한다. 각 결제사의 정산 권한과 운영 인증 정보가 필요하다.

## 참고 자료

- 영카트 주문서 출력: <https://github.com/gnuboard/gnuboard5/blob/master/adm/shop_admin/orderprint.php>
- 영카트 배송 일괄 처리: <https://github.com/gnuboard/gnuboard5/blob/master/adm/shop_admin/orderdelivery.php>
- 한진 API 연동 절차: <https://developers.hanjin.com/guides>
- 한진 운송장 출력 규격: <https://developers.hanjin.com/printwbl>
- 토스페이먼츠 정산 API: <https://docs.tosspayments.com/reference>
- 국세청 부가가치세 안내: <https://g.nts.go.kr/nts/cm/cntnts/cntntsView.do?cntntsId=7670&mi=2231>
