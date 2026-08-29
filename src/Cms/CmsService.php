<?php

declare(strict_types=1);

namespace GnuCms\Cms;

use GnuCms\Auth\Acl;
use GnuCms\Error\DomainError;
use GnuCms\Validation\Validator;

final class CmsService
{
    public const DEFAULT_SETTINGS = [
        'site_name' => GNUCMS,
        'site_tagline' => '가볍게 시작하는 기초 커뮤니티',
        'home_title' => '가볍게 시작하고, 오래 이어지는 공간',
        'home_intro' => '필요한 페이지와 커뮤니티를 한곳에서 운영하세요.',
        'registration_enabled' => '1',
        'theme' => 'codex-preline',
    ];

    private CmsRepository $cms;

    private HtmlSanitizer $sanitizer;
    private ?ContentImageService $images;

    public function __construct(CmsRepository $cms, ?HtmlSanitizer $sanitizer = null,
        ?ContentImageService $images = null)
    {
        $this->cms = $cms;
        $this->sanitizer = $sanitizer ?? new HtmlSanitizer();
        $this->images = $images;
    }

    public function settings(): array
    {
        try {
            $stored = $this->cms->settings();
        } catch (DomainError $e) {
            // 아직 CMS 마이그레이션 전인 기존 설치도 오류 화면과 설치 진입점을
            // 정상적으로 열 수 있어야 한다. 실제 요청의 DB 오류는 각 기능에서 처리한다.
            $stored = [];
        }
        $settings = array_merge(self::DEFAULT_SETTINGS, $stored);
        $settings['registration_enabled'] = $settings['registration_enabled'] === '1';
        return $settings;
    }

    public function menu(): array
    {
        try {
            return $this->cms->publishedMenuPages();
        } catch (DomainError $e) {
            return [];
        }
    }

    /**
     * 가입 화면에 붙는 동의 항목. consent_key 가 있고 공개된 내용만, 정한 차례대로.
     * 개수 제한이 없다. 이용약관·개인정보는 그중 씨앗으로 심어 둔 둘일 뿐이다.
     */
    public function consentDocuments(): array
    {
        return $this->cms->listConsentDocuments(true);
    }

    /** 바닥글 등이 쓰는 필수 약관 두 개. 없으면 가입을 받지 않는다. */
    public function legalDocuments(): array
    {
        $terms = $this->cms->findPublishedBySlug('terms');
        $privacy = $this->cms->findPublishedBySlug('privacy');
        if ($terms === null || $privacy === null) {
            throw DomainError::forbidden('회원가입을 받으려면 이용약관과 개인정보 처리방침을 먼저 작성하고 공개해야 합니다.');
        }
        return ['terms' => $terms, 'privacy' => $privacy];
    }

    public function ensureLegalDrafts(Acl $acl): void
    {
        $acl->assertGlobalAdmin();
        $siteName = (string) $this->settings()['site_name'];
        if ($this->cms->findBySlug('terms') === null) {
            $this->cms->createPage([
                'slug' => 'terms', 'title' => '이용약관', 'seo_description' => $siteName . ' 서비스 이용약관',
                'content' => $this->termsDraft($siteName), 'status' => 'draft', 'show_in_menu' => 0, 'sort_order' => 900,
                'consent_key' => 'terms', 'consent_order' => 10, 'consent_required' => 1,
            ]);
        }
        if ($this->cms->findBySlug('privacy') === null) {
            $this->cms->createPage([
                'slug' => 'privacy', 'title' => '개인정보 처리방침',
                'seo_description' => $siteName . ' 개인정보 처리방침',
                'content' => $this->privacyDraft($siteName), 'status' => 'draft', 'show_in_menu' => 0, 'sort_order' => 910,
                'consent_key' => 'privacy', 'consent_order' => 20, 'consent_required' => 1,
            ]);
        }
    }

    public function pages(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        return $this->cms->listPages();
    }

    /** 약관도 이제 여기에 함께 나온다. 따로 걸러 내지 않는다. */
    public function contents(Acl $acl): array
    {
        return $this->pages($acl);
    }

    public function trash(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        return $this->cms->listDeletedPages();
    }

    public function legalOverview(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        return [
            'terms' => $this->cms->findBySlug('terms'),
            'privacy' => $this->cms->findBySlug('privacy'),
        ];
    }

    public function page(Acl $acl, int $id): array
    {
        $acl->assertGlobalAdmin();
        $page = $this->cms->findPageById($id);
        if ($page === null) {
            throw DomainError::notFound('내용을 찾을 수 없습니다.');
        }
        return $page;
    }

    public function publishedPage(string $slug): array
    {
        $page = $this->cms->findPublishedBySlug($slug);
        if ($page === null) {
            throw DomainError::notFound('내용을 찾을 수 없습니다.');
        }
        return $page;
    }

