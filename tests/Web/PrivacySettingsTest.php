<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PrivacySettingsTest extends WebTestCase
{
    private function generalSettings(array $privacy = []): array
    {
        return $privacy + [
            'site_name' => GNUCMS, 'site_tagline' => '소개',
            'home_title' => '홈', 'home_intro' => '본문', 'theme' => 'default',
        ];
    }

    private function consentConfig(string $html): array
    {
        self::assertSame(1, preg_match(
            '~<script type="application/json" id="gnucms-privacy-config">(.*?)</script>~s', $html, $matches
        ));
        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('connectionProvider')]
    public function testGoogleModesKeepAnalyticsInertAndExposeRelevantPreferenceLinks(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $analytics = '<script data-analytics>window.analyticsLoaded=true;</script>';
        $adsense = '<script data-ad-loader>window.adLoader=true;</script>';
        foreach (['europe', 'us_states', 'europe_us'] as $mode) {
            $app->cmsService()->saveGeneralSettings($this->adminAcl(), $this->generalSettings([
                'privacy_mode' => $mode, 'analytics_html' => $analytics, 'adsense_html' => $adsense,
            ]));
            $html = $this->body($this->get($app, '/'));
            self::assertStringNotContainsString($analytics, $html);
            self::assertStringContainsString($adsense, $html, 'Google 태그는 CMP 로더로 실행한다.');
            $config = $this->consentConfig($html);
            self::assertSame($mode, $config['mode']);
            self::assertSame($analytics, $config['analytics']);
            self::assertSame('', $config['advertising']);
            self::assertLessThan(strpos($html, $adsense), strpos($html, '/privacy.js?v='));
            self::assertSame($mode !== 'us_states', str_contains($html, 'data-privacy-preferences="europe"'));
            self::assertSame($mode !== 'europe', str_contains($html, 'data-privacy-preferences="us_states"'));
        }
    }

    #[DataProvider('connectionProvider')]
    public function testExternalCmpRunsBeforeInertTagsAndOffPreservesConfiguration(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $cmp = '<script data-cmp>window.cmpLoaded=true;</script>';
        $analytics = '<script data-analytics>window.analyticsLoaded=true;</script>';
        $adsense = '<script data-ad-loader>window.adLoader=true;</script>';
        $app->cmsService()->saveGeneralSettings($this->adminAcl(), $this->generalSettings([
            'privacy_mode' => 'external', 'privacy_cmp_html' => $cmp,
            'privacy_regulation_name' => '외부 CMP 지원 규정',
            'analytics_html' => $analytics, 'adsense_html' => $adsense,
        ]));
        $html = $this->body($this->get($app, '/'));
        self::assertStringContainsString($cmp, $html);
        self::assertStringNotContainsString($analytics, $html);
        self::assertStringNotContainsString($adsense, $html);
        $config = $this->consentConfig($html);
        self::assertSame($analytics, $config['analytics']);
        self::assertSame($adsense, $config['advertising']);
        self::assertLessThan(strpos($html, $cmp), strpos($html, '/privacy.js?v='));
        self::assertStringContainsString('data-privacy-preferences="external" hidden', $html);

        $app->cmsService()->saveGeneralSettings($this->adminAcl(), $this->generalSettings(['privacy_mode' => 'off']));
        $stored = $app->cms()->settings();
        self::assertSame($cmp, $stored['privacy_cmp_html']);
        self::assertSame('외부 CMP 지원 규정', $stored['privacy_regulation_name']);
        $html = $this->body($this->get($app, '/'));
        self::assertStringContainsString($analytics, $html);
        self::assertStringContainsString($adsense, $html);
        self::assertStringNotContainsString($cmp, $html);
        self::assertStringNotContainsString('gnucms-privacy-config', $html);
        self::assertStringNotContainsString('data-privacy-preferences', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testAdminDoesNotExecuteCmpAndInvalidConfigurationIsNotSaved(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create(
            'owner@example.com', password_hash('owner-password-123', PASSWORD_DEFAULT), '관리자', true
        );
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'owner@example.com',
            'password' => 'owner-password-123',
        ]);
        $cmp = '<script data-cmp>window.cmpLoaded=true;</script>';
        $app->cmsService()->saveGeneralSettings($this->adminAcl(), $this->generalSettings([
            'privacy_mode' => 'europe', 'privacy_cmp_html' => $cmp,
        ]));
        $admin = $this->body($this->get($app, '/admin/settings'));
        self::assertStringContainsString('name="privacy_mode"', $admin);
        self::assertStringContainsString('&lt;script data-cmp&gt;', $admin);
        self::assertStringNotContainsString($cmp, $admin);
        self::assertStringNotContainsString('gnucms-privacy-config', $admin);
        self::assertStringNotContainsString('/privacy.js?v=', $admin);

        foreach ([
            ['privacy_mode' => ['europe']],
            ['privacy_mode' => 'unsupported'],
            ['privacy_mode' => 'external', 'privacy_cmp_html' => '', 'privacy_regulation_name' => '기타 규정'],
            ['privacy_mode' => 'external', 'privacy_cmp_html' => $cmp, 'privacy_regulation_name' => ''],
            ['privacy_mode' => 'europe', 'privacy_cmp_html' => '', 'adsense_html' => ''],
            ['privacy_mode' => 'europe', 'privacy_cmp_html' => '</head><body>잘못된 코드</body>'],
        ] as $invalid) {
            $response = $this->post($app, '/admin/settings', $this->generalSettings($invalid) + [
                'csrf_token' => $_SESSION['csrf_token'],
            ]);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame('europe', $app->cms()->settings()['privacy_mode']);
            self::assertSame($cmp, $app->cms()->settings()['privacy_cmp_html']);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testDefaultKeepsLegacyOutputButCorruptModeBlocksTracking(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        self::assertSame('off', $app->cmsService()->settings()['privacy_mode']);
        $analytics = '<script data-analytics>window.analyticsLoaded=true;</script>';
        $this->saveSiteSettings($app, ['analytics_html' => $analytics]);
        self::assertStringContainsString($analytics, $this->body($this->get($app, '/')));
        $this->saveSiteSettings($app, ['privacy_mode' => 'unsupported', 'analytics_html' => $analytics]);
        self::assertSame('external', $app->cmsService()->settings()['privacy_mode']);
        $html = $this->body($this->get($app, '/'));
        self::assertStringNotContainsString($analytics, $html);
        self::assertSame($analytics, $this->consentConfig($html)['analytics']);
    }
}
