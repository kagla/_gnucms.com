# 약관·동의 구조 설계

- 작성일: 2026-08-30
- 상태: 초안
- 선행 작업: `14e4e61` (약관을 내용에 합침), `d4eddff` (필수·선택), `f1dcba1` (동의 내역·스키마 도장)

## 1. 배경

약관을 별도 표에서 빼내 `contents` 로 합쳤다. 그 결과 약관을 개수 제한 없이 만들 수 있고,
가입 화면에 자동으로 붙고, 동의 기록이 남는다. 여기까지는 동작한다.

그런데 세 가지가 걸린다.

**첫째, 관리 화면에서 무엇이 약관인지 정하는 방법이 애매하다.** 지금은 "가입 동의 항목"
칸에 `marketing` 같은 키를 사람이 직접 적는다. 무슨 키를 적어야 하는지, 안 적으면 어떻게
되는지가 화면만 봐서는 알 수 없다.

**둘째, 약관을 회원가입 말고 다른 자리에서도 쓸 수 있어야 한다.** 앞으로 신청 폼 같은
기능이 생기면 거기에도 동의를 붙여야 한다. 그런데 지금은 필수·선택과 차례가 내용 행에
붙어 있어서, 같은 약관이 자리마다 다른 규칙을 가질 수 없다.

**셋째, 편집 경로가 둘이라 헷갈린다.** 약관을 `/admin/terms` 에서도, `/admin/content`
에서도 고칠 수 있다. 게다가 `/admin/terms` 는 이용약관·개인정보 둘만 알아서 세 번째
약관은 다루지 못한다.

## 2. 목표

1. **무엇이 약관인지 토글 하나로 정한다.** 키를 사람이 짓지 않는다.
2. **약관을 어느 자리에든 붙일 수 있다.** 자리마다 필수·선택과 차례를 따로 정한다.
3. **비회원 제출에도 동의를 남길 수 있다.** 기록 표를 미리 넓혀 둔다.
4. **편집 경로를 하나로 만든다.** 약관은 약관 관리에서, 나머지는 내용 관리에서.
5. **동의 현황을 약관별로 본다.** 누가 동의했고 누가 안 했는지.

## 3. 결정과 근거

### 3.1 표는 하나, 화면은 둘

약관은 `contents` 에 그대로 둔다. 편집기·이미지 업로드·SEO 설명·미리보기·휴지통·slug 가
일반 내용과 전부 같아서, 표를 나누면 같은 것을 두 벌 유지하게 된다.

반면 **화면은 나눈다.** 약관에는 일반 내용에 없는 것이 딸린다 — 어느 자리에 붙일지,
필수인지 선택인지, 그리고 동의 현황. 내용 관리 목록에 그 열을 넣으면 대부분의 행에서
빈칸이 된다. 지우는 규칙도 다르다(동의 항목으로 쓰는 내용은 지울 수 없다).

하는 일이 다른 것은 화면이지 저장이 아니다.

### 3.2 동의 키를 없애고 내용 id 를 쓴다

`contents.consent_key` 를 없앤다. 동의 기록에 이미 `content_id` 가 들어가고, 그것이
더 확실한 신원이다. 가입 화면의 필드 이름은 `agree_3` 처럼 내용 id 로 만든다.

키를 없애면 "무슨 키를 적어야 하나" 라는 문제가 통째로 사라진다. 제목이 바뀌든 slug 가
바뀌든 기록이 끊기지 않는다.

기록 표의 `consent_type` 칸은 남긴다. 다만 신원이 아니라 **그때 그 문서가 무엇이었는지
읽기 쉽게 남기는 기록용**이다. 문서가 지워져도 이름이 남는다.

### 3.3 필수·선택과 차례는 약관이 아니라 "붙임"에 속한다

지금은 `contents.consent_required` 와 `consent_order` 가 내용 행에 붙어 있다. 자리가
회원가입 하나뿐일 때는 괜찮지만, 두 번째 자리가 생기면 깨진다. **같은 약관이 회원가입에선
필수, 신청 폼에선 선택일 수 있다.**

그래서 붙임을 별도 표로 뺀다.

```
consent_uses(scope, content_id, required, sort_order)
```

`scope` 는 붙이는 자리다. 지금은 `signup` 하나뿐이고, 나중에 `form:event-2026`,
`board:qna` 처럼 늘어난다.

