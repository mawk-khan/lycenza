<?php

namespace Tests\Feature\Email;

use App\Support\Email\ProviderAuthPause;
use App\Support\Email\Providers\SubmissionResult;
use App\Support\Observability\ComponentStatus;
use App\Support\Observability\Metrics\MetricsExporter;
use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 section 16): the `email` Operations Status
 * component (Degraded at worst, never readiness, no provider call), the
 * closed-label email metrics and the operator status command.
 */
class EmailOperationsSignalsTest extends TestCase
{
    use CreatesEmailFixtures;

    private function emailComponent(): ComponentStatus
    {
        return app(OperationalStatusService::class)->email();
    }

    #[Test]
    public function the_component_distinguishes_disabled_healthy_and_degraded_and_is_never_unhealthy(): void
    {
        $this->assertSame([OperationalStatus::Healthy, null], [$this->emailComponent()->status, $this->emailComponent()->reason]);

        config(['email.provider' => 'none']);
        $this->assertSame([OperationalStatus::Degraded, 'disabled'], [$this->emailComponent()->status, $this->emailComponent()->reason]);
        config(['email.provider' => 'fake']);

        app(ProviderAuthPause::class)->open(900);
        $this->assertSame([OperationalStatus::Degraded, 'provider_auth_failure'], [$this->emailComponent()->status, $this->emailComponent()->reason]);
        app(ProviderAuthPause::class)->clear();

        // A very old critical backlog is still only Degraded (rule 56 spirit).
        $this->holdEmailSubmission();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->queueInvitationEmail($school, $admin);
        $this->travel(2)->days();
        $component = $this->emailComponent();
        $this->assertSame([OperationalStatus::Degraded, 'backlog'], [$component->status, $component->reason]);
        $this->assertSame(1, $component->detail['backlog']['account_invitation']['pending']);
        $this->assertSame('unconfigured', $component->detail['retention']);
        $this->assertStringNotContainsString('guardian@example.com', (string) json_encode($component->detail));
    }

    #[Test]
    public function readiness_never_depends_on_email(): void
    {
        $source = (string) file_get_contents(app_path('Support/Observability/OperationalStatusService.php'));
        preg_match('/public function readiness\(\): OperationalStatus\s*\{(.*?)\n    \}/s', $source, $m);

        $this->assertStringNotContainsString('email', strtolower($m[1]));
    }

    #[Test]
    public function the_email_metrics_carry_closed_labels_only(): void
    {
        $this->fakeEmail();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->emailFake()->queue(SubmissionResult::transient('timeout'));
        $this->queueStandardEmail($school, $admin, 'metric-person@school-os.test');

        $exposition = app(MetricsExporter::class)->render();

        $this->assertStringContainsString('lycenza_email_pending_messages{message_class="school_communication"} 1', $exposition);
        $this->assertStringContainsString('lycenza_email_oldest_pending_age_seconds{message_class="account_invitation"} 0', $exposition);
        $this->assertStringContainsString('lycenza_email_last_event_timestamp_seconds 0', $exposition, 'an event feed is configured in the suite');
        foreach (['metric-person', $school->id, 'notify.lycenza-suite.test'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $exposition);
        }
    }

    #[Test]
    public function the_status_command_prints_modes_and_counts_never_secrets(): void
    {
        $this->artisan('platform:mail-status')
            ->expectsOutputToContain('provider: fake; events: configured; sending verified: no')
            ->expectsOutputToContain('[LEGAL REVIEW REQUIRED]')
            ->doesntExpectOutputToContain((string) config('email.suppression.key'))
            ->doesntExpectOutputToContain((string) config('email.events.secrets')[0])
            ->assertExitCode(0);
    }
}
