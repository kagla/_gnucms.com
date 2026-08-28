<?php

declare(strict_types=1);

namespace ApiBoard;

use ApiBoard\Account\AccountService;
use ApiBoard\Account\UserRepository;
use ApiBoard\Account\TokenRepository;
use ApiBoard\Account\TokenService;
use ApiBoard\Account\IdentityRepository;
use ApiBoard\Account\LinkingService;
use ApiBoard\Account\SocialAuthService;
use ApiBoard\Account\AdminService;
use ApiBoard\Account\ConsentRepository;
use ApiBoard\Auth\Acl;
use ApiBoard\Auth\Identity;
use ApiBoard\Db\Connection;
use ApiBoard\Repository\BoardRepository;
use ApiBoard\Repository\CommentRepository;
use ApiBoard\Repository\NotificationRepository;
use ApiBoard\Repository\PostRepository;
use ApiBoard\Service\AttachmentService;
use ApiBoard\Service\BoardService;
use ApiBoard\Service\CommentService;
use ApiBoard\Service\NotificationService;
use ApiBoard\Service\PostService;
use ApiBoard\Mail\NativeMailer;
use ApiBoard\Mail\MailerInterface;
use ApiBoard\Mail\MailSettingsRepository;
use ApiBoard\Mail\MailSettingsService;
use ApiBoard\Mail\SecretCipher;
use ApiBoard\Mail\SmtpMailer;
use ApiBoard\Oauth\ProviderRegistry;
use ApiBoard\Cms\CmsRepository;
use ApiBoard\Cms\CmsService;
use ApiBoard\Cms\ContentImageService;
use ApiBoard\Cms\ContentRenderer;
use ApiBoard\Cms\HtmlSanitizer;

/**
 * 설정으로부터 객체 그래프를 조립한다. 컨테이너 라이브러리를 쓰지 않는 이유는
 * 객체 수가 열 개 남짓이고 런타임 의존성을 0 으로 유지해야 하기 때문이다.
 */
final class App
{
    /** @var array */
    private $config;

    /** @var Connection|null */
    private $db = null;

    /** @var BoardRepository|null */
    private $boards = null;

    /** @var PostRepository|null */
    private $posts = null;

    /** @var CommentRepository|null */
    private $comments = null;

    /** @var BoardService|null */
    private $boardService = null;

    /** @var PostService|null */
    private $postService = null;

    /** @var CommentService|null */
    private $commentService = null;

    /** @var NotificationRepository|null */
    private $notifications = null;

    /** @var NotificationService|null */
    private $notificationService = null;

    /** @var AttachmentService|null */
    private $attachmentService = null;

    /** @var UserRepository|null */
    private $users = null;

    /** @var AccountService|null */
    private $accountService = null;

    /** @var TokenRepository|null */
    private $tokens = null;

    private ?IdentityRepository $identities = null;

    private ?LinkingService $linkingService = null;

    private ?SocialAuthService $socialAuthService = null;

    private ?ProviderRegistry $providerRegistry = null;

    private ?MailerInterface $mailer = null;

    private ?MailSettingsRepository $mailSettings = null;

    private ?MailSettingsService $mailSettingsService = null;

    private ?AdminService $adminService = null;

    private ?CmsRepository $cms = null;

    private ?CmsService $cmsService = null;

    private ?ConsentRepository $consents = null;

    private ?HtmlSanitizer $htmlSanitizer = null;

    private ?ContentRenderer $contentRenderer = null;

    private ?ContentImageService $contentImages = null;

