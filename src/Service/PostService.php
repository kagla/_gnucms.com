<?php

declare(strict_types=1);

namespace GnuCms\Service;

use GnuCms\Auth\Acl;
use GnuCms\Cms\ContentImageService;
use GnuCms\Cms\ContentRenderer;
use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Error\DomainError;
use GnuCms\Repository\PostRepository;
use GnuCms\Validation\Validator;

final class PostService
{
    /** @var BoardService */
    private $boards;

    /** @var PostRepository */
    private $posts;

    /** @var AttachmentService|null 순환 의존을 피하려고 나중에 주입한다 */
    private $attachments = null;

    /** @var callable|null 첨부 검증이 실제로 필요한 순간에 attachments 를 지연 연결한다 */
    private $attachmentResolver = null;

    /** @var HtmlSanitizer */
    private $sanitizer;

    /** @var ContentImageService */
    private $images;

    /** 글당 첨부 개수 한도. 0 = 무제한. App 이 사이트 설정에서 넣는다. */
    private int $attachmentLimit = 0;

    public function __construct(
        BoardService $boards,
        PostRepository $posts,
        HtmlSanitizer $sanitizer,
        ContentImageService $images
    ) {
        $this->boards = $boards;
        $this->posts = $posts;
        $this->sanitizer = $sanitizer;
        $this->images = $images;
    }

    /**
     * 본문은 편집기가 보내는 HTML 이다. 저장 시점에 한 번 정화해 두고,
     * 출력에서도 cms_html 로 한 번 더 거른다. 평문이 들어오면 정화기가 문단으로 감싼다.
     */
    private function cleanContent(string $raw): string
    {
        return $this->sanitizer->clean($raw);
    }

    /** 편집기가 올린 이미지를 묶는 폴더 이름. 형식이 어긋나면 저장을 막는다. */
    private function editorImageKey(Validator $v, array $input): ?string
    {
        if (!array_key_exists('image_key', $input)) {
            return null;
        }
        $key = strtolower((string) $v->optionalString('image_key', 32));
        if ($key === '') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            $v->fail('image_key', '이미지 저장 정보를 확인할 수 없습니다.');

            return null;
        }

