<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Policy\CommunicationConsentService;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationConsentStatus;
use App\Domain\Communications\Infrastructure\CommunicationDomainConsentEvent;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.2 §13/§14/§15/§53/§66 -- the append-only consent ledger:
 * unknown-by-default, current-status derivation, full history
 * preservation across granted -> withdrawn -> granted, batched reads.
 */
class CommunicationConsentServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): CommunicationConsentService
    {
        return app(CommunicationConsentService::class);
    }

    #[Test]
    public function no_event_means_unknown_status(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $status = $this->service()->currentStatusForGuardian($school, $guardian, CommunicationChannel::Email);

        $this->assertNull($status);
    }

    #[Test]
    public function recording_a_grant_makes_current_status_granted(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $this->service()->recordGrantForGuardian($school, $guardian, $admin, CommunicationChannel::Email);

        $status = $this->service()->currentStatusForGuardian($school, $guardian, CommunicationChannel::Email);
        $this->assertSame(CommunicationConsentStatus::Granted, $status);
    }

    #[Test]
    public function recording_a_withdrawal_makes_current_status_withdrawn(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $service = $this->service();

        $service->recordGrantForGuardian($school, $guardian, $admin, CommunicationChannel::Email);
        $service->recordWithdrawalForGuardian($school, $guardian, $admin, CommunicationChannel::Email);

        $status = $service->currentStatusForGuardian($school, $guardian, CommunicationChannel::Email);
        $this->assertSame(CommunicationConsentStatus::Withdrawn, $status);
    }

    #[Test]
    public function full_consent_history_is_preserved_across_multiple_transitions(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $service = $this->service();

        $service->recordGrantForGuardian($school, $guardian, $admin, CommunicationChannel::Email);
        $service->recordWithdrawalForGuardian($school, $guardian, $admin, CommunicationChannel::Email);
        $service->recordGrantForGuardian($school, $guardian, $admin, CommunicationChannel::Email);

        $events = app(TenantContext::class)->withSchool($school, fn () => CommunicationDomainConsentEvent::query()
            ->where('guardian_id', $guardian->id)
            ->orderBy('recorded_at')
            ->get());

        $this->assertCount(3, $events, 'All three historical events must remain -- none overwritten.');
        $this->assertSame(
            [CommunicationConsentStatus::Granted, CommunicationConsentStatus::Withdrawn, CommunicationConsentStatus::Granted],
            $events->pluck('status')->all(),
        );

        $current = $service->currentStatusForGuardian($school, $guardian, CommunicationChannel::Email);
        $this->assertSame(CommunicationConsentStatus::Granted, $current, 'Current status must be deterministic (the latest event).');
    }

    #[Test]
    public function consent_events_are_append_only_at_the_database_level(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $event = $this->service()->recordGrantForGuardian($school, $guardian, $admin, CommunicationChannel::Email);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        try {
            DB::connection('pgsql')->transaction(function () use ($event): void {
                DB::connection('pgsql')->table('communication_domain_consent_events')->where('id', $event->id)->update(['status' => 'withdrawn']);
            });
            $this->fail('Expected a QueryException: runtime role must not be able to UPDATE consent events.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }
    }

    #[Test]
    public function batch_consent_status_lookup_for_many_guardians_uses_a_bounded_query_count(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardianIds = [];
        for ($i = 0; $i < 20; $i++) {
            $guardian = $this->createGuardian($school);
            if ($i % 2 === 0) {
                $this->service()->recordWithdrawalForGuardian($school, $guardian, $admin, CommunicationChannel::Email);
            }
            $guardianIds[] = $guardian->id;
        }

        DB::enableQueryLog();
        $statuses = $this->service()->currentStatusesForGuardians($school, $guardianIds, CommunicationChannel::Email);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(10, $statuses, 'Only the 10 Guardians with a recorded event should appear.');
        $this->assertLessThan(5, $queryCount, 'Batch consent lookup must be ONE query, not one per Guardian.');
    }
}
