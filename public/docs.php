<?php

declare(strict_types=1);

/*
 * API 문서 화면.
 *
 * 서버 로직이 없다. 이 파일을 지워도 API 는 그대로 동작한다. 설치 마법사처럼
 * 운영에서 감추고 싶으면 지우거나 웹서버에서 막으면 된다.
 *
 * Swagger UI 는 CDN 에서 받아 쓴다. 게시판 자체의 런타임 의존성이 아니라 이 화면
 * 하나의 의존성이다. 외부 접속이 막힌 곳이라면 이 파일을 지우고 docs/openapi.yaml
 * 을 그대로 읽거나 다른 뷰어에 넣으면 된다.
 *
 * 스펙 원본은 문서 루트 밖(docs/)에 있으므로 ?spec 으로 이 파일이 대신 내보낸다.
 */

$specFile = __DIR__ . '/../docs/openapi.yaml';

if (isset($_GET['spec'])) {
    if (!is_file($specFile)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'docs/openapi.yaml 을 찾을 수 없습니다.';
        exit;
    }

    header('Content-Type: application/yaml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    readfile($specFile);
    exit;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>표준 게시판 API 문서</title>
<link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.32.14/swagger-ui.css">
<style>
  body { margin: 0; background: #fafafa; }
  .sb-head {
    font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
    padding: 14px 20px; border-bottom: 1px solid #ddd; background: #fff; color: #1a1a1a;
  }
  .sb-head h1 { font-size: 17px; margin: 0 0 4px; }
  .sb-head p { margin: 0; font-size: 13px; color: #666; }
  .sb-head a { color: #0b57d0; }
  .sb-fallback { padding: 20px; font: 15px/1.6 system-ui, sans-serif; color: #b00020; }
</style>
</head>
<body>

<div class="sb-head">
  <h1>표준 게시판 API</h1>
  <p>
    스펙 원본: <a href="?spec">openapi.yaml</a> ·
    관리자 화면: <a href="admin.php">admin.php</a> ·
    <code>mod_rewrite</code> 가 없는 호스팅에서는 <code>index.php?p=/경로</code> 형태로 부른다.
  </p>
</div>

<div id="swagger-ui"></div>
<noscript><p class="sb-fallback">이 화면은 자바스크립트가 필요합니다. <a href="?spec">openapi.yaml</a> 을 직접 받으세요.</p></noscript>

<script src="https://unpkg.com/swagger-ui-dist@5.32.14/swagger-ui-bundle.js"></script>
<script>
  window.addEventListener('load', function () {
    if (typeof SwaggerUIBundle !== 'function') {
      document.getElementById('swagger-ui').innerHTML =
        '<p class="sb-fallback">Swagger UI 를 불러오지 못했습니다(외부 접속 차단?). '
        + '<a href="?spec">openapi.yaml</a> 을 직접 받으세요.</p>';
      return;
    }

    SwaggerUIBundle({
      url: 'docs.php?spec',
      dom_id: '#swagger-ui',
      deepLinking: true,
      presets: [SwaggerUIBundle.presets.apis],
      layout: 'BaseLayout',
      // 토큰을 한 번 넣으면 새로고침해도 남는다. 문서를 보며 실제로 눌러 보라는 뜻이다.
      persistAuthorization: true,
      defaultModelsExpandDepth: 1,
      docExpansion: 'list'
    });
  });
</script>
</body>
</html>
