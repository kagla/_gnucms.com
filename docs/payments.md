# 공통 결제 계층 (설정 → 결제)

GNUCMS가 PG와 직접 연동한다. 포트원 계정이나 API를 거치지 않는다. 쇼핑몰은 공통
`Gateway` 계약으로 결제창·승인·조회·전액/부분 환불·미확정 환불 대조를 호출하며,
각 PG의 전문과 인증은 해당 어댑터가 처리한다. KG이니시스는 카드, 실시간 계좌이체,
가상계좌, 휴대폰 결제를 제공하고 NHN KCP REST API 방식은 카드, 실시간 계좌이체, 휴대폰 결제를 제공한다.
토스페이먼츠와 나이스페이먼츠도 카드·실시간 계좌이체·휴대폰 결제를 제공하며 일반결제 또는
계좌이체 에스크로를 선택할 수 있다. 두 결제사는 결제창 인증 뒤 서버 승인·거래조회·전액/부분
취소를 연결한다. 가상계좌는 비동기 입금 통보 연동 전까지 두 결제사의 주문서에 표시하지 않는다.
NHN KCP 기존 방식(TCP/IP)은 일반결제 또는 에스크로 결제 중 하나를 선택해 사이트 코드·사이트 키를 등록할 수 있으며 결제 실행은 아직 연결되지 않았다.
상점 MID에 각 수단 계약이 완료된 뒤 **쇼핑몰 설정 → 결제**에서
수단을 켠다. 이니시스 가상계좌는 발급·입금을 분리하고 `/shop/pay/callback`의 입금 통보를 거래 조회로
검증해 결제 완료로 처리한다. 가상계좌·휴대폰 결제 환불은 이니시스 관리자에서 처리한 뒤 주문의
**결제 조회**로 반영한다. 무통장입금은 쇼핑몰에서 별도로 관리한다. 토스·나이스페이의
에스크로 배송 정보는 각 결제사 상점관리자에서 등록한다.
에스크로 계좌이체의 부분 취소는 두 결제사의 구매 확정 전 제한에 따라 쇼핑몰에서 막고,
전액 취소만 요청한다. 토스 일반결제 모드에서 구매자가 결제창에서 에스크로를 선택한
경우에도 실제 거래의 에스크로 여부를 주문에 저장해 배송 등록 안내를 표시한다.

## 관리자 사용 순서

