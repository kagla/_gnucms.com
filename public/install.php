<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ApiBoard\Error\DomainError;
use ApiBoard\Install\Installer;

$installer = new Installer(__DIR__ . '/../config/config.php', __DIR__ . '/../storage');

$done = null;
$errors = [];
$input = [
    'dsn'          => 'sqlite:' . realpath(__DIR__ . '/..') . '/storage/board.sqlite',
    'db_username'  => '',
    'admin_id'     => 'root',
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
<title>apiboard 설치</title>
<style>
  body { font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; color: #1a1a1a; }
  h1 { font-size: 22px; }
  label { display: block; margin-top: 16px; font-weight: 600; }
  input, textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font: inherit; box-sizing: border-box; }
  .hint { color: #666; font-weight: 400; font-size: 13px; }
  .error { color: #b00020; font-size: 13px; margin-top: 4px; }
  .done { background: #e7f6ea; border: 1px solid #46a15a; padding: 16px; border-radius: 4px; }
  button { margin-top: 24px; padding: 10px 20px; font: inherit; cursor: pointer; }
</style>
</head>
<body>
<h1>apiboard 설치</h1>

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

    <label>관리자 아이디
      <input name="admin_id" value="<?= h($input['admin_id']) ?>" required>
    </label>
    <label>관리자 비밀번호 <span class="hint">8자 이상</span>
      <input name="admin_password" type="password" required>
    </label>
    <?php if (isset($errors['admin_password'])): ?><p class="error"><?= h($errors['admin_password']) ?></p><?php endif; ?>

    <label>허용할 출처 (CORS) <span class="hint">호스트 앱 주소를 한 줄에 하나씩. 없으면 비워 둡니다</span>
      <textarea name="cors_origins" rows="3"><?= h($input['cors_origins']) ?></textarea>
    </label>

    <button type="submit">설치</button>
  </form>
<?php endif; ?>
</body>
</html>