### 3.4 동의 기록을 회원 밖으로 넓힌다

앞으로 만들 폼은 회원도 비회원도 제출할 수 있다. 비회원이면 동의를 회원이 아니라
**제출 건**에 달아야 한다.

`user_consents` 를 `consents_given` 으로 넓힌다.

```
subject_type   user | submission
subject_id     회원 id 또는 제출 건 id
```

**이 부분만 미리 넓히는 이유**는 비대칭이기 때문이다. 붙이는 구조(`consent_uses`)는
나중에 넓혀도 데이터가 적어 기계적이지만, **쌓인 동의 기록은 나중에 옮기기 비싸다.**
동의 기록은 함부로 손대면 안 되는 자료다. 비싼 쪽만 지금 사고, 싼 쪽은 필요할 때 산다.

### 3.5 증적으로 IP 를 남긴다. 이것은 동의 대상이 아니다

접속 IP 는 개인정보로 취급하는 것이 안전하다. 그러나 **동의를 받았다는 사실을 입증하기
위한 기록**은 동의가 아니라 정당한 이익·법적 의무 이행을 근거로 수집한다. 논리적으로도
순환이다 — 동의 기록용 IP 수집에 동의를 받으면 그 동의를 또 기록해야 한다.

동의 대신 해야 할 것이 셋 있다.

1. 개인정보 처리방침에 자동 수집 항목으로 고지한다 (접속 IP, 접속 일시, 브라우저 정보 /
   목적: 동의 사실 증명, 부정 이용 방지)
2. 보관기간을 정하고 지킨다. 무기한 보관이 문제다.
3. 그 목적으로만 쓴다. 증적으로 받은 IP 를 통계나 마케팅에 쓰면 목적 외 이용이다.

**IP 는 마스킹하지 않고 원문으로 둔다.** 마스킹하면 증적으로서의 값이 거의 없어져서,
수집은 하는데 쓸모는 없는 최악이 된다. 대신 보관기간을 짧게 잡는다.

보관기간:
- 회원 동의 기록 → 탈퇴 시 함께 파기
- 비회원 제출 건 동의 기록 → 제출 건 보관기간과 동일

정작 동의를 받아야 하는 것은 폼에서 받는 **이름·연락처·이메일**이다. 그것이 그 폼에 붙일
"개인정보 수집·이용 동의" 문서이고, 거기에는 수집 항목·이용 목적·보유기간이 반드시
적혀야 한다. 회원가입의 개인정보 처리방침과 다른 문서인 이유가 이것이다.

> 법률 자문이 아니다. 실제 운영 전에 확인이 필요하다.

## 4. 데이터 모델

### 4.1 contents — 칸 셋을 하나로 바꾼다

```
- consent_key       VARCHAR(20)  NULL      (없앤다)
- consent_order     INTEGER               (consent_uses 로 옮긴다)
- consent_required  INTEGER               (consent_uses 로 옮긴다)
+ is_consent        SMALLINT NOT NULL DEFAULT 0
```

`ux_contents_consent` 인덱스를 지우고 `ix_contents_is_consent` 를 만든다.

### 4.2 consent_uses — 신설

```
id          {AUTO_PK}
scope       VARCHAR(40)  NOT NULL      -- signup, form:xxx, board:xxx
content_id  BIGINT       NOT NULL
required    SMALLINT     NOT NULL DEFAULT 1
sort_order  INTEGER      NOT NULL DEFAULT 0
created_at  {DATETIME}   NOT NULL

UNIQUE INDEX ux_consent_uses (scope, content_id)
INDEX        ix_consent_uses_content (content_id)
```

### 4.3 consents_given — user_consents 를 넓힌다

```
id                  {AUTO_PK}
subject_type        VARCHAR(20)  NOT NULL   -- user | submission
subject_id          BIGINT       NOT NULL
scope               VARCHAR(40)  NOT NULL   -- 어느 자리에서 받았나
content_id          BIGINT       NOT NULL
consent_type        VARCHAR(100) NOT NULL   -- 그때의 slug. 읽기용 기록 (slug 와 같은 폭)
content_updated_at  {DATETIME}   NOT NULL   -- 그때 본 판
agreed              SMALLINT     NOT NULL DEFAULT 1
agreed_at           {DATETIME}   NOT NULL
agreed_ip           VARCHAR(45)  NULL       -- 증적. IPv6 까지
agreed_ua           VARCHAR(255) NULL       -- 증적

UNIQUE INDEX ux_consents_given (subject_type, subject_id, scope, content_id)
INDEX        ix_consents_given_content (content_id)
```