토스 결제창은 [SDK v1 결제수단별 파라미터](https://docs.tosspayments.com/sdk/payment-js)의
`useEscrow`와 `escrowProducts`를 사용하며, [결제 API](https://docs.tosspayments.com/reference)의
승인·조회·취소를 사용한다. 토스는 에스크로 배송 정보를
[상점관리자 또는 API](https://docs.tosspayments.com/resources/glossary/escrow)로 등록할 수 있다.
현재 결제수단별 화면은 SDK v1에 맞춰 구현되어 있다. 토스는 v1의 업데이트를 종료하고
v2 사용을 권장하므로, SDK를 전환할 때 결제창 호출과 에스크로 파라미터를 함께 갱신해야 한다.
나이스페이는 [결제창 서버 승인 모델](https://github.com/nicepayments/nicepay-manual/blob/main/api/payment-window-server.md)의
`useEscrow`와 [거래조회](https://github.com/nicepayments/nicepay-manual/blob/main/api/status-transaction.md),
[취소 API](https://github.com/nicepayments/nicepay-manual/blob/main/api/cancel.md)를 사용한다.
나이스페이 에스크로 배송 정보는 [가맹점관리자](https://start.nicepay.co.kr/homepage/service/escrow.do)에 등록한다.
두 결제사 모두 현금성 에스크로는 별도 계약 및 결제수단 허용 설정이 필요하다.

1. **쇼핑몰 → 설정 → 결제**(`/admin/shop/settings#settings-payment`)에서 새 주문에 사용할 PG·결제 환경·결제 수단을 선택한다. 각 PG에서 일반결제·에스크로 결제 중 하나를 고른다. 이니시스 운영 환경은 발급받은 MID·키·서버 IP를 입력하고 테스트 환경은 공용 테스트 정보가 자동 적용된다. KCP 기존 방식은 운영 사이트 코드·사이트 키 한 쌍을 등록하며 테스트 환경은 선택한 방식의 공용 테스트 정보를 자동 적용한다. KCP REST API 방식은 사이트 코드·인증서·개인키·비밀번호를 입력한다. REST API 테스트 사이트 코드는 일반결제 `T0000`, 에스크로 결제 `T0007`이다. 토스·나이스페이는 환경별 클라이언트 키와 서버 시크릿 키를 입력한다.
2. 화면 하단의 **설정 저장**을 한 번 누른다. 이니시스와 KCP REST API 방식은 선택한 환경의 연동 설정이 저장되어 있으면 주문서에 현재 지원하는 결제 수단이 표시된다. KCP 기존 방식은 자격정보를 저장하되 온라인 결제 수단을 노출하지 않는다.

이니시스·KCP의 에스크로 계좌이체·가상계좌 요청, 승인 확인, 배송 상태 처리는 아직 연결되지 않았다. 해당 방식을
선택한 환경에서는 두 수단을 주문서에서 숨기고 결제창 직접 요청도 거절한다. 토스·나이스페이는
계좌이체 에스크로를 결제창에 요청한다. 카드·휴대폰 결제에는
에스크로가 적용되지 않으므로 기존 결제 흐름을 사용한다. 기존 일반결제 주문은 저장 당시의 설정 판으로 처리한다.

설정 화면에는 등록된 어댑터만 표시한다. PG를 변경해도 기존 주문의 결제사·환경·설정 판은
바뀌지 않는다. 과거 주문의 콜백, 결제창 재진입, 조회, 환불은 원래 PG로 전달한다.
기존 주문을 처리해야 하는 PG는 어댑터를 제거하거나 실행을 정지하지 않아야 한다.
알 수 없는 PG를 임의로 다른 PG로 바꾸지 않고 오류로 처리한다.
관리자 주문 상세에서 해당 주문의 결제사를 확인할 수 있다.

PG 거절과 승인 결과 확인 보류 기록은 쇼핑몰 관리자 → 주문 → 결제 오류에서 확인한다.
목록은 결제 참조번호·PG 응답 코드로 검색하고 20건씩 페이지를 나눈다. 주문자 정보와
PG 결과 메시지는 결제 원장 안에서 암호화한다. 검색·페이징은 별도 메타데이터로 처리하고 화면에
표시할 현재 페이지의 원장만 복호화한다. 기존 원장은 화면에 진입할 때 한 번에 최대 500건씩
메타데이터를 보완한다. 승인 완료 뒤 주문 접수가 보류된 가상계좌는 이 화면에서 거래를 다시
조회해 주문 접수를 복구할 수 있다. 가상계좌 번호·예금주는 승인 응답 원문에서 검증해 사용한다.

운영 또는 테스트 선택이 새 주문의 결제 환경을 정한다. 별도의 실행 허용 단계는 없다.
설정이 없는 환경에는 온라인 결제 수단을 표시하지 않는다. 백업·복원 중에는 결제 실행과 설정 변경을 잠근다.

## 이니시스 준비물

공용 테스트 상점 MID는 `INIpayTest`다. 테스트 환경을 선택해 쇼핑몰 설정을 저장하면
[KG이니시스 PayPro 안내](https://manual.inicis.com/inipaypro/)와
[INIAPI 샘플](https://manual.inicis.com/download/TLS12_test_manual.pdf)의 공개 테스트 값을
서버에서 자동으로 저장한다. 테스트 인증키는 관리자 화면에 표시하거나 입력받지 않는다.
테스트 API의 기본 `clientIp`는 `127.0.0.1`이며 필요하면 서버별
`config/config.php`의 `payment.inicis.client_ip`로 요청 주소를 지정한다.
운영 환경에는 해당 상점에 발급된 아래 값을 입력한다.

| 항목 | 설명 |
|---|---|
| 상점 아이디 (MID) | 영문·숫자 10자 |
| 웹표준 결제 SignKey | 기존 웹표준 결제 건의 승인 호환용. 신규 PayPro 설정만 할 때는 비워 둔다 |
| 모바일 금액 위변조 Hash Key | PayPro 결제창의 금액 위변조 검증 |
| INIAPI Key | 승인 조회·환불 API |
| 기본 요청 서버 IPv4 주소 | 별도 서버 설정이 없을 때 INIAPI 요청의 `clientIp`에 넣는 주소 |

INIAPI 요청 한 건에는 IPv4 주소 하나만 보낸다. 여러 서버가 같은 DB를 사용하면 각 서버의
`config/config.php`에 `payment.inicis.client_ip`를 해당 서버의 요청 IPv4로 지정한다.
이 로컬 설정이 관리자 기본값보다 우선하며, 비어 있으면 기본값을 사용한다. 프록시나 NAT를
사용한다면 서버가 실제로 어떤 주소로 요청하는지 확인해 설정한다. 방문자 IP나
`X-Forwarded-For` 헤더를 이 값으로 사용하지 않는다.

사이트 주소(`app.url`)는 공개 HTTPS 주소여야 한다. 인증키는 암호화해 저장하고 화면에
다시 보여주지 않는다. 설정을 저장할 때마다 새 판(revision)을 보존한다. 이니시스는 같은
상점의 인증키와 서버 IP가 바뀌면 과거 주문에도 최신 값을 사용하고, 상점 ID가 바뀌면
과거 주문의 상점 정보를 보존한다. 다른 PG의 키 교체 규칙은 그 PG의 `credentials()`가 정한다.
신규 카드 결제는 PC·모바일 모두 이니시스 PayPro의 `INIPayPro_v2.js`로 요청한다.
주문서의 구매자 이메일과 연락처는 `P_RESERVED` JSON의 `email`·`phonenum` 항목으로 전달한다.
PayPro JS의 결과 콜백을 사용해 인증 결과를 쇼핑몰의 서명된 콜백 주소로 POST한다.
`P_CLOSE_URL`은 성공 후 이동을 덮어쓸 수 있으므로 새 PayPro 요청에는 넣지 않는다.
인증 실패(`P_STATUS`가 `00`이 아님)는 승인 API를 호출하지 않고 오류 코드만 표시한다.
실패한 임시 주문서는 `declined`로 기록하며, 다시 시도할 때는 새 PG 주문번호를 발급한다.
승인 요청이나 결과 확인에서 실패한 경우에는 결제 여부가 불확실하므로 재결제를 권하지 않는다.
인증 결과에 포함된 IDC 코드로 스테이징·운영 승인 서버를 선택한다. 이전 웹표준 결제창에서
진행 중이던 건은 기존 콜백 형식도 처리한다.
PayPro의 IDC는 주문의 테스트·운영 설정과 별도로 PG가 반환한 `fc`·`ks`·`stg` 중 하나를
사용한다. 반환값을 그대로 호스트명에 넣지 않고 이 세 값만 허용한다. 승인 요청 전후로 실패하면
사용자에게 애플리케이션 오류 코드와 결제 참조번호를 표시해 원장·로그를 대조할 수 있게 한다.
결제 완료 주문에는 거래조회가 제공한 카드사명, 마스킹 카드 끝번호, 일시불·할부 개월,
무이자 여부와 승인번호를 저장해 주문 상세에 표시한다. 원 카드번호는 저장하지 않는다.
개인·법인 구분은 일반 카드 거래조회 응답에 포함되지 않으므로 임의로 표시하지 않는다.
주문 상세의 **카드 매출전표 보기**는 이니시스 영수증 조회로 연결하며, 영수증 페이지에서
구매자명과 결제 금액을 확인해야 한다.

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
| `src/Payment/Settings.php`, `Journal.php` | PG별 암호화 설정 판·원장 |
| `src/Payment/InicisProvider.php`, `ProviderConfig.php` | 이니시스 설정 항목·검증·키 교체 정책 |
| `src/Payment/InicisGateway.php`, `KcpGateway.php`, `StreamTransport.php` | 이니시스·KCP 전문·통신·허용 API 주소·응답 검증 |
| `templates/default/admin/payment_settings.php` | 모든 등록 PG가 사용하는 설정 화면 |
| `src/Shop/Commerce/CheckoutIntents.php` | PG 승인 전 암호화 임시 주문서와 승인 후 주문 확정·접수 실패 시 취소 |
| `templates/default/shop/checkout.php` | 새 PG 주문의 결제창을 주문서 안에 표시 |
| `templates/default/shop/pay.php` | 이전 결제 대기 주문의 결제 재시도 화면 |
| `templates/default/payment/inicis.php`, `inicis_scripts.php` | 이니시스 PC/모바일 결제창 조각과 실행 스크립트 |
| `templates/default/payment/kcp.php`, `kcp_scripts.php`, `www/themes/default/kcp.js` | KCP PC/모바일 결제창 조각과 콜백 실행 스크립트 |

결제창 조각도 기존 테마 경로 탐색을 사용한다. `templates/<테마>/payment/inicis.php`,
`inicis_scripts.php`, `kcp.php`, `kcp_scripts.php`로 재정의할 수 있다. 쇼핑몰 전용 재정의는
`templates/<테마>/shop/payment/`에 둔다. 기존 `shop/pay.php` 전체 재정의도 유지된다.

이니시스는 상점 MID 계약이 허용한 카드, 실시간 계좌이체, 가상계좌, 휴대폰 결제를 지원한다.
KCP 표준결제는 상점 계약이 허용한 카드, 실시간 계좌이체, 휴대폰 결제를 지원한다. 쇼핑몰 설정에서
각 수단을 켜야 주문서에 표시된다. KCP 가상계좌는 별도 발급 API와 입금 웹훅을 사용하므로 아직 주문서에
노출하지 않는다. 이니시스 가상계좌는 입금 기한과 입금 통보를 처리하고,
환불은 이니시스 관리자에서 실행한 뒤 쇼핑몰 주문의 결제 조회로 반영한다. 휴대폰 결제 환불도
이니시스 관리자에서 실행하고 결제 조회로 반영한다.

## NHN KCP 기존 방식 (TCP/IP) 등록

쇼핑몰 설정 → 결제에서 **NHN KCP 기존 방식 (TCP/IP)**을 선택하고 **일반결제 / 에스크로 결제** 중 사용할 방식을 고른다.
운영 환경은 선택한 방식으로 KCP에서 받은 `site_cd`와 `site_key` 한 쌍을 입력한다. 테스트 환경에서는 일반결제는
`T0000`, 에스크로 결제는 `T0007`이 보이며 선택한 방식의 공용 사이트 키가 하단의 설정 저장 시 자동 적용된다.
사이트 설정 → 결제에서도 같은 방식으로 자격정보를 등록할 수 있다. 값은 REST API 방식과 별도 암호화 설정으로 저장된다.
운영 결제 방식과 사이트 코드가 같으면 사이트 키를 비워 두어도 저장된 키가 유지된다. 방식을 변경하면 새 방식의
운영 사이트 코드와 사이트 키를 입력해야 한다.
KCP는 기존 pp_cli 방식에서 사이트 코드와 사이트 키를 사용한다고 [REST API 전환 가이드](https://developer.kcp.co.kr/guide/rest-api-guide)에 안내한다.
TCP/IP 결제 승인·조회·취소 모듈은 아직 연결되지 않았으므로 이 방식을 선택하면 주문서에 온라인 결제 수단이 표시되지 않는다.
쇼핑몰의 별도 무통장입금 수단은 KCP 에스크로를 이용하지 않는다.
KCP의 [에스크로 가이드](https://developer.kcp.co.kr/page/document/escrow)에 따라 에스크로 적용 대상은
계좌이체·가상계좌다. 카드·휴대폰 결제에는 에스크로가 적용되지 않는다. 실제 에스크로 결제에는 결제창 요청,
승인 응답의 `escw_yn` 확인, 배송 시작 상태 변경과 웹훅 처리가 추가로 필요하다.

## NHN KCP REST API 방식 설정

쇼핑몰 설정 → 결제에서 **NHN KCP REST API 방식**을 선택하면 테스트·운영 환경별 입력란이 나타난다. 하단의 설정 저장으로
쇼핑몰 설정과 선택한 환경의 KCP 자격정보를 함께 저장한다. 사이트 설정 → 결제의 REST API 방식 탭에서도
KCP 자격정보를 관리할 수 있다. 테스트 사이트 코드는 일반결제 `T0000`, 에스크로 결제 `T0007`로 자동 선택하고,
운영에는 선택한 방식으로 KCP가 발급한 5자리 사이트 코드를 사용한다. 각 환경의 서비스 인증서 PEM, 개인키 PEM,
개인키 비밀번호가 필요하다. 인증서와 개인키는 암호화해 보관한다.

PC는 KCP 표준결제 허브를, 모바일은 [거래등록 API](https://developer.kcp.co.kr/reference/regist)가 반환한 PayUrl과 인증 키를 사용한다.
인증 후에는 서버에서 [결제승인 API](https://developer.kcp.co.kr/reference/pay-approve)를 호출하고, 거래조회 결과로 주문번호·거래번호·수단·금액·잔액을 검증한다.
[공식 거래조회 Guide](https://developer.kcp.co.kr/reference/search)는 조회 서명 문자열로 `site_cd^tno^pay_type`을 안내하지만 API Reference의 서명 파라미터 설명은
`site_cd^tno^mod_type`을 적고 있다. 구현은 Guide에 요청 예시가 있는 `pay_type` 규칙을 사용한다. 실제 KCP 테스트 계정으로
조회·취소를 검증해야 한다. 거래조회 응답에는 취소별 이력이 없어 원장에 저장한 GNUCMS 취소와 PG 잔액 합계가
일치할 때만 조회 결과를 유효하게 처리한다. 전액·부분 취소는 [거래취소 API](https://developer.kcp.co.kr/reference/cancel)를 호출한다.

## 새 PG 추가

신뢰하는 서버 초기화 코드에서 다음처럼 등록한다. 사용자 입력으로 클래스를 생성하거나
템플릿 파일 경로를 지정하지 않는다.

```php
$app->paymentProviders()->register(new YourProvider());
```

`YourProvider`는 `Provider`를 구현한다. 고유 ID(영문 소문자로 시작, 영문 소문자·숫자·밑줄,
최대 32자), 표시명, 설정 항목과 검증, 가맹점별 키 교체 정책, 지원 수단, 부분 환불 여부,
결제창 조각 이름을 선언하고 `gateway(Settings $settings)`에서 PG 게이트웨이를 반환한다.
중복 ID 등록은 거절한다. 쇼핑몰은 PG가 선언한 수단 중 운영자가 설정에서 켠 수단을 주문서에 노출한다.

게이트웨이는 `DirectGateway`를 상속하고 다음 PG별 부분을 구현한다.

- `checkout()`: `prepare()`로 주문·설정·리턴 주소를 확인한 뒤 결제창 데이터를 만든다.
- `validateCallback()`: 인증 결과와 주문 연결을 확인한다.
- `approve()`, `query()`, `refund()`: PG 규격으로 요청하고 공통 결과로 변환한다.
- `query()`는 `status`, `valid`, `transaction_id`, `paid_at`, `cancelled`, 선택적
  `cancellations`를 돌려준다. `valid`는 PG에서 조회한 상점·주문·금액·통화 등이 모두
  주문과 일치할 때만 참이다. 취소 항목은 `id`, 정수 원 단위 `amount`, Unix 초 `at`이다.
- `refund()`는 확정된 취소의 `id`, `amount`, `at`를 반환한다. 응답이 불확실하면 예외를
  내서 원장의 보류 상태를 유지한다. 승인·환불 타임아웃을 성공이나 미처리로 추측하지 않는다.

현재 `StreamTransport`는 등록된 이니시스·KCP 주소만 허용한다. 새 PG는 `Transport` 구현을 주입해
그 PG의 공식 HTTPS 주소·응답 형식·인증을 검증한다. 임의 콜백 URL로 인증키를 보내거나,
리다이렉트를 따라가거나, TLS 검증을 끄지 않는다. 필요하면 PG별 콜백/웹훅 인증과 경로도
추가한다. 브라우저 인증 콜백과 비동기 입금 통보는 같은 계약이 아니다. 이니시스 가상계좌 통보는
서명된 주문 콜백 URL로 받고, 본문을 결제 완료로 신뢰하지 않고 이니시스 거래 조회 결과를 확인한다.

PG별 프로토콜은 각각 구현해야 한다. 이 구조는 서로 다른 PG를 설정 이름만 바꿔 연결하는
방식이 아니라, 반복되는 주문·설정·원장·관리 화면을 공유하는 방식이다.
`tests/Payment/TestProvider.php`는 실제 연동에 사용하지 않는 모의 어댑터 예다.

## 데이터와 업그레이드

코어 DB 스키마 32판은 다음을 추가한다. MySQL의 기존 데이터는 보존한다. SQLite는 지원하지 않는다.

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
