<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Repository;

use ApiBoard\Db\Connection;
use ApiBoard\Repository\BoardRepository;
use ApiBoard\Repository\PostRepository;
use ApiBoard\Support\Clock;
use PHPUnit\Framework\Attributes\DataProvider;
use ApiBoard\Tests\Support\DatabaseTestCase;

final class PostRepositoryTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        Clock::freeze('2026-08-26 01:02:03');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    #[DataProvider('connectionProvider')]
    public function testCreateAndFind(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $id = $repo->create([
            'board_id'    => $boardId,
            'title'       => '첫 글',
            'content'     => '본문',
            'author_id'   => 'user-1',
            'author_name' => '홍길동',
        ]);

        $post = $repo->find($id);

        $this->assertSame('첫 글', $post['title']);
        $this->assertSame(0, $post['view_count']);
        $this->assertSame(0, $post['comment_count']);
        $this->assertSame([], $post['attachments']);
        $this->assertNull($post['deleted_at']);
    }

    #[DataProvider('connectionProvider')]
    public function testFindNeverExposesGuestPassword(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $id = $repo->create($this->guestPost($boardId, '비회원 글'));

        $post = $repo->find($id);

        $this->assertArrayNotHasKey('guest_password', $post);
    }

    #[DataProvider('connectionProvider')]
    public function testFindWithSecretExposesGuestPassword(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $id = $repo->create($this->guestPost($boardId, '비회원 글'));

        $post = $repo->findWithSecret($id);

        $this->assertTrue(password_verify('1234', (string) $post['guest_password']));
    }

    #[DataProvider('connectionProvider')]
    public function testPaginateExcludesNoticesAndDeleted(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $repo->create($this->post($boardId, '일반 1'));
        $noticeId = $repo->create($this->post($boardId, '공지'));
        $repo->setNotice($noticeId, true);
        $deletedId = $repo->create($this->post($boardId, '삭제됨'));
        $repo->softDelete($deletedId);

        $page = $repo->paginate($boardId, 1, 20);

        $this->assertSame(1, $page['total']);
        $this->assertSame(['일반 1'], array_column($page['rows'], 'title'));
    }

    #[DataProvider('connectionProvider')]
    public function testPaginateCanIncludeDeletedPosts(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $repo->create($this->post($boardId, '살아 있는 글'));
        $deletedId = $repo->create($this->post($boardId, '삭제된 글'));
        $repo->softDelete($deletedId);

        $page = $repo->paginate($boardId, 1, 20, null, null, true);

        $this->assertSame(2, $page['total']);
        $this->assertSame(['삭제된 글', '살아 있는 글'], array_column($page['rows'], 'title'));
    }

    #[DataProvider('connectionProvider')]
    public function testPaginateOrdersByIdDescendingAndSlices(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        foreach (['1', '2', '3', '4', '5'] as $title) {
            $repo->create($this->post($boardId, $title));
        }

        $page = $repo->paginate($boardId, 2, 2);

        $this->assertSame(5, $page['total']);
        $this->assertSame(['3', '2'], array_column($page['rows'], 'title'));
    }

    #[DataProvider('connectionProvider')]
    public function testSearchMatchesTitleOrContent(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $repo->create($this->post($boardId, '사과 이야기'));
        $repo->create(['board_id' => $boardId, 'title' => '무관', 'content' => '사과가 본문에 있다',
                       'author_id' => 'u', 'author_name' => '가']);
        $repo->create($this->post($boardId, '배 이야기'));

        $page = $repo->paginate($boardId, 1, 20, '사과');

        $this->assertSame(2, $page['total']);
    }

    #[DataProvider('connectionProvider')]
    public function testSearchTreatsPercentAsLiteral(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $repo->create($this->post($boardId, '할인 50% 행사'));
        $repo->create($this->post($boardId, '아무 글'));

        $page = $repo->paginate($boardId, 1, 20, '50%');

        $this->assertSame(1, $page['total']);
        $this->assertSame('할인 50% 행사', $page['rows'][0]['title']);
    }

    #[DataProvider('connectionProvider')]
    public function testSearchTreatsUnderscoreAsLiteral(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $repo->create($this->post($boardId, 'a_b'));
        $repo->create($this->post($boardId, 'axb'));

        $page = $repo->paginate($boardId, 1, 20, 'a_b');

        $this->assertSame(1, $page['total']);
    }

    #[DataProvider('connectionProvider')]
    public function testFilterByCategory(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $repo->create($this->post($boardId, '질문 글') + ['category' => '질문']);
        $repo->create($this->post($boardId, '잡담 글') + ['category' => '잡담']);

        $page = $repo->paginate($boardId, 1, 20, null, '질문');

        $this->assertSame(1, $page['total']);
        $this->assertSame('질문 글', $page['rows'][0]['title']);
    }

    #[DataProvider('connectionProvider')]
    public function testNoticesAreReturnedSeparatelyNewestFirst(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $first = $repo->create($this->post($boardId, '공지 1'));
        $second = $repo->create($this->post($boardId, '공지 2'));
        $repo->setNotice($first, true);
        $repo->setNotice($second, true);

        $this->assertSame(['공지 2', '공지 1'], array_column($repo->notices($boardId), 'title'));
    }

    #[DataProvider('connectionProvider')]
    public function testSoftDeleteAndRestore(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $id = $repo->create($this->post($boardId, '글'));

        $repo->softDelete($id);
        $this->assertNotNull($repo->find($id)['deleted_at']);

        $repo->restore($id);
        $this->assertNull($repo->find($id)['deleted_at']);
    }

    #[DataProvider('connectionProvider')]
    public function testIncrementViewsAndCommentCount(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $id = $repo->create($this->post($boardId, '글'));

        $repo->incrementViews($id);
        $repo->incrementViews($id);
        $repo->adjustCommentCount($id, 1);
        $repo->adjustCommentCount($id, 1);
        $repo->adjustCommentCount($id, -1);

        $post = $repo->find($id);
        $this->assertSame(2, $post['view_count']);
        $this->assertSame(1, $post['comment_count']);
    }

    #[DataProvider('connectionProvider')]
    public function testAttachmentsRoundTripAsArray(array $config): void
    {
        [$repo, $boardId] = $this->setUpBoard($config);
        $files = [['id' => 'abc', 'name' => '문서.pdf', 'size' => 10, 'mime' => 'application/pdf']];
        $id = $repo->create($this->post($boardId, '글') + ['attachments' => $files]);

        $this->assertSame($files, $repo->find($id)['attachments']);
    }

    /** @return array{0: PostRepository, 1: int} */
    private function setUpBoard(array $config): array
    {
        $db = $this->freshDatabase($config);
        $boardId = (new BoardRepository($db))->create(['board_key' => 'free', 'name' => '자유']);

        return [new PostRepository($db), $boardId];
    }

    private function post(int $boardId, string $title): array
    {
        return [
            'board_id'    => $boardId,
            'title'       => $title,
            'content'     => '본문',
            'author_id'   => 'user-1',
            'author_name' => '홍길동',
        ];
    }

    private function guestPost(int $boardId, string $title): array
    {
        return [
            'board_id'       => $boardId,
            'title'          => $title,
            'content'        => '본문',
            'author_id'      => null,
            'author_name'    => '손님',
            'guest_password' => password_hash('1234', PASSWORD_DEFAULT),
        ];
    }
}
