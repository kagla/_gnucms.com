# 공통 결제 계층 (설정 → 결제)

GNUCMS가 PG와 직접 연동한다. 포트원 계정이나 API를 거치지 않는다. 쇼핑몰은 공통
`Gateway` 계약으로 결제창·승인·조회·전액/부분 환불·미확정 환불 대조를 호출하며,
각 PG의 전문과 인증은 해당 어댑터가 처리한다. **현재 실제 제공하는 어댑터는 KG이니시스
카드 결제 하나다.** 다른 회사는 어댑터를 구현하고 검증한 뒤 등록해야 한다.
무통장입금은 쇼핑몰에서 별도로 관리한다. 계좌이체·가상계좌·간편결제는 아직 지원하지 않는다.

## 관리자 사용 순서

1. **설정 → 결제**(`/admin/settings/payment`)에서 PG와 테스트/운영 환경을 고른다.
2. PG가 발급한 상점 정보와 인증키를 저장하고 해당 환경의 API 실행을 허용한다.
3. **쇼핑몰 → 설정 → 결제**에서 새 주문에 사용할 온라인 결제사와 환경을 선택한다.

설정 화면에는 등록된 어댑터만 표시한다. PG를 변경해도 기존 주문의 결제사·환경·설정 판은
바뀌지 않는다. 과거 주문의 콜백, 결제창 재진입, 조회, 환불은 원래 PG로 전달한다.
기존 주문을 처리해야 하는 PG는 어댑터를 제거하거나 실행을 정지하지 않아야 한다.
알 수 없는 PG를 임의로 다른 PG로 바꾸지 않고 오류로 처리한다.
관리자 주문 상세에서 해당 주문의 결제사를 확인할 수 있다.

설정 저장만으로 결제창이 열리지는 않는다. PG별·환경별로 실행을 허용해야 한다.
설정을 다시 저장하면 실행 허용이 해제된다. 백업 복원 뒤에도 다시 허용해야 한다.
백업·복원 중에는 결제 실행과 설정 변경을 잠근다.

## 이니시스 준비물

| 항목 | 설명 |
|---|---|
| 상점 아이디 (MID) | 영문·숫자 10자 |
| 웹표준 결제 SignKey | PC 결제창 서명 |
| 모바일 금액 위변조 Hash Key | 모바일 결제창 서명 |
| INIAPI Key | 승인 조회·환불 API |
| 결제 요청 서버 IPv4 주소 | 이니시스에 등록한 이 서버의 공인 IP |

사이트 주소(`app.url`)는 공개 HTTPS 주소여야 한다. 인증키는 암호화해 저장하고 화면에
다시 보여주지 않는다. 설정을 저장할 때마다 새 판(revision)을 보존한다. 이니시스는 같은
상점의 인증키와 서버 IP가 바뀌면 과거 주문에도 최신 값을 사용하고, 상점 ID가 바뀌면
과거 주문의 상점 정보를 보존한다. 다른 PG의 키 교체 규칙은 그 PG의 `credentials()`가 정한다.

PG 실계정 승인·조회·환불은 별도 검증이 필요하다. 자동 테스트는 외부 요청을 모의하며
실제 카드 결제나 송금을 실행하지 않는다.

## 실행 구조와 파일

```text
쇼핑몰 주문·관리자
  → src/Shop/Commerce/Payments.php
  → App::paymentGateway(주문에 저장된 payment_provider)
  → ProviderRegistry → PG별 Gateway
  → PG API
```

| 위치 | 책임 |
|---|---|
| `src/Payment/Provider.php`, `ProviderRegistry.php` | PG 등록, 설정 항목·검증, 지원 수단·부분 환불 여부, 결제창 템플릿, 게이트웨이 생성 |
| `src/Payment/Gateway.php` | 승인·조회·환불·미확정 처리의 서버 계약 |
| `src/Payment/DirectGateway.php` | 승인/환불 중복 전송 방지, 원장 상태, 환불 대조 공통 구현 |
| `src/Payment/Settings.php`, `Journal.php` | PG별 암호화 설정 판·원장·실행 허용 |
| `src/Payment/InicisProvider.php`, `ProviderConfig.php` | 이니시스 설정 항목·검증·키 교체 정책 |
| `src/Payment/InicisGateway.php`, `StreamTransport.php` | 이니시스 전문·통신·허용 API 주소·응답 검증 |
| `templates/default/admin/payment_settings.php` | 모든 등록 PG가 사용하는 설정 화면 |
| `templates/default/shop/pay.php` | 공통 결제 페이지 |
| `templates/default/payment/inicis.php`, `inicis_scripts.php` | 이니시스 PC/모바일 결제창 조각과 실행 스크립트 |

