# 쇼핑몰 (영카트 모듈)

영카트5의 기능을 GNUCMS 확장 모듈로 다시 만든 쇼핑몰이다. 사용자 주소 `/shop`, 관리자 주소 `/admin/shop`.

- 1단계: 분류·상품·옵션·이미지·재고 관리, 메인·분류 목록·유형별 목록·검색·상세.
- 2단계: 모던 종합 쇼핑몰 UI, 세션 장바구니·바로 주문·배송비 계산·회원/비회원 주문 접수·조회·취소·관리자 배송 처리. 온라인 결제는 별도 단계다.
- 설치: 관리자 → 모듈에서 활성화한 뒤 `/admin/shop`에서 **데이터 설치/갱신**을 실행한다.
- 설계 문서: `docs/superpowers/specs/2026-09-07-youngcart-catalog-design.md`, 운영 안내: `docs/youngcart.md`.
- 패키지 폴더는 작은 쇼핑몰이 `modules/shop`을 쓰는 동안 `modules/youngcart`를 유지한다.

UI·주문 설계: `docs/superpowers/specs/2026-09-08-youngcart-modern-checkout-design.md`.
