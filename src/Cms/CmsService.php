<?php

declare(strict_types=1);

namespace GnuCms\Cms;

use GnuCms\Account\ConsentRepository;
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

    private ConsentUseRepository $uses;

    private ConsentRepository $consents;

    public function __construct(
        CmsRepository $cms,
        HtmlSanitizer $sanitizer,
        ContentImageService $images,
        ConsentUseRepository $uses,
        ConsentRepository $consents
    ) {
        $this->cms = $cms;
        $this->sanitizer = $sanitizer;
        $this->images = $images;
        $this->uses = $uses;
        $this->consents = $consents;
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
     * 한 자리에 붙은 동의 항목. 공개된 것만, 정한 차례대로. 개수 제한이 없다.
     * 필수·선택은 약관이 아니라 붙임이 갖는다.
     */
    public function consentDocuments(string $scope = 'signup'): array
    {
        return $this->uses->listForScope($scope, true);
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
        $seeds = [
            ['terms', '이용약관', $siteName . ' 서비스 이용약관', $this->termsDraft($siteName), 900, 10],
            ['privacy', '개인정보 처리방침', $siteName . ' 개인정보 처리방침',
             $this->privacyDraft($siteName), 910, 20],
        ];
        foreach ($seeds as [$slug, $title, $seo, $body, $sort, $order]) {
            $page = $this->cms->findBySlug($slug);
            if ($page === null) {
                $id = $this->cms->createPage([
                    'slug' => $slug, 'title' => $title, 'seo_description' => $seo,
                    'content' => $body, 'status' => 'draft', 'show_in_menu' => 0,
                    'sort_order' => $sort, 'is_consent' => 1,
                ]);
            } else {
                $id = (int) $page['id'];
                // 옛 판에서 손수 만든 terms 페이지는 표시가 없어서, 붙이기만 하면
                // 약관 관리 목록에도 가입 화면에도 안 보이는 유령 붙임이 된다.
                if ((int) ($page['is_consent'] ?? 0) !== 1) {
                    $this->cms->markConsent($id);
                }
            }
            // 씨앗 둘은 회원가입에 반드시 붙는다. 없으면 가입 자체를 받지 않는다.
            $this->uses->attach('signup', $id, true, $order);
        }
    }

    /** 내용 관리 목록. 약관은 약관 관리에서 다루므로 여기서 뺀다. */
    public function contents(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        return $this->cms->listPages(false);
    }

    /**
     * 약관 관리 목록. 그 자리의 붙임과 동의 수를 합쳐 준다.
     *
     * 붙임을 여기서 골라 주는 이유는 Twig 의 {% set %} 이 for 밖으로 새지 않아
     * 템플릿 안에서 "이 약관이 이 자리에 붙었나" 를 고를 수 없기 때문이다.
     */
    public function consentPages(Acl $acl, string $scope = 'signup'): array
    {
        $acl->assertGlobalAdmin();
        $rows = [];
        foreach ($this->cms->listPages(true) as $page) {
            $id = (int) $page['id'];
            $uses = $this->uses->listForContent($id);
            $page['uses'] = $uses;
            $page['use'] = null;
            foreach ($uses as $use) {
                if ((string) $use['scope'] === $scope) {
                    $page['use'] = $use;
                    break;
                }
            }
            $page['counts'] = $this->consents->countsForContent($id);
            $rows[] = $page;
        }
        return $rows;
    }

    public function trash(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        return $this->cms->listDeletedPages();
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
        // 약관 표시를 껐으면 붙임도 함께 걷는다. 붙임이 남으면 약관 관리 목록에서
        // 사라져 화면에서 뗄 길이 없고, 가입 화면에 유령 항목이 남는다.
        if (array_key_exists('is_consent', $data)
            && (int) $data['is_consent'] === 0 && (int) ($page['is_consent'] ?? 0) === 1) {
            $this->uses->detachContent($id);
        }
        $this->syncImages($data);
    }

    public function deletePage(Acl $acl, int $id): void
    {
        $page = $this->page($acl, $id);
        // 붙어 있는 약관을 지우면 그 자리의 가입·신청이 그때부터 막힌다.
        if ($this->uses->listForContent($id) !== []) {
            throw DomainError::validation([
                'is_consent' => '어딘가에 붙어 있는 약관은 지울 수 없습니다. 먼저 붙임을 떼어 주세요.',
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
        // 약관 여부는 폼에 있을 때만 반영한다. 그 칸이 없는 화면에서 저장해도
        // 이미 정해 둔 표시가 조용히 지워지지 않는다.
        if (array_key_exists('is_consent', $input)) {
            $data['is_consent'] = $v->bool('is_consent', false) ? 1 : 0;
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
        // 뒤에 자동 수집 안내(HTML)를 이어 붙이면서 문서 전체가 태그를 포함하게 됐다.
        // HtmlSanitizer::clean() 은 태그가 하나라도 있으면 순수 텍스트용 줄바꿈 처리를
        // 건너뛰므로, 앞쪽도 <p>/<h2> 로 직접 문단을 나눠 줘야 1~9항이 한 문단으로
        // 뭉개지지 않는다. 사이트 이름은 관리자가 자유롭게 입력하므로 이스케이프한다.
        $safeName = htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<p>[공개 전에 대괄호 안내를 실제 운영 정보와 처리 현황에 맞게 수정하세요.]</p>'
            . "<h2>{$safeName} 개인정보 처리방침</h2>"
            . '<p>1. 처리 목적<br>회원 식별과 로그인, 커뮤니티 운영, 문의 대응, 부정 이용 방지를 위해 개인정보를 처리합니다.</p>'
            . '<p>2. 처리 항목<br>필수: 이메일 주소, 비밀번호(복호화할 수 없는 해시 형태), 이메일에서 생성된 표시 이름'
            . '<br>자동 생성 정보: [접속 기록 등 실제 수집 항목을 기재]</p>'
            . '<p>3. 보유 및 이용 기간<br>회원 탈퇴 시 지체 없이 삭제합니다. 다만 관계 법령에 보존 의무가 있거나 분쟁 대응에'
            . ' 필요한 경우 해당 기간 동안 분리 보관합니다. [구체적인 보존 항목과 기간 기재]</p>'
            . '<p>4. 동의 거부 권리<br>개인정보 수집·이용에 동의하지 않을 수 있으나, 필수 정보 처리를 거부하면'
            . ' 회원가입과 회원 서비스를 이용할 수 없습니다.</p>'
            . '<p>5. 제3자 제공 및 처리위탁<br>[제3자 제공 또는 처리위탁이 없다면 ‘없음’으로, 있다면 업체·업무·보유기간을 기재]</p>'
            . '<p>6. 파기 절차 및 방법<br>보유 기간이 끝난 개인정보는 복구할 수 없는 방법으로 안전하게 삭제합니다.</p>'
            . '<p>7. 정보주체의 권리<br>이용자는 개인정보 열람, 정정, 삭제, 처리정지 및 동의 철회를 요청할 수 있습니다.</p>'
            . '<p>8. 안전성 확보 조치<br>접근 권한 관리, 비밀번호 암호화, 보안 업데이트 등 필요한 보호조치를 시행합니다.</p>'
            . '<p>9. 개인정보 보호 문의<br>담당자: [이름 또는 담당 부서]<br>연락처: [이메일]</p>'
            . '<h2>자동으로 수집하는 정보</h2>'
            . '<p>회원가입과 각종 신청에서 동의를 받을 때, 동의를 받았다는 사실을 증명하기 위해'
            . ' 접속 IP 주소와 접속 일시, 브라우저 정보를 함께 기록합니다. 이 정보는 동의 사실'
            . ' 증명과 부정 이용 방지 목적으로만 쓰며, 다른 목적으로 이용하지 않습니다.</p>'
            . '<p>보관기간: 회원 동의 기록은 탈퇴 시 함께 파기하고, 비회원 신청 건의 동의 기록은'
            . ' 해당 신청 건의 보관기간이 지나면 파기합니다.</p>'
            . '<p>시행일: [YYYY-MM-DD]</p>';
    }
}
