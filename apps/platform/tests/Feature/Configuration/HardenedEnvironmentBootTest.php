<?php

namespace Tests\Feature\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\BootsHardenedEnvironment;
use Tests\TestCase;

/**
 * PPR.1 (ADR 0074 section 26, OD-PPR-1 option B), proven in real
 * `php artisan` subprocesses with FAKE values only:
 *
 * - only `production`, `staging`, `local` and `testing` boot; any other
 *   APP_ENV (a typo, a near-miss, an empty value) refuses at once, names the
 *   supported set and never echoes the value;
 * - `staging` runs the SAME hardened boot guard as production (finding F-2:
 *   it used to run none);
 * - the one email difference: staging SMTP must be declared a capture sink
 *   (`MAIL_STAGING_DELIVERY=capture`); production refuses that declaration;
 *   SendGrid stays refused in both.
 */
class HardenedEnvironmentBootTest extends TestCase
{
    use BootsHardenedEnvironment;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/hardened_boot_'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** @return array<string, string> a sound staging/production SMTP email setup (fake values) */
    private function smtpEmail(string $host, array $overrides = []): array
    {
        return [
            'MAIL_PROVIDER' => 'smtp',
            'MAIL_MAILER' => 'smtp',
            'MAIL_SENDING_DOMAIN' => 'notify.erp.example.org',
            'MAIL_FROM_ADDRESS' => 'notifications@notify.erp.example.org',
            'MAIL_SUPPRESSION_HMAC_KEY' => 'canary-suppression-'.str_repeat('k', 40),
            'MAIL_SUPPRESSION_HMAC_KEY_ID' => 'k-canary',
            'MAIL_HOST' => $host,
            'MAIL_PORT' => '1025',
            'MAIL_USERNAME' => 'capture',
            'MAIL_PASSWORD' => 'canary-smtp-password-7c1e',
            'MAIL_SMTP_TLS' => 'required',
            'MAIL_SMTP_TIMEOUT_SECONDS' => '5',
            ...$overrides,
        ];
    }

    private function processOutput(Process $process): string
    {
        return $process->getOutput().$process->getErrorOutput();
    }

    #[Test]
    public function an_unsupported_environment_refuses_to_boot_and_never_echoes_the_value(): void
    {
        $canary = 'canary-env-value-9f1d3b';

        foreach (['stage', 'preprod', 'prod2', 'Production', 'dev', $canary] as $value) {
            $process = $this->runArtisan(['about'], $this->hardenedEnv($value));
            $output = $this->processOutput($process);

            $this->assertNotSame(0, $process->getExitCode(), "APP_ENV={$value} must refuse to boot:\n{$output}");
            $this->assertStringContainsString('is not a supported environment', $output);
            $this->assertStringContainsString('production, staging, local, testing', $output);
            $this->assertStringNotContainsString($canary, $output, 'the refusal never echoes the configured value');
            $this->assertStringNotContainsString('canary-production-signing-key', $output);
        }

        // An empty APP_ENV is refused too -- it never runs as a weaker third state.
        $empty = $this->runArtisan(['about'], $this->hardenedEnv(''));
        $this->assertNotSame(0, $empty->getExitCode(), $this->processOutput($empty));
        $this->assertStringContainsString('it is empty', $this->processOutput($empty));
    }

    #[Test]
    public function an_unsupported_environment_serves_no_web_page_and_no_debug_page(): void
    {
        $probe = new Process(['php', '-d', 'display_errors=stderr', base_path('tests/Support/http-boot-probe.php')], base_path(), $this->hardenedEnv('preprod', ['APP_DEBUG' => 'true', 'PROBE_URI' => '/api/health/live']));
        $probe->setTimeout(120);
        $probe->run();
        $output = $this->processOutput($probe);

        $this->assertStringContainsString('SERVED:500', $output);
        foreach (['AppServiceProvider', 'vendor/laravel', 'Stack trace', '<pre', self::CANARY_SIGNING_KEY] as $leak) {
            $this->assertStringNotContainsString($leak, $output);
        }
    }

