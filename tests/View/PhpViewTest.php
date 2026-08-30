<?php

declare(strict_types=1);

namespace GnuCms\Tests\View;

use GnuCms\View\PhpView;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Interfaces\RouteParserInterface;

final class PhpViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gnucms-phpview-' . getmypid();
        @mkdir($this->dir . '/sub', 0777, true);
    }

    protected function tearDown(): void
    {
        // GLOB_BRACE 는 PHP 빌드에 따라 없다(이 환경에도 없다). 두 번 훑어 같은 일을 한다.
        $files = array_merge(glob($this->dir . '/*.php') ?: [], glob($this->dir . '/sub/*.php') ?: []);
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/sub');
        @rmdir($this->dir);
    }

    private function write(string $name, string $php): void
    {
        file_put_contents($this->dir . '/' . $name . '.php', $php);
    }

    private function view(): PhpView
    {
        $routes = $this->createMock(RouteParserInterface::class);
        $routes->method('urlFor')->willReturnCallback(
            static fn (string $name, array $p = [], array $q = []): string =>
                '/r/' . $name . ($p ? '/' . implode('/', $p) : '') . ($q ? '?' . http_build_query($q) : '')
        );
        return new PhpView(
            [$this->dir],
            $routes,
            '/base',
            static fn (string $path): string => '/themes/t/' . $path,
            static fn (string $html): string => '<div class="rich">' . $html . '</div>'
        );
    }

    public function testEscapesLikeTwig(): void
    {
        $this->write('a', '<?= $this->e($v) ?>|<?= $this->e(null) ?>');
        self::assertSame('&lt;b&gt;&quot;&#039;&amp;|', $this->view()->fetch('a', ['v' => '<b>"\'&']));
    }

    public function testChildBlocksFillLayoutAndOutsideOutputIsDropped(): void
    {
        $this->write('layout', "<html><title><?= \$this->block('title', '기본') ?></title><main><?= \$this->block('body') ?></main></html>");
        $this->write('page', "<?php \$this->layout('layout') ?>버림<?php \$this->start('body') ?>본문 <?= \$this->e(\$name) ?><?php \$this->stop() ?>");
        self::assertSame(
            '<html><title>기본</title><main>본문 홍길동</main></html>',
            $this->view()->fetch('page', ['name' => '홍길동'])
        );
    }

    public function testChildBlockBeatsParentDefaultAndNestedLayoutsWork(): void
    {
        // 루트 레이아웃은 start/stop 으로 '기본값을 정의하면서 출력' 한다 (Twig 의 {% block %}).
        $this->write('layout', "[<?php \$this->start('chrome') ?>기본크롬<?php \$this->stop() ?>|<?= \$this->block('body') ?>]");
        // 중간 레이아웃은 자식이면서 부모다. 자기 블록은 조용히 잡히고, 자식 블록을 읽어 넣는다.
        $this->write('sub/layout', "<?php \$this->layout('layout') ?><?php \$this->start('chrome') ?>관리크롬(<?= \$this->block('body') ?>)<?php \$this->stop() ?>");
        $this->write('page', "<?php \$this->layout('sub/layout') ?><?php \$this->start('body') ?>글<?php \$this->stop() ?>");
        self::assertSame('[관리크롬(글)|글]', $this->view()->fetch('page'));
    }

    public function testHasSeesOnlyBlocksTheChildFilled(): void
    {
        $this->write('layout', "<?= \$this->has('search') ? 'S' : '-' ?>");
        $this->write('with', "<?php \$this->layout('layout') ?><?php \$this->start('search') ?>x<?php \$this->stop() ?>");
        $this->write('blank', "<?php \$this->layout('layout') ?><?php \$this->start('search') ?>  <?php \$this->stop() ?>");
        $this->write('none', "<?php \$this->layout('layout') ?>");
        self::assertSame('S', $this->view()->fetch('with'));
        self::assertSame('-', $this->view()->fetch('blank'), '공백만 있는 블록은 비어 있는 것이다');
        self::assertSame('-', $this->view()->fetch('none'));
    }

    public function testInsertPassesCurrentVariablesPlusExtras(): void
    {
        $this->write('part', '<?= $this->e($site) ?>/<?= $this->e($x) ?>');
        $this->write('page', "<?php \$this->insert('part', ['x' => 1]) ?>;<?php \$this->insert('part', ['x' => 2]) ?>");
        $view = $this->view();
        $view->addGlobal('site', 'S');
        self::assertSame('S/1;S/2', $view->fetch('page'));
    }

    public function testDataBeatsGlobalsWithSameName(): void
    {
        $this->write('a', '<?= $this->e($site) ?>');
        $view = $this->view();
        $view->addGlobal('site', 'global');
        self::assertSame('local', $view->fetch('a', ['site' => 'local']));
    }

    public function testUrlAssetHtmlJsonDateHelpers(): void
    {
        $this->write('a', "<?= \$this->url('posts.show', ['id' => '7'], ['q' => 'a b']) ?>|<?= \$this->asset('theme.css') ?>|<?= \$this->html('<p>x</p>') ?>|<?= \$this->json(['a' => '<']) ?>|<?= \$this->date('2026-08-30 01:02:03', 'Y.m.d H:i') ?>|<?= \$this->base ?>");
        self::assertSame(
            '/r/posts.show/7?q=a+b|/themes/t/theme.css|<div class="rich"><p>x</p></div>|{"a":"<"}|2026.08.30 01:02|/base',
            $this->view()->fetch('a')
        );
    }

    public function testIconComesFromIconsFile(): void
    {
        $this->write('_icons', "<?php return ['home' => '<path d=\"M1 1\"/>'];");
        $this->write('a', "<?= \$this->icon('home', 18, 'x') ?>|<?= \$this->icon('nope') ?>");
        $out = $this->view()->fetch('a');
        self::assertStringContainsString('<svg class="icon x" width="18" height="18"', $out);
        self::assertStringContainsString('<path d="M1 1"/>', $out);
        self::assertStringContainsString('|<svg class="icon" width="20" height="20"', $out);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->view()->fetch('does/not/exist');
    }

    public function testExceptionInsideTemplateDoesNotLeakBufferedOutput(): void
    {
        $this->write('a', "앞<?php throw new \\LogicException('x'); ?>");
        $level = ob_get_level();
        try {
            $this->view()->fetch('a');
            self::fail('예외가 나야 한다');
        } catch (\LogicException $e) {
            self::assertSame($level, ob_get_level(), '출력 버퍼가 남으면 다음 화면이 깨진다');
        }
    }
}
