<?php

namespace Tests\Feature\Configuration;

use App\Domain\Examinations\Application\Marks\StudentMarkAvailability;
use App\Domain\Examinations\Application\Marks\TeacherStudentMarkAvailability;
use App\Support\Api\PartnerScopeRegistry;
use App\Support\Email\Events\EmailEventAdapterResolver;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Email\Providers\SendGridConfiguration;
use App\Support\Portal\PortalAvailability;
use App\Support\ServiceAuth\ServiceAuthKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PPR.1 (ADR 0074 section 26): at run time, staging behaves exactly like
 * production for every security hardening (HSTS, the SendGrid refusals, the
 * sending-domain gates, development features off), with only the listed
 * capture difference -- and local/testing keep their development behaviour.
 * The boot guard itself is proven in HardenedEnvironmentBootTest.
 */
class StagingParityTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function hardened(): array
    {
        return ['production' => ['production'], 'staging' => ['staging']];
    }

    private function as(string $environment): void
    {
        $this->app['env'] = $environment;
    }

    #[Test]
    #[DataProvider('hardened')]
    public function hsts_is_sent_on_secure_responses(string $environment): void
    {
        $this->as($environment);
        $this->get('https://localhost/api/health/live')->assertHeader('Strict-Transport-Security');

        $this->as('testing');
        $this->get('https://localhost/api/health/live')->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    #[DataProvider('hardened')]
    public function sendgrid_and_its_event_adapter_stay_refused(string $environment): void
    {
        config(['email.events.adapter' => 'sendgrid']);
        $this->as($environment);

        $this->assertSame('production_not_authorised', $this->app->make(SendGridConfiguration::class)->violation());
        $this->assertNull($this->app->make(EmailEventAdapterResolver::class)->active());
        config(['email.provider' => 'sendgrid']);
        $this->assertSame('provider_not_authorised', $this->app->make(EmailProviderResolver::class)->blockedReason());

        // Development keeps its non-production SendGrid path (refused only by its own configuration checks).
        $this->as('testing');
        $this->assertNotSame('production_not_authorised', $this->app->make(SendGridConfiguration::class)->violation());
    }

    #[Test]
    public function only_a_declared_staging_capture_sink_skips_the_sending_verified_attestation(): void
    {
        config([
            'app.url' => 'https://erp.example.org',
            'email.provider' => 'smtp',
            'email.sending_domain' => 'notify.erp.example.org',
            'mail.from.address' => 'notifications@notify.erp.example.org',
            'email.sending_verified' => false,
        ]);
        $reason = fn () => $this->app->make(EmailProviderResolver::class)->blockedReason();

        $this->as('production');
        $this->assertSame('sending_not_verified', $reason());
        config(['email.staging_delivery' => 'capture']);
        $this->assertSame('sending_not_verified', $reason(), 'production never honours the capture declaration');

        $this->as('staging');
        $this->assertNull($reason(), 'a declared staging capture sink submits (it delivers nothing)');
        config(['email.staging_delivery' => null]);
        $this->assertSame('sending_not_verified', $reason(), 'undeclared staging needs the attestation like production');

        // The reserved-domain gate never relaxes: a School-claimable sending domain is refused in staging too.
        config(['email.staging_delivery' => 'capture', 'email.sending_domain' => 'mail.some-school.example', 'mail.from.address' => 'notifications@mail.some-school.example']);
        $this->assertSame('sending_domain_not_reserved', $reason());
    }

    #[Test]
    #[DataProvider('hardened')]
    public function development_features_are_unavailable(string $environment): void
    {
        config(['webhooks.allow_loopback_for_tests' => true, 'email.provider' => 'fake']);
        $this->as($environment);

        $this->assertFalse(PortalAvailability::isAvailable(), 'Guardian portal (E46)');
        $this->assertFalse(StudentMarkAvailability::isAvailable(), 'StudentMark (E35-E37)');
        $this->assertFalse(TeacherStudentMarkAvailability::isAvailable(), 'teacher marks (E37)');
        $this->assertFalse(PartnerScopeRegistry::probeEnabled(), 'partner probe scope');
        $this->assertTrue($this->app->make(ServiceAuthKeys::class)->enforceKeyAge(), 'service-key age enforced');
        $this->assertNull($this->app->make(EmailProviderResolver::class)->adapter(), 'the fake email provider never resolves');

        // Contrast: the same switches are live in testing, so the checks above are not vacuous.
        $this->as('testing');
        $this->assertTrue(PortalAvailability::isAvailable());
        $this->assertTrue(StudentMarkAvailability::isAvailable());
        $this->assertNotNull($this->app->make(EmailProviderResolver::class)->adapter());
    }
}
