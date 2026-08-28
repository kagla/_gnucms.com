<?php

declare(strict_types=1);

/**
 * 사람이 보는 기본 이름. 설치할 때 site_settings 의 site_name 으로 들어가고,
 * 그 뒤로는 관리자가 고친 site_name 이 앞선다. 설정을 읽을 수 없는 자리
 * (설치 화면, 기본값, 스키마 씨앗)에서만 이 상수를 그대로 쓴다.
 */
const GNUCMS = 'gnucms.com';

/**
 * app.url 을 설정하지 않은 설치에서 쓰는 기본 주소. 메일 링크와 소셜 로그인
 * redirect_uri 가 이 값을 바탕으로 만들어진다.
 */
const GNUCMS_URL = 'https://gnucms.gnuboard.net';

/**
 * 저장 키·CSS 클래스·쿠키·편집기 플러그인 이름 앞에 붙는 기술 식별자.
 * public/themes 아래 theme.css 와 public/assets/editor-content.css 에도
 * 같은 접두사가 글자 그대로 박혀 있으니, 이 값을 바꾸면 그 파일들도 같이 고쳐야 한다.
 */
const GNUCMS_ID = 'gnucms';