## 5. 화면

### 5.1 내용 관리

약관을 **뺀다** (`is_consent = 1` 인 행을 목록에서 제외). 편집 경로가 하나로 정리된다.

편집 폼에는 토글 하나만 남는다.

```
[ ● ] 이 내용은 약관이다
      켜면 동의 항목으로 고를 수 있게 됩니다.
```

켜면 그 내용은 약관 관리 목록으로 옮겨간다. 키 입력칸·필수 토글·차례 칸은 전부 없앤다.

### 5.2 약관 관리 (`/admin/terms` 를 고쳐 쓴다)

이용약관·개인정보 둘만 알던 화면을 **약관 전부를 다루는 화면**으로 바꾼다. 개수 제한이 없다.

```
약관 관리
  이용약관             필수  차례 10   동의 42 · 미동의 0    [수정] [동의 현황]
  개인정보 처리방침     필수  차례 20   동의 42 · 미동의 0    [수정] [동의 현황]
  마케팅 정보 수신      선택  차례 30   동의 11 · 미동의 31   [수정] [동의 현황]
  위치기반 서비스 약관   ─    붙인 곳 없음                   [수정]
```

필수·선택과 차례는 여기서 정한다(`consent_uses` 를 쓴다). 자리가 여럿이 되면 자리별로
상자가 나뉜다.

`[수정]` 은 내용 편집 폼을 그대로 쓴다. 폼은 공유하고 진입만 여기서 한다.

옛 라우트는 이렇게 정리한다.

| 옛 주소 | 뒤 |
|---|---|
| `GET /admin/terms` | 남는다. 약관 전부를 보여주도록 고친다 |
| `GET/POST /admin/terms/{type:service\|privacy}` | 없앤다. `/admin/content/{id}/edit` 로 간다 |
| `GET /admin/terms/{type}/preview` | 없앤다. `/admin/content/{id}/preview` 로 간다 |
| `POST /admin/terms/setup` | 남는다 (5.5) |
| `GET /admin/legal*` | 없앤다. 옛 판의 되돌림 길이고 이미 두 판이 지났다 |

없애는 주소를 쓰던 20개 테마의 `legal.html.twig`·`legal_form.html.twig` 와
`pages/show.html.twig` 의 `legal_type`·`preview_legal_type` 갈림도 함께 걷는다.
`pages/show.html.twig` 의 톱니는 언제나 `admin.content.edit` 로 간다.

### 5.3 동의 현황 (`/admin/terms/{id}/consents`)

한 약관에 대해 누가 동의했고 누가 안 했는지 목록으로 본다. 회원·비회원을 함께 보여주고,
그때 본 문서의 판과 동의 시각을 함께 낸다. 문서가 그 뒤 바뀌었으면 표시한다.

### 5.4 회원 수정 화면

이미 붙어 있는 "가입 동의 내역" 표를 그대로 쓴다. `consents_given` 을 읽도록 바꾼다.

### 5.5 씨앗 약관 만들기

`ensureLegalDrafts()` 를 부르던 `/admin/terms/setup` 단추는 약관 관리 화면에 남긴다.
이용약관·개인정보가 없으면 가입 자체를 받지 않으므로 만들 길이 필요하다.

## 6. 동의 흐름

### 6.1 회원가입

1. `consent_uses` 에서 `scope = 'signup'` 인 붙임을 차례대로 읽고, 공개된 내용만 남긴다.
2. 필수 항목을 체크하지 않으면 가입을 막는다. 선택 항목은 막지 않는다.
3. 가입이 되면 **보여 준 항목 전부**를 `consents_given` 에 남긴다. 선택을 안 했으면
   `agreed = 0` 으로 남긴다. 줄이 없으면 "안 했다"인지 "묻지도 않았다"인지 가릴 수 없다.
4. `subject_type = 'user'`, `scope = 'signup'`, IP·UA 를 함께 남긴다.

