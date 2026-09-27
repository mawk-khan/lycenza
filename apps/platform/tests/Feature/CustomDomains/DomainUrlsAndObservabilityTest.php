<?php

namespace Tests\Feature\CustomDomains;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Identity\Mail\GuardianAccountInvitationMail;
use App\Domain\Platform\Application\Domains\SchoolDomainCheckService;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\School;
use App\Support\Domains\CanonicalOrigin;
use App\Support\Domains\Dns\DomainDnsResolver;
use App\Support\Domains\DomainDirectory;
use App\Support\Domains\DomainState;
use App\Support\Domains\OwnershipVerifier;
use App\Support\Observability\LogSanitizer;
use App\Support\Observability\Metrics\MetricCatalog;
use App\Support\Observability\Metrics\MetricsExporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 8.8-8.9, 10.2, 12): canonical School URLs
 * (the invitation Host-poisoning fix), the Host cache's failure mode, and
 * bounded metrics and logs.
 */
class DomainUrlsAndObservabilityTest extends TestCase
{
    use CapturesStructuredLogs, CreatesCommunicationFixtures, CreatesSchoolDomains, CreatesTenancyFixtures;

    #[Test]
    public function the_canonical_origin_is_the_active_primary_or_the_platform_never_the_request(): void
    {
        $origins = app(CanonicalOrigin::class);
        $plain = $this->createSchool();
        $custom = $this->createSchool();
        $this->createSchoolDomain($custom, 'erp.northfield.org');
        $this->createSchoolDomain($custom, 'www.northfield.org');
        config(['app.url' => 'https://app.lycenza-platform.com']);

        $this->assertSame('https://app.lycenza-platform.com', $origins->forSchool($plain));
        $this->assertSame('https://erp.northfield.org', $origins->forSchool($custom));
        $this->assertSame('https://erp.northfield.org/invitations/x', $origins->schoolUrl($custom, '/invitations/x'));

        DB::table('schools')->where('id', $custom->id)->update(['status' => 'suspended']);
        $this->assertSame('https://app.lycenza-platform.com', $origins->forSchool($custom->refresh()), 'a non-active School links to the platform');
    }

    #[Test]
    public function an_invitation_link_uses_the_canonical_origin_whatever_host_the_request_came_in_on(): void
    {
        Mail::fake();
        config(['app.url' => 'https://app.lycenza-platform.com', 'domains.platform_aliases' => ['www.lycenza-platform.com']]);
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');

        $urls = [];
        $capture = function () use (&$urls): void {
            Mail::assertSent(GuardianAccountInvitationMail::class, function (GuardianAccountInvitationMail $mail) use (&$urls): bool {
                $urls[spl_object_id($mail)] ??= (string) $mail->content()->with['acceptanceUrl'];

                return true;
            });
        };

        // Sent from the platform alias: the link is still the School's canonical origin.
        $this->actingAs($admin)->withSession([RequireSchoolContext::SESSION_KEY => $school->id])
            ->post("https://www.lycenza-platform.com/app/guardians/{$guardian->id}/account-invitation")->assertRedirect();
        $capture();

        $this->createSchoolDomain($school, 'erp.northfield.org');
        $this->actingAs($admin)->withSession([RequireSchoolContext::SESSION_KEY => $school->id])
            ->post("https://www.lycenza-platform.com/app/guardians/{$guardian->id}/account-invitation/resend")->assertRedirect();
        $capture();
        $urls = array_values($urls);

        $this->assertCount(2, $urls);
        $this->assertStringStartsWith("https://app.lycenza-platform.com/invitations/{$school->id}/", $urls[0]);
        $this->assertStringStartsWith("https://erp.northfield.org/invitations/{$school->id}/", $urls[1], 'once the School has an active primary, its links use it');
        foreach ($urls as $url) {
            $this->assertStringNotContainsString('www.', $url, 'never the incoming Host');
        }
    }

