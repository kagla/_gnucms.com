# 쇼핑몰 (영카트 모듈)

영카트5의 기능을 GNUCMS 확장 모듈로 다시 만든 쇼핑몰이다. 사용자 주소 `/shop`, 관리자 주소 `/admin/shop`.

- 1단계: 분류·상품·옵션·이미지·재고 관리, 메인·분류 목록·유형별 목록·검색·상세.
- 2단계: 모던 종합 쇼핑몰 UI, 세션 장바구니·바로 주문·배송비 계산·회원/비회원 주문 접수·조회·취소·관리자 배송 처리. 온라인 결제는 별도 단계다.
- 설치: 관리자 → 모듈에서 활성화한 뒤 `/admin/shop`에서 **데이터 설치/갱신**을 실행한다.
- 메인 배너: `/admin/shop/settings#settings-banner`에서 문구·버튼·링크·표시 여부를 편집하고, 상품 자동 표시·메인 진열 상품 랜덤 표시·특정 상품 선택·이미지 업로드 중에서 정한다. 별도 코딩이나 DB 갱신 없이 저장 후 반영된다.
- 설계 문서: `docs/superpowers/specs/2026-09-07-youngcart-catalog-design.md`, 운영 안내: `docs/youngcart.md`.
- 패키지 폴더는 작은 쇼핑몰이 `modules/shop`을 쓰는 동안 `modules/youngcart`를 유지한다.

UI·주문 설계: `docs/superpowers/specs/2026-09-08-youngcart-modern-checkout-design.md`.
