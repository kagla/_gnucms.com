<?php

declare(strict_types=1);

/** Run in a development or staging site after configuring MySQL/MariaDB. */

use GnuCms\App;
use GnuCms\Payment\ProviderConfig;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$config = require $root . '/config/config.php';
if (!str_starts_with((string) ($config['db']['dsn'] ?? ''), 'mysql:')) {
    throw new RuntimeException('데모 상품은 MySQL/MariaDB 사이트에만 등록할 수 있습니다.');
}
$catalog = json_decode((string) file_get_contents($root . '/docs/shop-demo-catalog.json'), true, 16, JSON_THROW_ON_ERROR);
$app = new App($config);
$shop = $app->shop();
$imageDir = $app->storageDir() . '/demo-images/final';
if (!is_dir($imageDir) && !mkdir($imageDir, 0755, true) && !is_dir($imageDir)) {
    throw new RuntimeException('데모 이미지 폴더를 만들 수 없습니다.');
}

/** Fetch one CC0 source and convert it to a bounded, square JPEG for the shop upload flow. */
function downloadDemoImage(array $product, string $path): void
{
    if (is_file($path)) return;
    $bytes = null;
    foreach (['source_url', 'thumbnail_url'] as $key) {
        $url = (string) ($product['image'][$key] ?? '');
        if (!preg_match('~^https?://~i', $url)) continue;
        $url = preg_replace('~^http://~i', 'https://', $url);
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'GNUCMS demo catalog (CC0 image download)',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 12000000) return 0;
                $body .= $chunk;
                return strlen($chunk);
            }]);
        if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'https');
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok !== false && $status === 200 && @getimagesizefromstring($body) !== false) {
            $bytes = $body;
            break;
        }
    }
    if ($bytes === null) throw new RuntimeException($product['code'] . ' 이미지를 내려받지 못했습니다.');
    $source = @imagecreatefromstring($bytes);
    if ($source === false) throw new RuntimeException($product['code'] . ' 이미지를 해석하지 못했습니다.');
    $width = imagesx($source);
    $height = imagesy($source);
    $target = imagecreatetruecolor(900, 900);
    imagefill($target, 0, 0, imagecolorallocate($target, 246, 244, 240));
    $scale = min(840 / $width, 840 / $height);
    $drawWidth = max(1, (int) round($width * $scale));
    $drawHeight = max(1, (int) round($height * $scale));
    imagecopyresampled($target, $source, (int) ((900 - $drawWidth) / 2), (int) ((900 - $drawHeight) / 2),
        0, 0, $drawWidth, $drawHeight, $width, $height);
    $temporary = $path . '.tmp';
    if (!imagejpeg($target, $temporary, 85) || !rename($temporary, $path)) {
        throw new RuntimeException($product['code'] . ' 이미지를 저장하지 못했습니다.');
    }
    imagedestroy($target);
    imagedestroy($source);
}

foreach ($catalog['products'] as $product) {
    downloadDemoImage($product, $imageDir . '/' . $product['code'] . '.jpg');
}

$categoryIds = [];
foreach ($catalog['categories'] as $category) {
    $existing = $shop->categories->bySlug($category['slug']);
    $categoryIds[$category['slug']] = $existing === null
        ? $shop->categories->save(['name' => $category['name'], 'slug' => $category['slug'], 'active' => '1',
            'list_columns' => '3', 'list_rows' => '5', 'image_width' => '300', 'image_height' => '0'])
        : (int) $existing['id'];
}

$created = 0;
foreach ($catalog['products'] as $index => $product) {
    if ($shop->products->byCode($product['code']) !== null) continue;
    $path = $imageDir . '/' . $product['code'] . '.jpg';
    $bytes = (string) file_get_contents($path);
    $file = new UploadedFile((new StreamFactory())->createStream($bytes), $product['code'] . '.jpg',
        'image/jpeg', strlen($bytes), UPLOAD_ERR_OK);
    $shop->products->save(['code' => $product['code'], 'name' => $product['name'],
        'category_id' => (string) $categoryIds[$product['category']], 'active' => '1',
        'price' => (string) $product['price'], 'list_price' => (string) $product['list_price'],
        'stock' => (string) $product['stock'], 'sort_order' => (string) ($index + 1),
        'summary' => '<p>' . htmlspecialchars($catalog['notice'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
        'description' => '<p>' . htmlspecialchars($product['name'] . ' — ' . $catalog['notice'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'], [$file]);
    $created++;
}

$shop->settings->setPaymentEnvironment('test');
$settings = $shop->settings->all();
$settings['visible'] = true;
$settings['payment']['provider'] = 'inicis';
$settings['payment']['environment'] = 'test';
$settings['payment']['methods'] = ['card' => true];
$settings['main']['categories'] = array_map(static fn (array $category): array =>
    ['id' => $categoryIds[$category['slug']], 'columns' => 3, 'rows' => 1], $catalog['categories']);
$settings['banner']['eyebrow'] = 'GNUCMS DEMO STORE';
$settings['banner']['title'] = "일상을 채우는 발견,\n데모 쇼핑몰";
$settings['banner']['description'] = '10개 분류와 30개 예시 상품으로 쇼핑 기능을 둘러보세요.';
$shop->store->db->update('yc_settings', ['payload' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
    'id = :id', ['id' => 'settings']);
if (!$app->paymentSettings('inicis')->available('test')) {
    $app->paymentSettings('inicis')->save('test', ProviderConfig::testCredentials());
}

$email = 'shop-demo@example.test';
$member = $app->users()->findByEmail($email);
$credentialFile = $app->storageDir() . '/shop-demo-member.json';
if ($member === null) {
    $password = bin2hex(random_bytes(8));
    $memberId = $app->users()->create($email, password_hash($password, PASSWORD_DEFAULT), '쇼핑몰 데모회원');
    $app->users()->verifyEmail($memberId);
    file_put_contents($credentialFile, json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($credentialFile, 0600);
}

echo '분류 ' . count($categoryIds) . '개, 신규 상품 ' . $created . '개; 이니시스 테스트 카드 결제 설정 완료.' . PHP_EOL;
echo '데모 회원 정보: ' . $credentialFile . PHP_EOL;
