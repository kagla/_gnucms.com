# 광고·분석 동의 관리

설정 → 기본·홈 → 광고·분석 동의 관리에서 적용 규정을 선택한다.
기본은 사용 안 함이며, 기존 광고·분석 코드의 실행 방식과 저장값을 유지한다.
유럽 규정, 미국 주 규정, 유럽·미국 주 규정은 Google CMP를 사용한다.
기타 규정은 해당 규정을 지원하는 외부 CMP를 연결한다. 규정 이름은 관리용 이름이며
이름을 입력하는 것만으로 해당 규정의 동작이 구현되지는 않는다.

기존 `site_settings`의 `privacy_mode`, `privacy_cmp_html`, `privacy_regulation_name`에 저장한다.
DB 구조·스키마 판 번호·제품 버전을 바꾸지 않는다. 사용 안 함으로 저장해도 CMP 코드와
기타 규정 이름은 보존한다. CMP·광고·분석 코드는 공개 화면에만 출력한다.
손상된 규정 값은 사용 안 함으로 바꾸지 않고 외부 CMP 대기로 처리하여 광고·분석 코드를 차단한다.

## Google CMP

1. 애드센스에 사이트를 등록하고 기존 애드센스 head 코드를 GNUCMS에 저장한다.
2. 애드센스 → 개인정보 보호 및 메시지에서 선택한 규정의 메시지를 만든다.
3. 사이트, 대상 지역, 언어, 개인정보처리방침 주소와 방문자 선택 항목을 확인하고 게시한다.
4. 유럽 규정은 Google CMP의 동의 모드에서 광고 목적과 분석 목적을 모두 켠다.
5. GNUCMS에서도 같은 규정 또는 유럽·미국 주 규정을 선택해 저장한다.

기존 애드센스 태그가 Google 메시지를 불러온다. 별도 CMP 코드가 필요한 구성에서는
CMP 연동 코드 칸에 발급된 코드를 넣는다. 같은 로더를 두 번 넣지 않는다.
GNUCMS 설정은 Google 계정의 메시지·대상 지역·게시 상태를 생성하거나 변경하지 않는다.
사용 안 함도 Google에서 게시한 메시지를 해제하지 않는다. Google 계정에서 별도로 관리한다.
일반 쿠키 배너를 Google 인증 CMP 대신 사용할 수 있는 것으로 안내하지 않는다.

Google Consent Mode 기본 상태는 네 목적 모두 denied로 설정한다. 저장된 분석 코드는
실행되지 않는 JSON에 보관한 뒤 `CONSENT_MODE_DATA_READY`의 분석 상태가 GRANTED(1) 또는
NOT_APPLICABLE(3)일 때 실행한다. UNKNOWN·DENIED·NOT_CONFIGURED에서는 실행하지 않는다.
Google의 광고·분석 동의 목적은 각각 전달하며, 분석 동의로 광고 동의를 대신하지 않는다.
Google 애드센스 태그 자체는 CMP 로더이므로 선행 차단하지 않는다. 그 광고 요청의
동의 처리는 Google의 TCF/GPP 연동을 따른다.

미국 주 규정은 초기 상태를 확인할 때까지 분석 코드를 기다리게 한다. 적용 제외 또는
미거부 상태에서 실행하고, 거부·알 수 없는 상태와 GPC 신호에서는 실행하지 않는다.
두 규정을 선택하면 두 조건이 모두 허용해야 분석 코드를 실행한다.

유럽 방문자의 TCF `gdprApplies` 상태에 따라 하단에 개인정보·쿠키 설정 버튼을 제공한다.
Google의 기본 유럽 동의 철회 링크는 제거하지 않는다. 미국 주의 기본 판매·공유 거부 링크는
GNUCMS 하단 버튼으로 대체하며 Google 확인창으로 연결한다. 거부 후에는 이 버튼을 숨긴다.
유럽 동의를 다시 선택하거나 이미 실행한 분석 코드의 허용을 철회하면 페이지를 다시 연다.
동의가 거부되거나 CMP가 차단·실패해도 본문·로그인·주문 기능은 그대로 사용할 수 있다.