    /** @var Identity */
    private $identity;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->identity = Identity::guest();
    }

    /** 점 표기 경로로 설정을 읽는다. 예: config('auth.secret') */
    public function config(string $path, $default = null)
    {
        $node = $this->config;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public function db(): Connection
    {
        if ($this->db === null) {
            $this->db = Connection::create((array) $this->config('db', []));
        }

        return $this->db;
    }

    public function boards(): BoardRepository
    {
        if ($this->boards === null) {
            $this->boards = new BoardRepository($this->db());
        }

        return $this->boards;
    }

    public function posts(): PostRepository
    {
        if ($this->posts === null) {
            $this->posts = new PostRepository($this->db());
        }

        return $this->posts;
    }

    public function comments(): CommentRepository
    {
        if ($this->comments === null) {
            $this->comments = new CommentRepository($this->db());
        }

        return $this->comments;
    }

    public function notifications(): NotificationRepository
    {
        if ($this->notifications === null) {
            $this->notifications = new NotificationRepository($this->db());
        }

        return $this->notifications;
    }

    public function notificationService(): NotificationService
    {
        if ($this->notificationService === null) {
            $this->notificationService = new NotificationService(
                $this->notifications(),
                $this->posts(),
                $this->comments()
            );
        }

        return $this->notificationService;
    }

    public function boardService(): BoardService
    {
        if ($this->boardService === null) {
            $this->boardService = new BoardService($this->db(), $this->boards(), $this->posts(), $this->comments());
        }

        return $this->boardService;
    }

    public function postService(): PostService
    {
        if ($this->postService === null) {
            $this->postService = new PostService(
                $this->boardService(),
                $this->posts(),
                $this->htmlSanitizer(),
                $this->contentImages()
            );
            // attachments() 가 다시 postService() 를 부르므로 여기서 호출하면 무한 재귀가 된다.
            // 첨부가 필요한 시점에 attachments() 가 setAttachmentService() 로 연결한다.
        }

        return $this->postService;
    }

    public function attachments(): AttachmentService
    {
        if ($this->attachmentService === null) {
            $this->attachmentService = new AttachmentService(
                $this->boardService(),
                $this->postService(),
                $this->posts(),
                (array) $this->config('uploads', []),
                (string) $this->config('auth.secret', '')
            );
            $this->postService()->setAttachmentService($this->attachmentService);
        }

        return $this->attachmentService;
    }

    public function commentService(): CommentService
    {
        if ($this->commentService === null) {
            $this->commentService = new CommentService(
                $this->postService(),
                $this->posts(),
                $this->comments(),
                $this->htmlSanitizer(),
                $this->contentImages(),
                $this->notificationService()
            );
        }

        return $this->commentService;
    }

    public function users(): UserRepository
    {
        if ($this->users === null) {
            $this->users = new UserRepository($this->db());
        }

        return $this->users;
    }

    public function accountService(): AccountService
    {
        if ($this->accountService === null) {
            if ($this->tokens === null) {
                $this->tokens = new TokenRepository($this->db());
            }
            $this->accountService = new AccountService(
                $this->users(),
                new TokenService($this->tokens),
                $this->mailer(),
                (string) $this->config('app.url', 'https://aboard.gnuboard.net'),
                $this->cmsService(),
                $this->consents()
            );
        }

        return $this->accountService;
    }

    public function providerRegistry(): ProviderRegistry
    {
        if ($this->providerRegistry === null) {
            $config = (array) $this->config('oauth', []);
            $appUrl = rtrim((string) $this->config('app.url', 'https://aboard.gnuboard.net'), '/');
            foreach (['google', 'naver', 'kakao', 'github'] as $key) {
                if (isset($config[$key]) && is_array($config[$key]) && empty($config[$key]['redirect_uri'])) {
                    $config[$key]['redirect_uri'] = $appUrl . '/auth/' . $key . '/callback';
                }
            }
            $this->providerRegistry = new ProviderRegistry($config);
        }
        return $this->providerRegistry;
    }

    public function setProviderRegistry(ProviderRegistry $registry): void
    {
        $this->providerRegistry = $registry;
        $this->socialAuthService = null;
    }

    public function socialAuthService(): SocialAuthService
    {
        if ($this->socialAuthService === null) {
            if ($this->identities === null) {
                $this->identities = new IdentityRepository($this->db());
            }
            if ($this->linkingService === null) {
                $this->linkingService = new LinkingService($this->db(), $this->users(), $this->identities);
            }
            $this->socialAuthService = new SocialAuthService(
                $this->providerRegistry(), $this->linkingService, $this->mailer(),
                (string) $this->config('app.url', 'https://aboard.gnuboard.net')
            );
        }
        return $this->socialAuthService;
    }

    private function mailer(): MailerInterface
    {
        if ($this->mailer === null) {
            $smtp = $this->mailSettingsService()->runtime();
            $this->mailer = $smtp === null
                ? new NativeMailer((string) $this->config('mail.from', 'no-reply@localhost'))
                : new SmtpMailer($smtp);
        }
        return $this->mailer;
    }

    public function mailSettings(): MailSettingsRepository
    {
        if ($this->mailSettings === null) {
            $this->mailSettings = new MailSettingsRepository($this->db());
        }
        return $this->mailSettings;
    }

    public function mailSettingsService(): MailSettingsService
    {
        if ($this->mailSettingsService === null) {
            $this->mailSettingsService = new MailSettingsService(
                $this->mailSettings(),
                new SecretCipher((string) $this->config('auth.secret', '')),
                (string) $this->config('mail.from', 'no-reply@localhost')
            );
        }
        return $this->mailSettingsService;
    }

    public function sendMailTest(): void
    {
        $settings = $this->mailSettingsService()->runtime();
        if ($settings === null) {
            throw \ApiBoard\Error\DomainError::validation(['enabled' => 'SMTP를 사용하도록 설정해 주세요.']);
        }
        $this->mailer()->send(
            (string) $settings['from_email'],
            '[aboard] SMTP 테스트 메일',
            "SMTP 설정이 정상적으로 작동합니다.\n\n이 메일은 aboard 관리자에서 보낸 테스트 메일입니다."
        );
    }

    public function adminService(): AdminService
    {
        if ($this->adminService === null) {
            $this->adminService = new AdminService($this->db(), $this->users(), $this->boardService());
        }
        return $this->adminService;
    }

    public function cms(): CmsRepository
    {
        if ($this->cms === null) {
            $this->cms = new CmsRepository($this->db());
        }
        return $this->cms;
    }

    public function cmsService(): CmsService
    {
        if ($this->cmsService === null) {
            $this->cmsService = new CmsService($this->cms(), $this->htmlSanitizer(), $this->contentImages());
        }
        return $this->cmsService;
    }

    public function htmlSanitizer(): HtmlSanitizer
    {
        if ($this->htmlSanitizer === null) {
            $this->htmlSanitizer = new HtmlSanitizer();
        }
        return $this->htmlSanitizer;
    }

    public function contentRenderer(): ContentRenderer
    {
        if ($this->contentRenderer === null) {
            $this->contentRenderer = new ContentRenderer($this->htmlSanitizer());
        }

        return $this->contentRenderer;
    }

    public function contentImages(): ContentImageService
    {
        if ($this->contentImages === null) {
            $uploads = (array) $this->config('uploads', []);
            $uploadRoot = rtrim((string) ($uploads['dir'] ?? dirname(__DIR__) . '/storage/uploads'), '/');
            $root = (string) $this->config('editor.dir', dirname($uploadRoot) . '/editor');
            $maxBytes = (int) $this->config('editor.max_bytes', 5 * 1024 * 1024);
            $this->contentImages = new ContentImageService($root, $maxBytes);
        }
        return $this->contentImages;
    }

    public function consents(): ConsentRepository
    {
        if ($this->consents === null) {
            $this->consents = new ConsentRepository($this->db());
        }
        return $this->consents;
    }

    public function setIdentity(Identity $identity): void
    {
        $this->identity = $identity;
    }

    public function guestAcl(): Acl
    {
        return new Acl($this->identity);
    }
}
