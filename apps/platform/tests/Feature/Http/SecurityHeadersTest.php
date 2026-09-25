<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\ApplySecurityHeaders;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.3 (ADR 0049 section 11): the browser security header baseline
 * -- present everywhere, the enforced self-only CSP on pages, the
 * sandboxed policy on API JSON and downloads, HSTS only for known-HTTPS
 * production requests, and coexistence with the existing no-store and
 * privacy headers.
 */
class SecurityHeadersTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function assertBaseline($response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=(), hid=(), midi=(), display-capture=()');
    }

    #[Test]
    public function pages_get_the_exact_enforced_csp_and_keep_their_privacy_headers(): void
    {
        $login = $this->get('/login')->assertOk();
        $this->assertBaseline($login);
        $login->assertHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        $this->assertFalse($login->headers->has('Content-Security-Policy-Report-Only'), 'Enforced, not report-only.');
        $this->assertFalse($login->headers->has('Strict-Transport-Security'), 'No HSTS outside production.');

        // The rendered page itself needs no inline script or style.
        $html = $login->getContent();
        $this->assertDoesNotMatchRegularExpression('/<style\b/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/i', $html);
        preg_match_all('/<script\b([^>]*)>/i', $html, $scripts);
        foreach ($scripts[1] as $attributes) {
            $this->assertTrue(str_contains($attributes, 'src=') || str_contains($attributes, 'application/json'), "Inline executable script: {$attributes}");
        }

        // A signed-in page keeps its no-store policy alongside the new headers.
        [$user] = $this->createSchoolAdmin();
        $page = $this->actingAs($user)->get('/app')->assertOk();
        $this->assertBaseline($page);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $page->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function error_pages_need_no_inline_style_or_script(): void
    {
        [$user] = $this->createSchoolAdmin();

        foreach ([
            $this->get('/definitely-not-a-page-'.bin2hex(random_bytes(4))),
            $this->actingAs($user)->get('/app/platform/roles'),
        ] as $response) {
            $this->assertContains($response->getStatusCode(), [403, 404]);
            $response->assertHeader('Content-Security-Policy', ApplySecurityHeaders::APP_CSP);
            $html = $response->getContent();
            $this->assertDoesNotMatchRegularExpression('/<style\b/i', $html);
            $this->assertDoesNotMatchRegularExpression('/\sstyle="/i', $html);
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $html);
        }
    }

    #[Test]
    public function api_json_and_downloads_get_the_sandboxed_policy(): void
    {
        $status = $this->getJson('/api/v1/system/status')->assertOk();
        $this->assertBaseline($status);
        $status->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox");

        $error = $this->getJson('/api/v1/schools/'.fake()->uuid().'/context')->assertUnauthorized();
        $error->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox");

        // Even an HTML-typed attachment is served sandboxed, never with the app policy.
        Route::get('/_test/download', fn () => response('<script>alert(1)</script>', 200, [
            'Content-Type' => 'text/html',
            'Content-Disposition' => 'attachment; filename="x.html"',
        ]));
        $download = $this->get('/_test/download');
        $this->assertBaseline($download);
        $download->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox");
        $download->assertHeader('Content-Disposition', 'attachment; filename="x.html"');
    }

    #[Test]
    public function hsts_is_production_only_https_only_and_never_preloads(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
            $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertStringNotContainsString('includeSubDomains', ApplySecurityHeaders::HSTS);
        $this->assertStringNotContainsString('preload', ApplySecurityHeaders::HSTS);
    }

    #[Test]
    public function only_local_with_a_running_vite_dev_server_adds_that_origin(): void
    {
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        file_put_contents($hot, 'https://lycenza.ddev.site:5173');
        Vite::useHotFile($hot);

        try {
            $this->assertSame(ApplySecurityHeaders::APP_CSP, $this->get('/login')->headers->get('Content-Security-Policy'), 'Not outside `local`.');

            $this->app['env'] = 'local';
            $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("script-src 'self' https://lycenza.ddev.site:5173", $csp);
            $this->assertStringContainsString("connect-src 'self' https://lycenza.ddev.site:5173 wss://lycenza.ddev.site:5173", $csp);
            $this->assertStringNotContainsString('unsafe', $csp);
        } finally {
            $this->app['env'] = 'testing';
            @unlink($hot);
        }
    }

    #[Test]
    public function the_policies_never_allow_unsafe_sources_and_the_progress_css_ships_in_the_bundle(): void
    {
        foreach ([ApplySecurityHeaders::APP_CSP, ApplySecurityHeaders::RESTRICTIVE_CSP] as $policy) {
            $this->assertStringNotContainsString('unsafe-eval', $policy);
            $this->assertStringNotContainsString('unsafe-inline', $policy);
            $this->assertStringNotContainsString('*', $policy);
            $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        }

        $this->assertStringContainsString('includeCSS: false', (string) file_get_contents(resource_path('js/app.ts')));
        $this->assertStringContainsString('#nprogress .bar', (string) file_get_contents(resource_path('css/app.css')));
    }
}
