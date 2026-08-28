<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Cms\ContentImageService;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 본문·목록에는 줄인 사진을 내보내고 원본은 눌렀을 때만 받아 가야 한다.
 * 파일 이름 뒤에 -thumb / -view 를 붙여 구분한다.
 */
final class ImageVariantTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testBodyImageIsShrunkAndLinksToTheOriginal(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [$postId, $url] = $this->seedPostWithImage($app);

        $body = $this->body($this->get($app, '/posts/' . $postId));

        preg_match_all('#<img[^>]*src="([^"]+)"#', $body, $images);
        self::assertContains(str_replace('.jpg', '-view.jpg', $url), $images[1]);
        self::assertNotContains($url, $images[1], '본문에 원본을 바로 걸면 안 된다');
        self::assertStringContainsString('href="' . $url . '"', $body, '눌러서 원본을 볼 길은 있어야 한다');
        self::assertStringContainsString('data-zoom', $body);
    }

    /** 줄인 파일이 실제로 더 작아야 의미가 있다. */
    #[DataProvider('connectionProvider')]
    public function testVariantsAreActuallySmallerThanTheOriginal(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [, $url] = $this->seedPostWithImage($app);

        $original = strlen($this->body($this->get($app, $url)));
        $view = strlen($this->body($this->get($app, str_replace('.jpg', '-view.jpg', $url))));
        $thumb = strlen($this->body($this->get($app, str_replace('.jpg', '-thumb.jpg', $url))));

        self::assertGreaterThan(0, $thumb);
        self::assertLessThan($view, $thumb, '목록용이 본문용보다 작아야 한다');
        self::assertLessThan($original, $view, '본문용이 원본보다 작아야 한다');
    }

    /** 목록 카드에서도 원본을 부르면 안 된다. */
    #[DataProvider('connectionProvider')]
    public function testGalleryCardUsesTheSmallVariant(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [, $url] = $this->seedPostWithImage($app);

        $body = $this->body($this->get($app, '/boards/gal'));

        self::assertStringContainsString(str_replace('.jpg', '-thumb.jpg', $url), $body);
        self::assertStringNotContainsString('src="' . $url . '"', $body);
    }

    /** 허락하지 않은 크기 이름은 받지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testUnknownVariantNameIsNotServed(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [, $url] = $this->seedPostWithImage($app);

        self::assertSame(404, $this->get($app, str_replace('.jpg', '-huge.jpg', $url))->getStatusCode());
    }

    /** 원본을 지우면 줄인 사진도 함께 사라져야 한다. */
    #[DataProvider('connectionProvider')]
    public function testDiscardingAnImageRemovesItsVariants(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [, $url] = $this->seedPostWithImage($app);
        $this->get($app, str_replace('.jpg', '-thumb.jpg', $url));

        $directory = $this->imageDirectory($app);
        self::assertCount(2, glob($directory . '/*') ?: []);

        $app->contentImages()->discard($this->adminAcl(), basename(dirname($url)), [basename($url)]);

        self::assertSame([], glob($directory . '/*') ?: []);
    }

    /** @return array{0:int,1:string} 글 번호와 원본 사진 주소 */
    private function seedPostWithImage(App $app): array
    {
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'gal', 'name' => '갤러리', 'list_type' => 'gallery',
        ]);

        $key = str_repeat('a', 32);
        $file = str_repeat('b', 32) . '.jpg';
        // 편집기 저장 폴더는 설정에 매인 고정 경로라 앞선 시험이 남긴 파일이 있을 수 있다.
        $directory = $this->imageDirectory($app);
        $this->clearDirectory($directory);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $canvas = imagecreatetruecolor(1600, 1000);
        for ($x = 0; $x < 1600; $x += 8) {
            imagefilledrectangle($canvas, $x, 0, $x + 7, 1000, imagecolorallocate($canvas, $x % 256, ($x * 3) % 256, ($x * 7) % 256));
        }
        imagejpeg($canvas, $directory . '/' . $file, 92);
        imagedestroy($canvas);

        $url = '/media/editor/' . $key . '/' . $file;
        $post = $app->postService()->create($this->adminAcl(), 'gal', [
            'title' => '사진 글', 'content' => '<p>보기</p><img src="' . $url . '">',
        ]);

        return [(int) $post['id'], $url];
    }

    private function imageDirectory(App $app): string
    {
        $property = new \ReflectionProperty(ContentImageService::class, 'root');
        $property->setAccessible(true);

        return $property->getValue($app->contentImages()) . '/' . str_repeat('a', 32);
    }

    private function clearDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