    public function saveSettings(Acl $acl, array $input): void
    {
        $acl->assertGlobalAdmin();
        $v = new Validator($input);
        $theme = strtolower($v->requiredString('theme', 50));
        if ($theme !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $theme) !== 1) {
            $v->fail('theme', '템플릿 이름이 올바르지 않습니다.');
        }
        $settings = [
            'site_name' => $v->requiredString('site_name', 50),
            'site_tagline' => $v->requiredString('site_tagline', 120),
            'home_title' => $v->requiredString('home_title', 120),
            'home_intro' => $v->requiredString('home_intro', 500),
            'registration_enabled' => $v->bool('registration_enabled', false) ? '1' : '0',
            'theme' => $theme,
        ];
        $v->check();
        $this->cms->saveSettings($settings);
    }

    public function createPage(Acl $acl, array $input): int
    {
        $acl->assertGlobalAdmin();
        $data = $this->validatePage($input);
        if ($this->cms->findBySlug($data['slug']) !== null) {
            throw DomainError::validation(['slug' => '이미 사용 중인 주소입니다.']);
        }
        $id = $this->cms->createPage($data);
        $this->syncImages($data);
        return $id;
    }

    public function updatePage(Acl $acl, int $id, array $input): void
    {
        $page = $this->page($acl, $id);
        $data = $this->validatePage($input);
        $sameSlug = $this->cms->findBySlug($data['slug']);
        if ($sameSlug !== null && (int) $sameSlug['id'] !== $id) {
            throw DomainError::validation(['slug' => '이미 사용 중인 주소입니다.']);
        }
        $this->cms->updatePage($id, $data, $page['published_at']);
        $this->syncImages($data);
    }

    public function deletePage(Acl $acl, int $id): void
    {
        $page = $this->page($acl, $id);
        // 가입 동의 항목을 지우면 그때부터 가입이 막힌다. 표시를 먼저 떼도록 안내한다.
        if (($page['consent_key'] ?? null) !== null) {
            throw DomainError::validation([
                'consent_key' => '가입 동의 항목으로 쓰는 내용은 지울 수 없습니다. 먼저 동의 항목 표시를 떼어 주세요.',
            ]);
        }
        $this->cms->deletePage($id);
    }

    public function restorePage(Acl $acl, int $id): void
    {
        $acl->assertGlobalAdmin();
        $page = $this->cms->findDeletedPageById($id);
        if ($page === null) {
            throw DomainError::notFound('휴지통에서 내용을 찾을 수 없습니다.');
        }
        $this->cms->restorePage($id);
    }

    public function permanentlyDeletePage(Acl $acl, int $id): void
    {
        $acl->assertGlobalAdmin();
        $page = $this->cms->findDeletedPageById($id);
        if ($page === null) {
            throw DomainError::notFound('휴지통에서 내용을 찾을 수 없습니다.');
        }
        $key = (string) ($page['image_key'] ?? '');
        if ($key !== '' && $this->images !== null) {
            $this->images->deleteFolder($key);
        }
        $this->cms->permanentlyDeletePage($id);
    }

    public function countPages(): int
    {
        return count($this->cms->listPages());
    }

    private function validatePage(array $input): array
    {
        $v = new Validator($input);
        $slug = strtolower($v->requiredString('slug', 100));
        if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
            $v->fail('slug', '영문 소문자나 숫자로 시작하고, 소문자·숫자·밑줄·하이픈만 쓸 수 있습니다.');
        }
        $content = $v->requiredString('content', 50000);
        $cleanContent = $this->sanitizer->clean($content);
        $visibleContent = trim(html_entity_decode(strip_tags(str_replace(['&nbsp;', '<br>'], [' ', "\n"], $cleanContent)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($content !== '' && $visibleContent === '' && stripos($cleanContent, '<img ') === false) {
            $v->fail('content', '내용을 입력해 주세요.');
        }
        $imageKey = strtolower((string) $v->optionalString('image_key', 32));
        if ($imageKey === '') {
            $imageKey = bin2hex(random_bytes(16));
        }
        $data = [
            'slug' => $slug,
            'title' => $v->requiredString('title', 200),
            'content' => $cleanContent,
            'seo_description' => $v->optionalString('seo_description', 300),
            'status' => $v->inList('status', ['draft', 'published'], 'draft'),
            'show_in_menu' => $v->bool('show_in_menu', false) ? 1 : 0,
            'sort_order' => $v->int('sort_order', 0, -9999, 9999),
            'image_key' => $imageKey,
        ];
        // 동의 항목 칸은 폼에 있을 때만 반영한다. 그 칸이 없는 화면에서 저장해도
        // 이미 정해 둔 동의 설정이 조용히 지워지지 않는다.
        if (array_key_exists('consent_key', $input)) {
            $key = strtolower(trim((string) $input['consent_key']));
            if ($key !== '' && preg_match('/^[a-z][a-z0-9_-]{0,19}$/D', $key) !== 1) {
                $v->fail('consent_key', '영문 소문자로 시작하고 소문자·숫자·밑줄·하이픈만 쓸 수 있습니다.');
            }
            $data['consent_key'] = $key === '' ? null : $key;
            $data['consent_order'] = $v->int('consent_order', 0, -9999, 9999);
            // 체크를 풀면 선택 동의가 된다. 마케팅 수신처럼 안 해도 가입은 되는 항목이다.
            $data['consent_required'] = $v->bool('consent_required', false) ? 1 : 0;
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $data['image_key']) !== 1) {
            $v->fail('image_key', '이미지 저장 정보를 확인할 수 없습니다.');
        }
        $v->check();
        return $data;
    }

    private function syncImages(array $data): void
    {
        if ($this->images !== null) {
            $this->images->sync((string) $data['image_key'], (string) $data['content']);
        }
    }

    private function termsDraft(string $siteName): string
    {
        return "[공개 전에 대괄호 안내를 실제 운영 정보에 맞게 수정하세요.]\n\n"
            . "제1조 목적\n이 약관은 {$siteName}(이하 ‘서비스’)의 이용 조건과 운영자 및 회원의 권리·의무를 정합니다.\n\n"
            . "제2조 회원가입\n회원은 정확한 이메일을 사용해 가입하며, 타인의 정보를 도용해서는 안 됩니다.\n\n"
            . "제3조 회원의 의무\n회원은 법령을 위반하거나 다른 이용자의 권리를 침해하는 게시물을 작성해서는 안 됩니다.\n\n"
            . "제4조 게시물과 운영\n운영자는 불법 정보, 권리 침해, 서비스 운영을 방해하는 게시물을 제한하거나 삭제할 수 있습니다. 구체적인 처리 기준과 이의제기 방법은 [운영 정책 또는 연락처]를 따릅니다.\n\n"
            . "제5조 서비스 변경과 중단\n점검, 장애 또는 운영상 필요한 경우 서비스를 변경하거나 중단할 수 있으며 가능한 경우 미리 알립니다.\n\n"
            . "제6조 탈퇴\n회원은 언제든 탈퇴를 요청할 수 있습니다. 관련 법령상 보존 의무가 있는 정보는 정해진 기간 후 삭제합니다.\n\n"
            . "제7조 책임\n운영자와 회원은 자신의 귀책사유로 상대방에게 발생한 손해에 대해 관계 법령에 따라 책임을 집니다.\n\n"
            . "제8조 문의\n운영자: [운영자명]\n연락처: [이메일]\n시행일: [YYYY-MM-DD]";
    }

    private function privacyDraft(string $siteName): string
    {
        return "[공개 전에 대괄호 안내를 실제 운영 정보와 처리 현황에 맞게 수정하세요.]\n\n"
            . "{$siteName} 개인정보 처리방침\n\n"
            . "1. 처리 목적\n회원 식별과 로그인, 커뮤니티 운영, 문의 대응, 부정 이용 방지를 위해 개인정보를 처리합니다.\n\n"
            . "2. 처리 항목\n필수: 이메일 주소, 비밀번호(복호화할 수 없는 해시 형태), 이메일에서 생성된 표시 이름\n자동 생성 정보: [접속 기록 등 실제 수집 항목을 기재]\n\n"
            . "3. 보유 및 이용 기간\n회원 탈퇴 시 지체 없이 삭제합니다. 다만 관계 법령에 보존 의무가 있거나 분쟁 대응에 필요한 경우 해당 기간 동안 분리 보관합니다. [구체적인 보존 항목과 기간 기재]\n\n"
            . "4. 동의 거부 권리\n개인정보 수집·이용에 동의하지 않을 수 있으나, 필수 정보 처리를 거부하면 회원가입과 회원 서비스를 이용할 수 없습니다.\n\n"
            . "5. 제3자 제공 및 처리위탁\n[제3자 제공 또는 처리위탁이 없다면 ‘없음’으로, 있다면 업체·업무·보유기간을 기재]\n\n"
            . "6. 파기 절차 및 방법\n보유 기간이 끝난 개인정보는 복구할 수 없는 방법으로 안전하게 삭제합니다.\n\n"
            . "7. 정보주체의 권리\n이용자는 개인정보 열람, 정정, 삭제, 처리정지 및 동의 철회를 요청할 수 있습니다.\n\n"
            . "8. 안전성 확보 조치\n접근 권한 관리, 비밀번호 암호화, 보안 업데이트 등 필요한 보호조치를 시행합니다.\n\n"
            . "9. 개인정보 보호 문의\n담당자: [이름 또는 담당 부서]\n연락처: [이메일]\n\n"
            . "시행일: [YYYY-MM-DD]";
    }
}
