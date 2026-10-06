<?php

declare(strict_types=1);

namespace GnuCms;

use GnuCms\Account\AccountService;
use GnuCms\Account\UserRepository;
use GnuCms\Account\TokenRepository;
use GnuCms\Account\TokenService;
use GnuCms\Account\IdentityRepository;
use GnuCms\Account\LinkingService;
use GnuCms\Account\SocialAuthService;
use GnuCms\Account\LoginEventRepository;
use GnuCms\Account\AvatarService;
use GnuCms\Account\AdminService;
use GnuCms\Account\ConsentRepository;
use GnuCms\Auth\Acl;
use GnuCms\Auth\PasswordThrottle;
use GnuCms\Validation\Validator;
use GnuCms\Auth\Identity;
use GnuCms\Db\Connection;
use GnuCms\Db\SchemaUpgrader;
use GnuCms\Repository\BoardRepository;
use GnuCms\Repository\CommentRepository;
use GnuCms\Repository\NotificationRepository;
use GnuCms\Repository\PostRepository;
use GnuCms\Service\AttachmentService;
use GnuCms\Service\BoardService;
use GnuCms\Service\CommentService;
use GnuCms\Service\NotificationService;
use GnuCms\Service\PostService;
use GnuCms\Spam\WriteRateLimiter;
use GnuCms\Spam\TurnstileSettingsRepository;
use GnuCms\Spam\TurnstileSettingsService;
use GnuCms\Spam\TurnstileVerifier;
use GnuCms\Mail\NativeMailer;
use GnuCms\Mail\MailerInterface;
use GnuCms\Mail\MailSettingsRepository;
use GnuCms\Mail\MailSettingsService;
use GnuCms\Mail\SecretCipher;
use GnuCms\Mail\SmtpMailer;
use GnuCms\Oauth\ProviderRegistry;
use GnuCms\Oauth\OauthSettingsRepository;
use GnuCms\Oauth\OauthSettingsService;
use GnuCms\Cms\CmsRepository;
use GnuCms\Cms\CmsService;
use GnuCms\Cms\ConsentUseRepository;
use GnuCms\Cms\ContentImageService;
use GnuCms\Cms\ContentRenderer;
use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Maintenance\BackupManager;
use GnuCms\Aligo\AligoService;
use GnuCms\Aligo\StreamTransport;
use GnuCms\Notify\AlimtalkChannel;
use GnuCms\Notify\InboxChannel;
use GnuCms\Notify\MailChannel;
use GnuCms\Notify\Notifier;
use GnuCms\Notify\NotifySettings;
use GnuCms\Notify\SettingsRepository as NotifySettingsRepository;
use GnuCms\Notify\SmsChannel;

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

    private ?WriteRateLimiter $writeRateLimiter = null;

    private ?TurnstileVerifier $turnstileVerifier = null;

    private ?TurnstileSettingsRepository $turnstileSettings = null;

    private ?TurnstileSettingsService $turnstileSettingsService = null;

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

    private ?LoginEventRepository $loginEvents = null;
    private ?AvatarService $avatars = null;

    private ?ProviderRegistry $providerRegistry = null;

    private ?OauthSettingsRepository $oauthSettings = null;

    private ?OauthSettingsService $oauthSettingsService = null;

    private ?MailerInterface $mailer = null;

    private ?MailSettingsRepository $mailSettings = null;

    private ?MailSettingsService $mailSettingsService = null;

    private ?AdminService $adminService = null;

    private ?CmsRepository $cms = null;

    private ?CmsService $cmsService = null;

    private ?ConsentRepository $consents = null;

    private ?ConsentUseRepository $consentUses = null;

    private ?HtmlSanitizer $htmlSanitizer = null;

    private ?ContentRenderer $contentRenderer = null;

    private ?ContentImageService $contentImages = null;

    private ?BackupManager $backupManager = null;

    private ?AligoService $aligoService = null;

    private ?NotifySettings $notifySettings = null;

    private ?Notifier $notifier = null;

    private array $paymentSettings = [];
    private ?\GnuCms\Payment\ProviderRegistry $paymentProviders = null;

    private array $paymentGateways = [];

    private ?\GnuCms\Shop\Service $shop = null;

    private ?string $configFile;

    /** @var Identity */
    private $identity;

    public function __construct(array $config, ?string $configFile = null)
    {
        $this->config = $config;
        $this->configFile = $configFile;
        $this->identity = Identity::guest();
        // 검사 기준이 곳곳에 흩어지지 않도록 비밀번호 최소 길이는 여기서 한 번만 정한다.
        Validator::setPasswordMin((int) $this->config('auth.password_min', 8));
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

    /** storage/ 절대 경로. 설정 storage.dir 가 있으면 그것을 쓴다(테스트·특수 배치용). */
    public function storageDir(): string
    {
        return rtrim((string) $this->config('storage.dir', dirname(__DIR__) . '/storage'), '/');
    }

    public function schemaUpgrader(): SchemaUpgrader
    {
        return new SchemaUpgrader($this->db(), $this->storageDir());
    }

    public function backups(): BackupManager
    {
        if ($this->backupManager === null) {
            $site = $this->cmsService()->settings();
            $this->backupManager = new BackupManager(
                $this->db(),
                $this->config,
                $this->storageDir(),
                $this->configFile,
                is_string($site['timezone'] ?? null) ? $site['timezone'] : 'Asia/Seoul'
            );
        }

        return $this->backupManager;
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
                $this->comments(),
                $this->users(),
                $this->cmsService(),
                (string) $this->config('app.url', GNUCMS_URL),
                // 발송기는 만들어진 채로 넘기지 않는다 — 알림함 채널이 이 서비스를
                // 지연해서 받는 것과 같은 고리를 반대쪽에서 막고(notifier() 주석),
                // setMailer()·setAligo() 가 발송기를 끊어도 이 서비스만 옛 사본을
                // 들고 남지 않게 한다.
                fn (): Notifier => $this->notifier()
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
            $this->postService->setWriteRateLimiter($this->writeRateLimiter());
            // 쓰기 규칙은 사이트 설정이 정한다. settings() 는 요청당 한 번만 DB 를 읽는다.
            $this->postService->setContentMinChars((int) $this->cmsService()->settings()['post_min_chars']);
            // attachments() 가 다시 postService() 를 부르므로 여기서 곧장 호출하면 무한
            // 재귀가 된다. 대신 지연 콜백만 넘겨 둔다: PostService 는 첨부 검증이 실제로
            // 필요한 순간(verifyAttachments())에야 이 콜백을 부른다. 이때는 postService()
            // 가 이미 캐시돼 있어 재귀가 없다. 이러면 컨트롤러가 요청마다 attachments()
            // 를 미리 불러 둬야 한다는 계약이 사라진다.
            $this->postService->setAttachmentResolver(function () { $this->attachments(); });
            $this->postService->setUserRepository($this->users());
        }

        return $this->postService;
    }

    public function attachments(): AttachmentService
    {
        if ($this->attachmentService === null) {
            $uploads = (array) $this->config('uploads', []);
            $uploads['max_bytes'] = $this->cmsService()->settings()['attach_max_mb'] * 1048576;
            $this->attachmentService = new AttachmentService(
                $this->boardService(),
                $this->postService(),
                $this->posts(),
                $uploads,
                (string) $this->config('auth.secret', '')
            );
            $this->postService()->setAttachmentLimit($this->cmsService()->settings()['attach_limit']);
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
            $this->commentService->setWriteRateLimiter($this->writeRateLimiter());
            $this->commentService->setContentMinChars((int) $this->cmsService()->settings()['comment_min_chars']);
            $this->commentService->setUserRepository($this->users());
            $this->commentService->setBoardService($this->boardService());
        }

        return $this->commentService;
    }

    public function writeRateLimiter(): WriteRateLimiter
    {
        if ($this->writeRateLimiter === null) {
            $settings = $this->cmsService()->settings();
            $this->writeRateLimiter = new WriteRateLimiter($this->db(), [
                'post' => [
                    [(int) $settings['post_rate_interval'], 1],
                    [600, (int) $settings['post_rate_10m']],
                    [86400, (int) $settings['post_rate_day']],
                ],
                'comment' => [
                    [(int) $settings['comment_rate_interval'], 1],
                    [600, (int) $settings['comment_rate_10m']],
                    [86400, (int) $settings['comment_rate_day']],
                ],
            ]);
        }

        return $this->writeRateLimiter;
    }

    public function turnstile(): TurnstileVerifier
    {
        if ($this->turnstileVerifier === null) {
            $config = $this->turnstileSettingsService()->runtime();
            $transport = isset($config['transport']) && is_callable($config['transport'])
                ? $config['transport'] : null;
            $this->turnstileVerifier = new TurnstileVerifier($config, $transport);
        }

        return $this->turnstileVerifier;
    }

    public function turnstileSettings(): TurnstileSettingsRepository
    {
        if ($this->turnstileSettings === null) {
            $this->turnstileSettings = new TurnstileSettingsRepository($this->db());
        }

        return $this->turnstileSettings;
    }

    public function turnstileSettingsService(): TurnstileSettingsService
    {
        if ($this->turnstileSettingsService === null) {
            $this->turnstileSettingsService = new TurnstileSettingsService(
                $this->turnstileSettings(),
                new SecretCipher((string) $this->config('auth.secret', '')),
                (array) $this->config('turnstile', []),
                (string) $this->config('app.url', GNUCMS_URL)
            );
        }

        return $this->turnstileSettingsService;
    }

    /** 관리자에서 키를 바꾼 같은 프로세스에서도 다음 요청부터 새 설정을 쓴다. */
    public function refreshTurnstile(): void
    {
        $this->turnstileVerifier = null;
        $this->passwordThrottle = null;
        $this->accountService = null;
    }

    public function users(): UserRepository
    {
        if ($this->users === null) {
            $this->users = new UserRepository($this->db());
        }

        return $this->users;
    }

    public function avatars(): AvatarService
    {
        if ($this->avatars === null) $this->avatars = new AvatarService($this->storageDir() . '/avatars');
        return $this->avatars;
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
                (string) $this->config('app.url', GNUCMS_URL),
                $this->cmsService(),
                $this->consents(),
                $this->mailSettingsService()->enabled()
            );
            $this->accountService->setPasswordThrottle($this->passwordThrottle());
            // 발송기는 new 가 끝난 **뒤에** 끼운다. 이 게터는 그 대입이 끝난 자리에서만
            // 메모이즈되므로, 언젠가 notifier() 쪽 조립이 계정 서비스를 되짚더라도
            // 반쯤 만들어진 것을 받을지언정 무한 재귀로 가지는 않는다. 위의
            // setPasswordThrottle() 과 같은 이유의 같은 차례다.
            $this->accountService->setNotifier($this->notifier());
            // 탈퇴는 번호를 지우는 것으로 끝나지 않는다 — 이미 걸린 예약도 멈춘다.
            $this->accountService->setAligo($this->aligo());
        }

        return $this->accountService;
    }

    public function providerRegistry(): ProviderRegistry
    {
        if ($this->providerRegistry === null) {
            $this->providerRegistry = new ProviderRegistry($this->oauthSettingsService()->runtime());
        }
        return $this->providerRegistry;
    }

    public function setProviderRegistry(ProviderRegistry $registry): void
    {
        $this->providerRegistry = $registry;
        $this->socialAuthService = null;
    }

    public function oauthSettings(): OauthSettingsRepository
    {
        if ($this->oauthSettings === null) {
            $this->oauthSettings = new OauthSettingsRepository($this->db());
        }
        return $this->oauthSettings;
    }

    public function oauthSettingsService(): OauthSettingsService
    {
        if ($this->oauthSettingsService === null) {
            $this->oauthSettingsService = new OauthSettingsService(
                $this->oauthSettings(),
                new SecretCipher((string) $this->config('auth.secret', '')),
                (array) $this->config('oauth', []),
                (string) $this->config('app.url', GNUCMS_URL)
            );
        }
        return $this->oauthSettingsService;
    }

    public function refreshOauthProviders(): void
    {
        $this->providerRegistry = null;
        $this->socialAuthService = null;
    }

    /** 테스트·특수 실행에서 메일 전송기를 바꾼다. 이미 조립된 인증 서비스도 함께 새로 만든다. */
    public function setMailer(MailerInterface $mailer): void
    {
        $this->mailer = $mailer;
        $this->accountService = null;
        $this->socialAuthService = null;
        $this->linkingService = null;
        $this->notifier = null;
    }

    public function socialAuthService(): SocialAuthService
    {
        if ($this->socialAuthService === null) {
            if ($this->linkingService === null) {
                $this->linkingService = new LinkingService(
                    $this->db(), $this->users(), $this->identities(),
                    $this->cmsService(), $this->consents(), $this->avatars()
                );
                // 소셜로 처음 가입하는 사람의 가입 완료 안내가 이 서비스에서 나간다.
                $this->linkingService->setNotifier($this->notifier());
            }
            $this->socialAuthService = new SocialAuthService(
                $this->providerRegistry(), $this->linkingService, $this->mailer(),
                (string) $this->config('app.url', GNUCMS_URL),
                $this->cmsService()
            );
            $this->socialAuthService->setNotifier($this->notifier());
        }
        return $this->socialAuthService;
    }

    public function identities(): IdentityRepository
    {
        if ($this->identities === null) {
            $this->identities = new IdentityRepository($this->db());
        }
        return $this->identities;
    }

    public function loginEvents(): LoginEventRepository
    {
        if ($this->loginEvents === null) {
            $this->loginEvents = new LoginEventRepository($this->db());
        }
        return $this->loginEvents;
    }

    private function mailer(): MailerInterface
    {
        if ($this->mailer === null) {
            $smtp = $this->mailSettingsService()->runtime();
            $this->mailer = $smtp === null
                ? new NativeMailer(
                    (string) $this->config('mail.from', 'no-reply@localhost'),
                    (string) $this->cmsService()->settings()['site_name']
                )
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

    /** 저장 직후에도 이 요청 안에서 새 메일 방식과 인증 정책을 다시 조립한다. */
    public function refreshMailSettings(): void
    {
        $this->mailer = null;
        $this->notifySettings = null;
        $this->notifier = null;
        $this->accountService = null;
        $this->socialAuthService = null;
        $this->linkingService = null;
    }

    /**
     * 테스트에서 알리고 전송기를 가짜로 바꾼다. 메일의 setMailer() 와 같은 이유다 —
     * 화면을 지나는 시험이 실제 알리고 서버를 부르면 안 된다. 발송을 막는 문(채널
     * 허용 스위치)은 AligoService::send() 안에 있으므로 이 교체로 열리지 않는다.
     */
    public function setAligo(AligoService $service): void
    {
        $this->aligoService = $service;
        // 알림 설정과 알림 발송기는 이 서비스(와 그 템플릿 사본)를 쥔 채 조립된다.
        // 끊어 주지 않으면 가짜로 바꾼 뒤에도 알림은 진짜 알리고로 나간다 —
        // setMailer() 가 accountService 를 끊는 것과 같은 이유다.
        $this->notifySettings = null;
        $this->notifier = null;
        // 발송기를 끊었으면 그 발송기를 이미 받아 쥔 서비스도 함께 끊어야 한다.
        // 그러지 않으면 그 서비스들만 옛 발송기(=진짜 알리고)를 계속 들고 있다.
        $this->accountService = null;
        $this->socialAuthService = null;
        $this->linkingService = null;
        // 회원 관리(차단)도 이 서비스를 쥔다 — 끊지 않으면 차단이 가짜가 아닌 진짜
        // 알리고로 취소를 부르러 나간다.
        $this->adminService = null;
    }

    public function aligo(): AligoService
    {
        if ($this->aligoService === null) {
            $this->aligoService = new AligoService(
                $this->db(),
                new StreamTransport(),
                new SecretCipher((string) $this->config('auth.secret', ''))
            );
        }

        return $this->aligoService;
    }

    public function shop(): \GnuCms\Shop\Service
    {
        return $this->shop ??= new \GnuCms\Shop\Service($this);
    }

    public function paymentProviders(): \GnuCms\Payment\ProviderRegistry
    {
        return $this->paymentProviders ??= new \GnuCms\Payment\ProviderRegistry();
    }

    public function paymentSettings(string $provider = 'inicis'): \GnuCms\Payment\Settings
    {
        return $this->paymentSettings[$provider] ??= new \GnuCms\Payment\Settings($this, $provider);
    }

    public function paymentGateway(string $provider): \GnuCms\Payment\Gateway
    {
        if (!isset($this->paymentGateways[$provider])) {
            $gateway = $this->paymentProviders()->get($provider)->gateway($this->paymentSettings($provider));
            if ($gateway->id() !== $provider) throw new \LogicException('결제사와 게이트웨이 ID가 다릅니다.');
            $this->paymentGateways[$provider] = $gateway;
        }
        return $this->paymentGateways[$provider];
    }

    /** 테스트·내부 서비스에서 전송기를 주입한 게이트웨이를 등록한다. */
    public function setPaymentGateway(\GnuCms\Payment\Gateway $gateway): void
    {
        $this->paymentProviders()->get($gateway->id());
        $this->paymentGateways[$gateway->id()] = $gateway;
    }

    /** 기존 코어 호출과의 호환. 쇼핑몰은 paymentGateway()를 사용한다. */
    public function inicisGateway(): \GnuCms\Payment\Gateway
    {
        return $this->paymentGateway('inicis');
    }

    public function setInicisGateway(\GnuCms\Payment\InicisGateway $gateway): void
    {
        $this->setPaymentGateway($gateway);
    }

    /**
     * 이벤트마다 어느 채널을 켤지 저장한 설정. 알림 발송기와 관리자 설정 화면이 같은
     * 사본을 본다 — 채널 둘(알림톡·문자)이 이 객체에게 본문·템플릿을 묻기 때문이다.
     */
    public function notifySettings(): NotifySettings
    {
        if ($this->notifySettings === null) {
            $this->notifySettings = new NotifySettings(
                new NotifySettingsRepository($this->db()),
                $this->aligo()->templates,
                fn (): bool => $this->mailSettingsService()->enabled(),
                fn (): array => $this->aligo()->channelStatus()
            );
        }

        return $this->notifySettings;
    }

    /**
     * 코어 알림의 유일한 출구.
     *
     * **알림함 채널만 callable 을 받는 이유.** 이 게터는 다른 게터들과 같이 new 가 끝난
     * **뒤에** 메모이즈한다($this->notifier 대입은 맨 마지막이다). 그래서 조립 도중에
     * notificationService() 를 만들면, 언젠가 그 서비스가 알림을 보내게 되는 순간
     * (알림함에 적는 김에 메일도 보내는 식) 그 게터가 다시 notifier() 를 부르고, 아직
     * null 인 메모이즈를 지나 무한 재귀가 된다. 발송 시점에야 서비스를 만들면 notifier()
     * 가 먼저 끝나 메모이즈되므로 그 고리가 닫히지 않는다. postService() 의
     * setAttachmentResolver() 가 같은 이유로 쓰는 같은 해법이다.
     */
    public function mailPreferences(): \GnuCms\Notify\MailPreferences
    {
        return new \GnuCms\Notify\MailPreferences($this->users(), (string) $this->config('auth.secret', ''),
            (string) $this->config('app.url', GNUCMS_URL));
    }

    public function notifier(): Notifier
    {
        if ($this->notifier === null) {
            $settings = $this->notifySettings();
            $this->notifier = new Notifier($settings, [
                new MailChannel($this->mailer(),
                    fn (): bool => $this->mailSettingsService()->enabled(),
                    fn (string $event, \GnuCms\Notify\Recipient $to): bool => $this->mailPreferences()->accepts($event, $to),
                    fn (string $event, \GnuCms\Notify\Recipient $to): string => $this->mailPreferences()->footer($event, $to), $settings),
                new AlimtalkChannel($this->aligo(), $settings, (string) $this->config('app.url', GNUCMS_URL)),
                new SmsChannel($this->aligo(), $settings, (string) $this->config('app.url', GNUCMS_URL)),
                new InboxChannel(fn (): NotificationService => $this->notificationService()),
            ], contact: fn (): string => $this->cmsService()->notificationContact(
                (string) $this->config('app.url', GNUCMS_URL)
            ), siteVariables: fn (): array => [
                '사이트명' => (string) $this->cmsService()->settings()['site_name'],
                '사이트주소' => rtrim((string) $this->config('app.url', GNUCMS_URL), '/'),
            ], alimtalkVariables: fn (): array => $this->alimtalkMessageInfo()->resolved());
        }

        return $this->notifier;
    }

    /** @return string 실제 사용한 전송 방식(native|smtp) */
    public function sendMailTest(string $to, string $serverIp = ''): string
    {
        $to = strtolower(trim($to));
        if ($to === '' || strlen($to) > 254 || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw \GnuCms\Error\DomainError::validation([
                'test_email' => '테스트 메일을 받을 올바른 이메일 주소를 입력해 주세요.',
            ]);
        }
        $mailSettings = $this->mailSettingsService();
        $mode = $mailSettings->mode();
        if ($mode === MailSettingsService::MODE_DISABLED) {
            throw \GnuCms\Error\DomainError::validation([
                'test_email' => '이메일 미사용 상태에서는 테스트 메일을 보낼 수 없습니다.',
            ]);
        }
        $settings = $mailSettings->runtime();
        $site = $this->cmsService()->settings();
        $siteName = (string) $site['site_name'];
        $domain = parse_url((string) $this->config('app.url', ''), PHP_URL_HOST);
        $domain = is_string($domain) && $domain !== '' ? $domain : '확인할 수 없음';
        $serverIp = trim($serverIp);
        $serverIp = filter_var($serverIp, FILTER_VALIDATE_IP) !== false ? $serverIp : '확인할 수 없음';
        $timezone = is_string($site['timezone'] ?? null) ? $site['timezone'] : 'Asia/Seoul';
        $sentAt = \GnuCms\Support\DateTimeDisplay::format(\GnuCms\Support\Clock::timestamp(), $timezone);
        $environment = "\n\n발송 환경\n"
            . "발송 사이트 도메인: {$domain}\n"
            . "웹서버 IP: {$serverIp}\n"
            . "발송 시각: {$sentAt} ({$timezone})\n"
            . '발송 방식: ' . ($settings === null ? '자체 서버' : 'SMTP');
        if ($settings !== null) {
            $environment .= "\nSMTP 서버: " . (string) $settings['host'];
        }
        $environment .= "\n\n웹서버 IP는 사이트가 실행되는 서버의 주소이며, SMTP 발송 서버의 IP와 다를 수 있습니다.";
        $this->mailer()->send(
            $to,
            '[' . $siteName . '] 테스트 메일',
            ($settings === null ? '서버 기본 메일 기능' : 'SMTP')
                . "으로 보낸 테스트 메일입니다.\n\n이 메일이 도착했다면 {$siteName}의 메일 발송 기능이 작동하고 있습니다."
                . $environment
        );

        return $mode;
    }

    public function adminService(): AdminService
    {
        if ($this->adminService === null) {
            $this->adminService = new AdminService($this->db(), $this->users(), $this->boardService());
            // 차단은 그 회원에게 걸린 예약 발송도 멈춘다. 게터들과 같은 차례로 new 가
            // 끝난 뒤에 끼운다(accountService() 의 setNotifier() 주석과 같은 이유).
            $this->adminService->setAligo($this->aligo());
        }
        return $this->adminService;
    }

    public function alimtalkMessageInfo(): \GnuCms\Aligo\MessageInfo
    {
        return new \GnuCms\Aligo\MessageInfo($this->cms(), fn (): array => [
            '사이트명' => (string) $this->cmsService()->settings()['site_name'],
            '사이트주소' => rtrim((string) $this->config('app.url', GNUCMS_URL), '/'),
            '문의처' => $this->cmsService()->notificationContact((string) $this->config('app.url', GNUCMS_URL)),
        ]);
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
            $this->cmsService = new CmsService(
                $this->cms(), $this->htmlSanitizer(), $this->contentImages(),
                $this->consentUses(), $this->consents()
            );
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

    public function consentUses(): ConsentUseRepository
    {
        if ($this->consentUses === null) {
            $this->consentUses = new ConsentUseRepository($this->db());
        }
        return $this->consentUses;
    }

    public function setIdentity(Identity $identity): void
    {
        $this->identity = $identity;
    }

    private ?PasswordThrottle $passwordThrottle = null;

    /** 비밀번호 대입 방어. 프록시 헤더는 믿지 않는다(동의 증적과 같은 원칙). */
    public function passwordThrottle(): PasswordThrottle
    {
        if ($this->passwordThrottle === null) {
            $ip = isset($_SERVER['REMOTE_ADDR']) && is_scalar($_SERVER['REMOTE_ADDR'])
                ? (string) $_SERVER['REMOTE_ADDR'] : null;
            $this->passwordThrottle = new PasswordThrottle(
                $this->db(),
                $ip,
                $this->turnstile()->isEnabled() && $this->turnstile()->isConfigured()
            );
        }

        return $this->passwordThrottle;
    }

    public function guestAcl(): Acl
    {
        $acl = new Acl($this->identity);
        $acl->setPasswordThrottle($this->passwordThrottle());
        $acl->setGuestWriteEnabled((bool) $this->cmsService()->settings()['guest_write_enabled']);
        $acl->setSecretGrants(isset($_SESSION['secret_posts']) && is_array($_SESSION['secret_posts'])
            ? $_SESSION['secret_posts'] : []);
        $acl->setCommentSecretGrants(
            isset($_SESSION['secret_comments']) && is_array($_SESSION['secret_comments'])
                ? $_SESSION['secret_comments'] : []
        );
        $acl->setCommentEditGrants(
            isset($_SESSION['comment_edits']) && is_array($_SESSION['comment_edits'])
                ? $_SESSION['comment_edits'] : []
        );

        return $acl;
    }
}