    #[Test]
    public function the_host_cache_is_bounded_and_a_cache_failure_falls_back_to_the_database(): void
    {
        $school = $this->createSchool();
        $this->createSchoolDomain($school, 'erp.northfield.org');

        // Redis gone: a store whose every call fails at the connection.
        config([
            'database.redis.unreachable' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2, 'read_timeout' => 0.2],
            'cache.stores.broken-redis' => ['driver' => 'redis', 'connection' => 'unreachable'],
        ]);
        $broken = app('cache')->store('broken-redis');
        $this->app->instance(DomainDirectory::class, new DomainDirectory($broken));

        $this->get('http://erp.northfield.org/login')->assertOk()->assertInertia(fn ($page) => $page->where('hostSchool.name', $school->name));
        $this->assertSame(60, DomainDirectory::TTL_SECONDS);
    }

    #[Test]
    public function domain_metrics_carry_closed_labels_only_and_never_touch_readiness(): void
    {
        // The check below starts an indeterminate streak (no route, so no
        // probe); a frozen clock makes its scrape-time age exactly 0.
        $this->freezeSecond();
        $school = $this->createSchool();
        $domain = $this->createSchoolDomain($school, 'erp.northfield.org');
        $this->createSchoolDomain($school, 'held.northfield.org', DomainState::Suspended);
        $this->get('http://attacker.example.net/login')->assertStatus(421);
        app(SchoolDomainCheckService::class)->check($domain);

        $exposition = app(MetricsExporter::class)->render();

        $this->assertStringContainsString('lycenza_school_domains{state="active"} 1', $exposition);
        $this->assertStringContainsString('lycenza_school_domains{state="suspended"} 1', $exposition);
        $this->assertMatchesRegularExpression('/lycenza_domain_certificate_min_days_remaining \d+/', $exposition);
        $this->assertStringContainsString('lycenza_domain_indeterminate_max_age_seconds 0', $exposition);
        $this->assertStringContainsString('lycenza_host_responses_total{outcome="misdirected"} 1', $exposition);
        $this->assertStringContainsString('lycenza_domain_checks_total{check="ownership",outcome="absent"} 1', $exposition);
        foreach (['northfield', 'attacker', $school->id, $domain->id, (string) $domain->challenge_token] as $identifier) {
            $this->assertStringNotContainsString($identifier, $exposition);
        }

        foreach (['lycenza_domain_checks_total', 'lycenza_domain_transitions_total', 'lycenza_school_domains', 'lycenza_host_responses_total'] as $name) {
            foreach (array_keys(MetricCatalog::definitions()[$name]['labels']) as $label) {
                $this->assertNotContains($label, ['hostname', 'school_id', 'domain_id', 'host'], $name);
            }
        }

        $readiness = $this->getJson('http://localhost/api/health/ready');
        $this->assertStringNotContainsString('domain', strtolower((string) $readiness->getContent()), 'one School\'s domain is never platform readiness');
    }

    #[Test]
    public function logs_carry_ids_and_closed_codes_never_the_challenge(): void
    {
        $this->captureLogs();
        $domain = $this->createSchoolDomain($this->createSchool(), 'erp.northfield.org', DomainState::PendingVerification);
        app(DomainDnsResolver::class)->publishTxt(OwnershipVerifier::recordName('erp.northfield.org'), [OwnershipVerifier::recordValue((string) $domain->challenge_token)]);

        app(SchoolDomainCheckService::class)->check($domain);

        $output = $this->capturedOutput();
        $this->assertStringContainsString('"event_code":"domains.check"', $output);
        $this->assertStringContainsString($domain->id, $output);
        $this->assertStringNotContainsString((string) $domain->challenge_token, $output);

        $sanitizer = app(LogSanitizer::class);
        $this->assertSame('lycenza-domain-verification=[redacted]', $sanitizer->sanitizeString('lycenza-domain-verification='.$domain->challenge_token));
        $this->assertSame(['ticket' => '[redacted]', 'probe_key' => '[redacted]', 'challenge_token' => '[redacted]'], $sanitizer->sanitize(['ticket' => 'abc', 'probe_key' => 'k', 'challenge_token' => 't']));
    }
}