    #[Test]
    public function staging_runs_the_hardened_boot_guard_exactly_like_production(): void
    {
        foreach ([['APP_DEBUG' => 'true'], ['SESSION_SECURE_COOKIE' => 'false'], ['AI_GATEWAY_CONTEXT_SIGNING_KEY' => ''], ['DB_SSLMODE' => 'prefer'], ['TEACHER_ATTENDANCE_API_DEVELOPMENT_ENABLED' => 'true'], ['DOMAIN_FAKES_ENABLED' => 'true']] as $unsafe) {
            foreach (['production', 'staging'] as $environment) {
                $process = $this->runArtisan(['about'], $this->hardenedEnv($environment, $unsafe));

                $this->assertNotSame(0, $process->getExitCode(), json_encode($unsafe)." must refuse to boot in {$environment}:\n".$this->processOutput($process));
                $this->assertStringContainsString('unsafe production configuration', $this->processOutput($process));
            }
        }

        // The safe baseline boots in both (the guard is not vacuous). config:cache
        // boots every provider without touching a database (the baseline
        // requires TLS, which the test PostgreSQL does not offer).
        foreach (['production', 'staging'] as $environment) {
            $process = $this->runArtisan(['config:cache'], $this->hardenedEnv($environment));
            $this->assertSame(0, $process->getExitCode(), "{$environment}: ".$this->processOutput($process));
            @unlink($this->dir.'/config.php');
        }
    }

    #[Test]
    public function staging_email_is_a_declared_capture_sink_and_sendgrid_stays_refused(): void
    {
        // Undeclared staging SMTP is refused; a Mailpit-style host is refused without the declaration.
        $undeclared = $this->runArtisan(['about'], $this->hardenedEnv('staging', $this->smtpEmail('mail-capture.internal')));
        $this->assertStringContainsString('mail_staging_delivery_undeclared', $this->processOutput($undeclared));
        $mailpit = $this->runArtisan(['about'], $this->hardenedEnv('staging', $this->smtpEmail('mailpit')));
        $this->assertStringContainsString('mail_smtp_host_local', $this->processOutput($mailpit));

        // Declared capture with TLS and credentials boots, even on a Mailpit-style service name.
        $capture = $this->runArtisan(['config:cache'], $this->hardenedEnv('staging', $this->smtpEmail('mailpit', ['MAIL_STAGING_DELIVERY' => 'capture'])));
        $this->assertSame(0, $capture->getExitCode(), $this->processOutput($capture));
        // Every later run must read its own environment, never this cache.
        @unlink($this->dir.'/config.php');

        // TLS is never relaxed for the capture sink; loopback is still refused.
        $plain = $this->runArtisan(['about'], $this->hardenedEnv('staging', $this->smtpEmail('mailpit', ['MAIL_STAGING_DELIVERY' => 'capture', 'MAIL_SMTP_TLS' => 'none'])));
        $this->assertStringContainsString('mail_smtp_tls_not_required', $this->processOutput($plain));
        $loopback = $this->runArtisan(['about'], $this->hardenedEnv('staging', $this->smtpEmail('127.0.0.1', ['MAIL_STAGING_DELIVERY' => 'capture'])));
        $this->assertStringContainsString('mail_smtp_host_local', $this->processOutput($loopback));

        // The declaration has a closed value set, and production never carries it.
        $invalid = $this->runArtisan(['about'], $this->hardenedEnv('staging', $this->smtpEmail('mailpit', ['MAIL_STAGING_DELIVERY' => 'deliver'])));
        $this->assertStringContainsString('mail_staging_delivery_invalid', $this->processOutput($invalid));
        $production = $this->runArtisan(['about'], $this->hardenedEnv('production', $this->smtpEmail('smtp.provider.example', ['MAIL_STAGING_DELIVERY' => 'capture'])));
        $this->assertStringContainsString('mail_staging_delivery_outside_staging', $this->processOutput($production));

        // SendGrid is refused in staging exactly as in production until PPR.6 (E17-E20).
        $sendgrid = $this->runArtisan(['about'], $this->hardenedEnv('staging', ['MAIL_PROVIDER' => 'sendgrid']));
        $this->assertStringContainsString('mail_provider_sendgrid_not_authorised', $this->processOutput($sendgrid));
    }

    #[Test]
    public function the_development_environments_still_boot(): void
    {
        $local = $this->runArtisan(['about'], ['APP_ENV' => 'local', 'APP_CONFIG_CACHE' => $this->dir.'/config.php', 'APP_ROUTES_CACHE' => $this->dir.'/routes.php']);
        $this->assertSame(0, $local->getExitCode(), $this->processOutput($local));
    }
}