첫 사람(사이트 소유자)은 약관을 만들기 전이라 동의를 받지 않는다. 지금과 같다.

### 6.2 소셜 가입

체크박스를 받을 자리가 없다. 단추 옆에 무엇에 동의하게 되는지 적고(필수만), 가입 시점에
필수는 `agreed = 1`, **선택은 `agreed = 0`** 으로 남긴다. 물어본 적 없는 선택 항목을
동의로 보면 안 된다. 지금과 같다.

### 6.3 앞으로 만들 폼

`scope = 'form:xxx'` 로 붙임을 읽는다. 제출이 되면 제출 건 id 로
`subject_type = 'submission'` 기록을 남긴다. 폼 기능 자체는 이 문서의 범위 밖이다.

## 7. 마이그레이션

`Schema::VERSION` 을 `9` 로 올린다. 도장에 파일 해시가 들어가므로 판 번호를 잊어도
멱등한 마이그레이션이 한 번 더 돈다.

1. `contents.is_consent` 를 더하고, `consent_key IS NOT NULL` 인 행을 `1` 로 채운다.
2. `consent_uses` 를 만들고, `is_consent = 1` 인 행마다
   `(scope='signup', content_id, required=consent_required, sort_order=consent_order)`
   를 넣는다.
3. `consents_given` 을 만들고 `user_consents` 를 옮긴다.
   `subject_type='user'`, `subject_id=user_id`, `scope='signup'`, IP·UA 는 `NULL`.
4. `contents.consent_key` / `consent_order` / `consent_required` 와 `user_consents` 는
   **남겨 둔다.** 한 판을 건너뛰고 다음에 지운다. 되돌릴 길을 한 판 동안 남긴다.

SQLite 는 `DROP COLUMN` 지원이 판마다 다르므로 지우는 것은 미루는 편이 안전하다.

라이브 DB 는 마이그레이션 전에 백업한다.

## 8. 범위 밖

- **폼(신청서) 기능 자체.** 이 문서는 붙일 자리를 받을 준비만 한다.
- **만 14세 미만 법정대리인 동의.** 폼을 만들 때 폼 설정에 "이 폼은 만 14세 미만도
  받나"를 두는 편이 낫다.
- **동의 철회·재동의 화면.** 회원이 마케팅 수신을 켜고 끄는 화면. `record()` 가 이미
  덮어쓰므로 화면만 붙이면 된다.
- **약관이 바뀌었을 때 재동의 받기.** 지금은 "그 뒤 바뀜" 표시까지만 한다.
- **외부 사이트에서 이 약관 쓰기.**
- **회원 탈퇴와 그때의 `consents_given` 파기.** 처리방침 초안이 보관기간을 약속하므로,
  탈퇴 기능을 만들 때 동의 기록 파기를 함께 넣는다.

## 9. 테스트

기존 테스트를 옮기고 다음을 더한다.

1. 토글을 켜면 약관 관리 목록에 나오고 내용 관리 목록에서 빠진다.
2. `consent_uses` 에 붙이지 않은 약관은 가입 화면에 나오지 않는다.
3. 같은 약관을 두 자리에 다른 필수·선택으로 붙일 수 있다.
4. 선택 항목을 비워도 가입되고, `agreed = 0` 으로 기록된다.
5. 소셜 가입은 필수만 `agreed = 1`, 선택은 `0` 이다.
6. 동의 기록에 IP 가 남는다.
7. `subject_type = 'submission'` 기록을 넣고 읽을 수 있다.
8. 마이그레이션이 기존 `user_consents` 를 빠짐없이 옮긴다.
9. SQLite·MySQL·PostgreSQL 에서 동일하게 동작한다.

## 10. 작업 순서

1. 스키마와 마이그레이션 (`Schema`, 4장)
2. 저장소·서비스 (`CmsRepository`, `CmsService`, `ConsentRepository`, `AccountService`,
   `LinkingService`)
3. 약관 관리 화면 (`/admin/terms` 를 고쳐 씀) + 내용 관리에서 약관 빼기
4. 내용 편집 폼의 토글 (20개 테마)
5. 가입 화면 (`_consents.html.twig`, `_social_consent.html.twig`)
6. 동의 현황 화면
7. 옛 칸 지우기 — 다음 판으로 미룸
