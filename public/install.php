<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GnuCms\Error\DomainError;
use GnuCms\Install\Installer;

$installer = new Installer(__DIR__ . '/../config/config.php', __DIR__ . '/../storage');

$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/D', $requestHost) !== 1) {
    $requestHost = 'localhost';
}
$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$mailHost = preg_replace('/:\d+$/', '', trim($requestHost, '[]')) ?: 'localhost';

$done = null;
$errors = [];
$input = [
    'app_url'      => $requestScheme . '://' . $requestHost,
    'mail_from'    => 'no-reply@' . $mailHost,
    'dsn'          => 'sqlite:' . realpath(__DIR__ . '/..') . '/storage/board.sqlite',
    'db_username'  => '',
    'cors_origins' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = array_merge($input, $_POST);
    try {
        $done = $installer->run($_POST);
    } catch (DomainError $e) {
        $errors = $e->details() !== [] ? $e->details() : ['_' => $e->getMessage()];
    }
}

$installed = $installer->isInstalled();

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(GNUCMS, ENT_QUOTES) ?> 설치</title>
<style>
  :root { color-scheme: light; --bg: #f7f8fb; --panel: #fff; --fg: #172033; --muted: #667085; --line: #d0d5dd; --primary: #635bff; --danger: #d92d20; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 56px 16px; background: var(--bg); color: var(--fg); font: 15px/1.6 system-ui, -apple-system, "Segoe UI", "Noto Sans KR", sans-serif; letter-spacing: -.012em; }
  main { max-width: 680px; margin: auto; padding: clamp(26px, 6vw, 48px); border: 1px solid #e4e7ec; border-radius: 20px; background: var(--panel); box-shadow: 0 18px 50px rgba(16, 24, 40, .08); }
  .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 34px; font-size: 17px; font-weight: 800; }
  .mark { display: grid; place-items: center; width: 32px; height: 32px; border-radius: 10px; color: #fff; background: linear-gradient(145deg, #7c74ff, #5148e5); }
  .eyebrow { margin: 0 0 6px; color: var(--primary); font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
  h1 { margin: 0 0 10px; font-size: clamp(28px, 6vw, 38px); line-height: 1.2; letter-spacing: -.045em; }
  .intro { margin: 0 0 30px; color: var(--muted); }
  label { display: block; margin-top: 19px; font-weight: 700; }
  input, textarea { width: 100%; margin-top: 7px; padding: 11px 13px; border: 1px solid var(--line); border-radius: 11px; background: var(--panel); color: var(--fg); font: inherit; transition: border-color .15s, box-shadow .15s; }
  input:focus, textarea:focus { outline: 0; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 91, 255, .14); }
  .hint { display: block; margin-top: 3px; color: var(--muted); font-weight: 400; font-size: 12px; }
  .error { color: var(--danger); font-size: 13px; margin: 5px 0 0; }
  .done { padding: 18px 20px; border: 1px solid #75d5a4; border-radius: 13px; background: #ecfdf3; color: #085d3a; }
  button { min-height: 46px; margin-top: 28px; padding: 0 22px; border: 0; border-radius: 11px; background: var(--primary); color: #fff; font: inherit; font-weight: 750; cursor: pointer; box-shadow: 0 7px 16px rgba(99, 91, 255, .22); }
  a { color: var(--primary); font-weight: 700; }
  code { padding: 2px 5px; border-radius: 5px; background: rgba(99, 91, 255, .09); }
  @media (prefers-color-scheme: dark) { :root { color-scheme: dark; --bg: #10131a; --panel: #181c25; --fg: #f2f4f7; --muted: #aeb6c5; --line: #3d4655; --primary: #958fff; --danger: #ff8b81; } main { border-color: #2c3340; box-shadow: 0 18px 50px rgba(0,0,0,.25); } .done { background: #16392b; color: #a6f4c5; border-color: #237a50; } }
</style>
</head>
<body>
<main>
<div class="brand"><span class="mark"><?= htmlspecialchars(mb_strtoupper(mb_substr(GNUCMS, 0, 1)), ENT_QUOTES) ?></span><?= htmlspecialchars(GNUCMS, ENT_QUOTES) ?></div>
<p class="eyebrow">Setup</p>
<h1><?= htmlspecialchars(GNUCMS, ENT_QUOTES) ?> 설치</h1>
<p class="intro">몇 가지 기본 정보를 입력하면 바로 커뮤니티를 시작할 수 있습니다.</p>

<?php if ($done !== null): ?>
  <div class="done">
    <p><strong>설치가 끝났습니다.</strong> 사용 중인 DB: <?= h($done['dialect']) ?></p>
    <p><strong>지금 <code>public/install.php</code> 를 삭제하세요.</strong> 남겨 두면 설정 파일을 지운 사람이 재설치할 수 있습니다.</p>
    <p><a href="./">사이트로 이동</a></p>
  </div>
<?php elseif ($installed): ?>
  <p>이미 설치되어 있습니다. 다시 설치하려면 <code>config/config.php</code> 를 지우세요.</p>
<?php else: ?>
  <?php if (isset($errors['_'])): ?><p class="error"><?= h($errors['_']) ?></p><?php endif; ?>
  <form method="post">
    <label>사이트 주소
      <span class="hint">인증 메일과 비밀번호 재설정 링크에 사용합니다</span>
      <input name="app_url" type="url" value="<?= h($input['app_url']) ?>" placeholder="https://example.com" required>
    </label>
    <?php if (isset($errors['app_url'])): ?><p class="error"><?= h($errors['app_url']) ?></p><?php endif; ?>

    <label>발신 이메일
      <span class="hint">인증 메일을 보낼 주소입니다. 운영 도메인의 메일 주소를 권장합니다</span>
      <input name="mail_from" type="email" value="<?= h($input['mail_from']) ?>" placeholder="no-reply@example.com" required>
    </label>
    <?php if (isset($errors['mail_from'])): ?><p class="error"><?= h($errors['mail_from']) ?></p><?php endif; ?>

    <label>DB DSN
      <span class="hint">예) sqlite:/절대경로/board.sqlite · mysql:host=localhost;dbname=board;charset=utf8mb4 · pgsql:host=localhost;dbname=board</span>
      <input name="dsn" value="<?= h($input['dsn']) ?>" required>
    </label>
    <?php if (isset($errors['dsn'])): ?><p class="error"><?= h($errors['dsn']) ?></p><?php endif; ?>

    <label>DB 사용자 <span class="hint">SQLite 면 비워 둡니다</span>
      <input name="db_username" value="<?= h($input['db_username']) ?>">
    </label>
    <label>DB 비밀번호
      <input name="db_password" type="password" value="">
    </label>

    <label>허용할 출처 (CORS) <span class="hint">호스트 앱 주소를 한 줄에 하나씩. 없으면 비워 둡니다</span>
      <textarea name="cors_origins" rows="3"><?= h($input['cors_origins']) ?></textarea>
    </label>

    <button type="submit">설치</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