결제창 조각도 기존 테마 경로 탐색을 사용한다. `templates/<테마>/payment/inicis.php`와
`inicis_scripts.php`로 재정의할 수 있다. 쇼핑몰 전용 재정의는
`templates/<테마>/shop/payment/`에 둔다. 기존 `shop/pay.php` 전체 재정의도 유지된다.

## 새 PG 추가

신뢰하는 서버 초기화 코드에서 다음처럼 등록한다. 사용자 입력으로 클래스를 생성하거나
템플릿 파일 경로를 지정하지 않는다.

```php
$app->paymentProviders()->register(new YourProvider());
```

`YourProvider`는 `Provider`를 구현한다. 고유 ID(영문 소문자로 시작, 영문 소문자·숫자·밑줄,
최대 32자), 표시명, 설정 항목과 검증, 가맹점별 키 교체 정책, 지원 수단, 부분 환불 여부,
결제창 조각 이름을 선언하고 `gateway(Settings $settings)`에서 PG 게이트웨이를 반환한다.
중복 ID 등록은 거절한다. 현재 쇼핑몰은 선언된 수단 중 `card`만 노출한다.

게이트웨이는 `DirectGateway`를 상속하고 다음 PG별 부분을 구현한다.

- `checkout()`: `prepare()`로 주문·설정·리턴 주소를 확인한 뒤 결제창 데이터를 만든다.
- `validateCallback()`: 인증 결과와 주문 연결을 확인한다.
- `approve()`, `query()`, `refund()`: PG 규격으로 요청하고 공통 결과로 변환한다.
- `query()`는 `status`, `valid`, `transaction_id`, `paid_at`, `cancelled`, 선택적
  `cancellations`를 돌려준다. `valid`는 PG에서 조회한 상점·주문·금액·통화 등이 모두
  주문과 일치할 때만 참이다. 취소 항목은 `id`, 정수 원 단위 `amount`, Unix 초 `at`이다.
- `refund()`는 확정된 취소의 `id`, `amount`, `at`를 반환한다. 응답이 불확실하면 예외를
  내서 원장의 보류 상태를 유지한다. 승인·환불 타임아웃을 성공이나 미처리로 추측하지 않는다.

현재 `StreamTransport`는 이니시스 주소만 허용한다. 새 PG는 `Transport` 구현을 주입해
그 PG의 공식 HTTPS 주소·응답 형식·인증을 검증한다. 임의 콜백 URL로 인증키를 보내거나,
리다이렉트를 따라가거나, TLS 검증을 끄지 않는다. 필요하면 PG별 콜백/웹훅 인증과 경로도
추가한다. 브라우저 인증 콜백과 비동기 입금 통보는 같은 계약이 아니며, 가상계좌 등을
지원하려면 주문 상태 처리까지 확장해야 한다.

PG별 프로토콜은 각각 구현해야 한다. 이 구조는 서로 다른 PG를 설정 이름만 바꿔 연결하는
방식이 아니라, 반복되는 주문·설정·원장·관리 화면을 공유하는 방식이다.
`tests/Payment/TestProvider.php`는 실제 연동에 사용하지 않는 모의 어댑터 예다.

## 데이터와 업그레이드

코어 DB 스키마 32판은 다음을 추가한다. SQLite와 MySQL/MariaDB 모두 같은 구조다.

- `pay_settings`: `(provider, id)` 기본키, 암호화 `payload`. ID는 환경 또는 설정 판이다.
- `pay_transactions`: `(provider, id)` 기본키, 암호화 `payload`. ID는 결제 원장 키다.
- `yc_orders.payment_provider`: 주문이 생성될 때 선택된 PG. 무통장·접수 전용은 빈 값이다.

기존 `pay_inicis_settings`, `pay_inicis_transactions`의 암호문은 `provider=inicis`로
복사한다. 과거 온라인 결제 주문에도 이니시스를 기록한다. 원래 설정 판·결제 ID·콜백 HMAC·
미확정 승인/환불 기록을 유지한다. 재실행해도 공통 표의 더 최신 기록을 덮어쓰지 않는다.
이전 두 표는 보관·백업만 하며 실행에서는 읽지 않는다. 새 설치에도 빈 보관 표를 만들어
동일한 백업/복원 표 목록을 유지한다.

`App::paymentSettings()`의 기본 PG와 기존 `inicisGateway()`/`setInicisGateway()` 호출은
호환용으로 남아 있다. 새 코드는 PG ID를 전달하는 `paymentSettings($id)`와
`paymentGateway($id)`를 사용한다.