## 외부 CMP 계약

외부 CMP 연동 코드는 광고·분석 코드보다 먼저 실행한다. 광고·분석 코드는 처음에는
실행되지 않으며 CMP가 저장된 선택을 확인하거나 사용자가 선택을 완료한 콜백에서
`window.GnuCmsPrivacy.update()`를 호출해야 한다. 전달값은 실제 boolean이어야 한다.
단순히 CMP 배너를 붙이는 것만으로 코드 실행을 허용하지 않는다.

```javascript
// CMP의 문서화된 동의 콜백에서 실제 저장된 선택을 전달한다.
window.GnuCmsPrivacy.update({
  analytics: analyticsAllowed,
  advertising: advertisingAllowed,
  adUserData: adUserDataAllowed,
  adPersonalization: adPersonalizationAllowed
});

// CMP의 설정 창을 여는 함수를 전달한다.
window.GnuCmsPrivacy.setPreferencesHandler(openCmpPreferences);
```

위 변수와 함수는 연결할 CMP의 API에서 가져오는 값이다. 예시 코드를 그대로 실행하거나
처음 방문한 사용자를 무조건 허용하는 코드로 바꾸지 않는다. 각 필드는 처음에 false이고
생략한 필드는 이전 값을 유지한다. `analytics`는 저장한 분석 코드,
`advertising`은 저장한 애드센스 코드의 실행을 제어한다. Google 광고 데이터와
개인 맞춤 목적은 `adUserData`·`adPersonalization`으로 별도 전달한다.

설정 창 함수를 등록한 뒤 하단 설정 버튼이 나타난다. 지역 판별, 적용 규정, 동의 기록,
유효기간, 쿠키 정리, TCF/GPP·GPC 신호 처리는 연결한 CMP와 어댑터가 담당한다.
이미 실행한 코드를 철회할 때는 CMP가 새 선택을 먼저 저장한 다음 false를 전달한다.
GNUCMS는 Google 동의 상태를 갱신하고 페이지를 다시 열어 코드를 처음부터 차단한다.
외부 코드가 이미 만든 쿠키·저장값을 GNUCMS가 임의 삭제하지 않는다.

## 테마·확장과 코드 범위

기본 레이아웃은 `_privacy_head.php`와 `_privacy_links.php`를 사용한다. 독립 테마에는
두 조각과 레이아웃 호출을 함께 복사한다. 관리자 화면에서는 `external_service_head`를
비워 CMP까지 실행하지 않는다. `privacy.js`는 정적 파일이며 서버 빌드가 필요 없다.

관리자에 저장한 head 코드는 신뢰하는 서비스의 코드만 사용한다. 외부 스크립트는
입력 순서대로 실행하며 async 태그의 비동기 동작을 유지한다. `document.write()`나
페이지 파싱 시점에 의존하는 태그는 지연 로딩용 코드로 바꾸어 사용해야 한다.
개별 광고 단위, 소유 확인 칸에 넣은 추적 코드, 테마·확장에 직접 넣은 추적 코드와
외부 임베드는 자동 차단 범위에 포함되지 않으며 별도 연동이 필요하다.
엄격한 Referrer-Policy가 필요한 결제·계정 화면의 정책을 CMP 때문에 완화하지 않는다.

규정 선택과 CMP 연결은 광고·분석 동의 관리 도구다. 사이트 전체의 법적 준수 보장은
아니며 실제 개인정보처리방침, 광고 파트너·데이터 용도, CMP 지원 범위를 함께 맞춘다.

## 공식 참고 문서

- [Google의 게시자용 CMP 요구사항](https://support.google.com/adsense/answer/13554116?hl=ko)
- [유럽 규정 메시지](https://support.google.com/adsense/answer/10961068?hl=ko)
- [미국 주 규정 메시지](https://support.google.com/adsense/answer/10961479?hl=ko)
- [Privacy & Messaging JavaScript API](https://developers.google.com/funding-choices/fc-api-docs)