        return $key;
    }

    public function setAttachmentService(AttachmentService $attachments): void
    {
        $this->attachments = $attachments;
    }

    /**
     * App::postService() 가 넣어 준다. 첨부 검증이 실제로 필요해질 때(요청 프로세스마다
     * 한 번) 이 콜백이 App::attachments() 를 불러 setAttachmentService() 를 부수효과로
     * 일으킨다. 컨트롤러가 매번 App::attachments() 를 미리 불러 둬야 한다는 계약을
     * 잊어버려 500 이 나던 문제(e84ba23)를 서비스 안에서 없앤다.
     */
    public function setAttachmentResolver(callable $resolver): void
    {
        $this->attachmentResolver = $resolver;
    }

    public function setAttachmentLimit(int $limit): void
    {
        $this->attachmentLimit = max(0, $limit);
    }

    public function listPosts(Acl $acl, string $boardKey, array $query): array
    {
        $board = $this->boards->getEntity($acl, $boardKey);

        $v = new Validator($query);
        $page = $v->int('page', 1, 1, 100000);
        // per_page 최솟값을 너무 낮게 두면 total_pages 가 글 수만큼 커진다. 목록
        // 화면의 페이지 번호는 그 값만큼 링크를 그리므로, 값이 작을수록 요청 하나로
        // 만들어지는 링크 수가 늘어난다 (예: per_page=1 인 대형 게시판).
        $perPage = $v->int('per_page', (int) $board['per_page'], 10, 100);
        $q = $v->optionalString('q', 100);
        $category = $v->optionalString('category', 50);
        $includeDeleted = $v->bool('include_deleted', false);
        $v->check();

        // 관리 권한이 없으면 조용히 무시한다. 오류로 만들 이유가 없다.
        $includeDeleted = $includeDeleted && $acl->isAdminFor($board);

        $result = $this->posts->paginate((int) $board['id'], $page, $perPage, $q, $category, $includeDeleted);

        $summaries = [];
        foreach ($result['rows'] as $row) {
            $summaries[] = $this->summary($row);
        }

        $notices = [];
        foreach ($this->posts->notices((int) $board['id']) as $row) {
            $notices[] = $this->summary($row);
        }

        return [
            'data'        => $summaries,
            'notices'     => $notices,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $result['total'],
            'total_pages' => $result['total'] === 0 ? 0 : (int) ceil($result['total'] / $perPage),
        ];
    }

    /** 메인 화면용으로 게시판의 최신 글 요약을 제한된 개수만 돌려준다. */
    /**
     * 관리 화면의 전체 글 목록. 게시판 경계를 넘으므로 사이트 관리 권한이 필요하다.
     * 각 글에 board_key/board_name 을 붙여 어느 게시판 글인지 바로 알 수 있게 한다.
     */
    /**
     * 게시판을 넘나드는 전체 글. 읽을 수 있는 게시판의 글만, 최신순으로.
     * 관리 콘솔의 listAllPosts() 와 달리 누구나 부를 수 있고 지운 글은 안 보인다.
     */
    public function listRecentPosts(Acl $acl, array $query): array
    {
        $v = new Validator($query);
        $page = $v->int('page', 1, 1, 100000);
        $q = $v->optionalString('q', 100);
        $v->check();
        $perPage = 20;

        $boards = [];
        foreach ($this->boards->listBoards($acl) as $board) {
            $boards[(int) $board['id']] = $board;
        }

        $result = $this->posts->paginateAll($page, $perPage, $q, null, false, array_keys($boards));

        $rows = [];
        foreach ($result['rows'] as $row) {
            $summary = $this->summary($row);
            $board = $boards[(int) $row['board_id']];
            $summary['board_key'] = $board['board_key'];
            $summary['board_name'] = $board['name'];
            $rows[] = $summary;
        }

        return [
            'data'        => $rows,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $result['total'],
            'total_pages' => $result['total'] === 0 ? 0 : (int) ceil($result['total'] / $perPage),
        ];
    }

    public function listAllPosts(Acl $acl, array $query): array
    {
        $acl->assertGlobalAdmin();

        $v = new Validator($query);
        $page = $v->int('page', 1, 1, 100000);
        $perPage = $v->int('per_page', 30, 10, 100);
        $q = $v->optionalString('q', 100);
        $boardKey = $v->optionalString('board', 50);
        $includeDeleted = $v->bool('include_deleted', false);
        $v->check();

        $boards = [];
        foreach ($this->boards->listBoards($acl) as $board) {
            $boards[(int) $board['id']] = $board;
        }

        $boardId = null;
        if ($boardKey !== null && $boardKey !== '') {
            $entity = $this->boards->getEntity($acl, $boardKey);
            $boardId = (int) $entity['id'];
        }

        $result = $this->posts->paginateAll($page, $perPage, $q, $boardId, $includeDeleted);

        $rows = [];
        foreach ($result['rows'] as $row) {
            $summary = $this->summary($row);
            $board = $boards[(int) $row['board_id']] ?? null;
            $summary['board_key'] = $board['board_key'] ?? null;
            $summary['board_name'] = $board['name'] ?? '(삭제된 게시판)';
            $rows[] = $summary;
        }

        return [
            'data'        => $rows,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $result['total'],
            'total_pages' => $result['total'] === 0 ? 0 : (int) ceil($result['total'] / $perPage),
        ];
    }

    public function latestPosts(Acl $acl, string $boardKey, int $limit = 5): array
    {
        $board = $this->boards->getEntity($acl, $boardKey);
        $summaries = [];

        foreach ($this->posts->latest((int) $board['id'], $limit) as $row) {
            $summaries[] = $this->summary($row);
        }

        return $summaries;
    }

    /** @return array{post: array, board: array} */
    public function loadForRead(Acl $acl, int $id, ?string $password): array
    {
        $post = $this->posts->findWithSecret($id);
        if ($post === null) {
            throw DomainError::notFound('글을 찾을 수 없습니다.');
        }

        $board = $this->boards->getEntity($acl, $this->boardKeyOf($post));

        if ($post['deleted_at'] !== null && !$acl->isAdminFor($board)) {
            throw DomainError::notFound('글을 찾을 수 없습니다.');
        }

        if ((int) $post['is_secret'] === 1 && !$acl->verifySecret($board, $post, $password)) {
            throw DomainError::forbidden('비밀글입니다.');
        }

        return ['post' => $post, 'board' => $board];
    }

    public function get(Acl $acl, int $id, ?string $password): array
    {
        $loaded = $this->loadForRead($acl, $id, $password);
        $post = $loaded['post'];

        $sub = $acl->identity()->sub();
        $isAuthor = $sub !== null && $post['author_id'] !== null && (string) $post['author_id'] === $sub;
        if (!$isAuthor) {
            $this->posts->incrementViews($id);
            $post = $this->posts->findWithSecret($id);
        }

        return $this->detail($post);
    }

    public function create(Acl $acl, string $boardKey, array $input): array
    {
        $board = $this->boards->getEntity($acl, $boardKey);
        $acl->assertCanWrite($board);

        $v = new Validator($input);
        $data = [
            'board_id' => (int) $board['id'],
            'title'    => $v->requiredString('title', 200),
            'content'  => $this->cleanContent($v->requiredString('content')),
        ];
        $data['image_key'] = $this->editorImageKey($v, $input);

        $identity = $acl->identity();
        if ($identity->isGuest()) {
            // 비회원 글: author_id 는 NULL 이고 비밀번호가 소유 증명 수단이 된다.
            $data['author_id'] = null;
            $data['author_name'] = $v->requiredString('author_name', 20);
            $password = $v->requiredPassword('password');
            $data['guest_password'] = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
        } else {
            // 로그인 사용자는 요청의 author_name 을 무시한다. 사칭 방지.
            $data['author_id'] = $identity->sub();
            $data['author_name'] = (string) $identity->displayName();
            $data['guest_password'] = null;
        }

        $data['category'] = $this->validateCategory($v, $board, $input);
        $data['is_secret'] = $this->validateSecret($v, $board, $v->bool('is_secret', false)) ? 1 : 0;
        $data['is_notice'] = 0;

        if (array_key_exists('is_notice', $input) && $v->bool('is_notice', false)) {
            $acl->assertAdminFor($board);
            $data['is_notice'] = 1;
        }

        if (array_key_exists('attachments', $input)) {
            $data['attachments'] = $this->verifyAttachments($board, $input['attachments']);
        }

        $v->check();

        $id = $this->posts->create($data);
        // 본문에 남지 않은 이미지는 지운다. 편집 중 올렸다가 지운 것들이다.
        if ($data['image_key'] !== null) {
            $this->images->sync((string) $data['image_key'], (string) $data['content']);
        }

        return $this->detail($this->posts->findWithSecret($id));
    }

    public function update(Acl $acl, int $id, array $input): array
    {
        $post = $this->posts->findWithSecret($id);
        if ($post === null) {
            throw DomainError::notFound('글을 찾을 수 없습니다.');
        }
        $board = $this->boards->getEntity($acl, $this->boardKeyOf($post));

        $v = new Validator($input);
        $password = $v->optionalPassword('password');
        $acl->assertCanModify($board, $post, $password);

        $data = [];
        if (array_key_exists('title', $input)) {
            $data['title'] = $v->requiredString('title', 200);
        }
        if (array_key_exists('content', $input)) {
            $data['content'] = $this->cleanContent($v->requiredString('content'));
        }
        $updateKey = $this->editorImageKey($v, $input);
        if ($updateKey !== null) {
            $data['image_key'] = $updateKey;
        }
        if (array_key_exists('category', $input)) {
            $data['category'] = $this->validateCategory($v, $board, $input);
        }
        if (array_key_exists('is_secret', $input)) {
            $data['is_secret'] = $this->validateSecret($v, $board, $v->bool('is_secret', false)) ? 1 : 0;
        }
        if (array_key_exists('is_notice', $input)) {
            $acl->assertAdminFor($board);
            $data['is_notice'] = $v->bool('is_notice', false) ? 1 : 0;
        }

        if (array_key_exists('attachments', $input)) {
            $data['attachments'] = $this->verifyAttachments($board, $input['attachments']);
        }

        $v->check();

        if ($data !== []) {
            $this->posts->update($id, $data);
        }
        $syncKey = $updateKey ?? (($post['image_key'] ?? null) ? (string) $post['image_key'] : null);
        if ($syncKey !== null && array_key_exists('content', $data)) {
            $this->images->sync($syncKey, (string) $data['content']);
        }

        return $this->detail($this->posts->findWithSecret($id));
    }

    public function delete(Acl $acl, int $id, ?string $password): void
    {
        $post = $this->posts->findWithSecret($id);
        if ($post === null) {
            throw DomainError::notFound('글을 찾을 수 없습니다.');
        }
        $board = $this->boards->getEntity($acl, $this->boardKeyOf($post));

        $acl->assertCanModify($board, $post, $password);

        $this->posts->softDelete($id);
    }

    public function restore(Acl $acl, int $id): array
    {
        $post = $this->posts->findWithSecret($id);
        if ($post === null) {
            throw DomainError::notFound('글을 찾을 수 없습니다.');
        }
        $board = $this->boards->getEntity($acl, $this->boardKeyOf($post));

        $acl->assertAdminFor($board);
        $this->posts->restore($id);

        return $this->detail($this->posts->findWithSecret($id));
    }

    private function boardKeyOf(array $post): string
    {
        $board = $this->boardsRepositoryLookup((int) $post['board_id']);

        return (string) $board['board_key'];
    }

    /** 댓글 서비스가 소속 게시판을 찾을 때 쓴다. */
    public function boardById(int $boardId): ?array
    {
        return $this->boards->findBoardById($boardId);
    }

    private function boardsRepositoryLookup(int $boardId): array
    {
        $board = $this->boards->findBoardById($boardId);
        if ($board === null) {
            throw DomainError::notFound('게시판을 찾을 수 없습니다.');
        }

        return $board;
    }

    private function verifyAttachments(array $board, $input): array
    {
        if (!is_array($input)) {
            throw DomainError::validation(['attachments' => '배열이어야 합니다.']);
        }
        // resolver 는 개수 한도 검사보다 먼저 돌려야 한다: App::attachments() 를 부르는
        // 부수효과로 $attachmentLimit 도 함께 설정되기 때문이다. 검사부터 먼저 하면
        // 지연 연결 전에는 한도가 기본값 0(무제한)으로 읽혀 한도 검사를 그냥 통과해 버린다.
        if ($this->attachments === null && $this->attachmentResolver !== null) {
            ($this->attachmentResolver)();
        }
        if ($input !== [] && (int) $board['use_file'] !== 1) {
            throw DomainError::validation(['attachments' => '이 게시판은 첨부를 쓰지 않습니다.']);
        }
        if ($this->attachmentLimit > 0 && count($input) > $this->attachmentLimit) {
            throw DomainError::validation(['attachments' => '첨부는 ' . $this->attachmentLimit . '개까지입니다.']);
        }
        if ($this->attachments === null) {
            throw DomainError::internal('첨부 서비스가 연결되지 않았습니다.');
        }

        $verified = [];
        foreach ($input as $descriptor) {
            $verified[] = $this->attachments->verify(is_array($descriptor) ? $descriptor : []);
        }

        return $verified;
    }

    private function validateCategory(Validator $v, array $board, array $input): ?string
    {
        $category = $v->optionalString('category', 50);
        $usesCategory = (int) $board['use_category'] === 1 && $board['categories'] !== [];

        if ($category === null) {
            // 분류를 쓰는 게시판인데 고르지 않았다면 이유를 알려 준다.
            // 예전에는 조용히 통과시켜 분류 없는 글이 섞였다.
            if ($usesCategory) {
                $v->fail('category', '분류를 선택해 주세요.');
            }

            return null;
        }
        if ((int) $board['use_category'] !== 1) {
            $v->fail('category', '이 게시판은 분류를 쓰지 않습니다.');

            return null;
        }
        if (!in_array($category, $board['categories'], true)) {
            $v->fail('category', '게시판에 없는 분류입니다.');

            return null;
        }

        return $category;
    }

    private function validateSecret(Validator $v, array $board, bool $requested): bool
    {
        if ($requested && (int) $board['use_secret'] !== 1) {
            $v->fail('is_secret', '이 게시판은 비밀글을 쓰지 않습니다.');

            return false;
        }

        return $requested;
    }

    /** 갤러리·매거진·뉴스형 목록이 쓰는 발췌문 길이 */
    private const EXCERPT_LENGTH = 120;

    private function summary(array $row): array
    {
        $secret = (bool) $row['is_secret'];

        return [
            'id'            => (int) $row['id'],
            'category'      => $row['category'],
            'title'         => $row['title'],
            'author_id'     => $row['author_id'],
            'author_name'   => $row['author_name'],
            'is_notice'     => (bool) $row['is_notice'],
            'is_secret'     => $secret,
            'view_count'    => (int) $row['view_count'],
            'comment_count' => (int) $row['comment_count'],
            'file_count'    => count($row['attachments']),
            // 비밀글은 목록에서 본문과 사진을 흘리면 안 된다. 제목만 남긴다.
            'excerpt'         => $secret ? null : $this->excerpt((string) $row['content']),
            'thumbnail_index' => $secret ? null : $this->firstImageIndex($row['attachments']),
            'thumbnail_url'   => $secret ? null : $this->firstContentImage((string) $row['content']),
            'deleted'       => $row['deleted_at'] !== null,
            'created_at'    => $row['created_at'],
        ];
    }

    /** 본문 앞부분을 한 줄로 눌러 목록용 발췌문을 만든다. */
    private function excerpt(string $content): ?string
    {
        // 본문이 HTML 이므로 태그를 걷어내고 한 줄로 만든다.
        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= self::EXCERPT_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::EXCERPT_LENGTH) . '…';
    }

    /** 첨부 중 첫 이미지의 인덱스. 목록 썸네일 주소를 만들 때 쓴다. */
    /**
     * 본문에 넣은 첫 사진의 주소. 첨부가 없어도 목록에 썸네일을 보이려고 쓴다.
     *
     * 편집기가 올린 사진만 받아들인다. 본문에는 다른 사이트 주소도 들어올 수 있는데,
     * 그것을 목록에서 불러오면 방문자 정보가 그 사이트로 새어 나간다.
     */
    private function firstContentImage(string $content): ?string
    {
        if (!preg_match_all('/<img\b[^>]*\bsrc\s*=\s*"([^"]+)"/i', $content, $matches)) {
            return null;
        }

        foreach ($matches[1] as $raw) {
            $url = html_entity_decode($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            // 목록에는 카드 크기에 맞춘 축소본을 쓴다. 원본을 그대로 내보내면
            // 글 목록 한 화면에 수십 MB 가 오간다.
            $thumb = ContentRenderer::variantUrl($url, 'thumb');
            if ($thumb !== null) {
                return $thumb;
            }
        }

        return null;
    }

    private function firstImageIndex(array $attachments): ?int
    {
        foreach (array_values($attachments) as $index => $file) {
            $mime = (string) ($file['mime'] ?? '');
            if (str_starts_with($mime, 'image/')) {
                return $index;
            }
        }

        return null;
    }

    private function detail(array $row): array
    {
        $view = $this->summary($row);
        $view['content'] = $row['content'];
        $view['updated_at'] = $row['updated_at'];
        $view['attachments'] = [];
        foreach ($row['attachments'] as $index => $file) {
            // id·path 는 화면에 뿌리는 값이 아니라 수정 폼이 AttachmentService::withSignature()
            // 로 서명을 다시 붙여 hidden input 에 되싣는 재료다. 절대 템플릿에 그대로 찍지 않는다
            // (path 는 서버 파일 경로를 드러낸다).
            $view['attachments'][] = [
                'index' => $index,
                'id'    => $file['id'] ?? '',
                'name'  => $file['name'] ?? '',
                'size'  => (int) ($file['size'] ?? 0),
                'mime'  => $file['mime'] ?? 'application/octet-stream',
                'path'  => $file['path'] ?? '',
            ];
        }

        return $view;
    }
}
