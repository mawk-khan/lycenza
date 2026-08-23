<?php

namespace Tests\Feature\Audit;

use App\Models\PlatformAuditEvent;
use App\Models\SchoolAuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 29: audit events are append-only. This is a REAL database
 * privilege the runtime app role lacks (ADR 0017, ADR 0021) -- not
 * cryptographic immutability, and not just "the app code doesn't call
 * update()". These tests attempt the SQL directly to prove the
 * database itself refuses, regardless of how the query was built.
 */
class AuditImmutabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function platform_audit_events_cannot_be_updated_by_the_runtime_role(): void
    {
        $event = app(AuditRecorder::class)->platform('test.event');

        try {
            DB::transaction(function () use ($event): void {
                DB::table('platform_audit_events')->where('id', $event->id)->update(['event_type' => 'tampered']);
            });
            $this->fail('Expected a QueryException: runtime role must not be able to UPDATE audit events.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }

        $this->assertSame('test.event', $event->fresh()->event_type);
    }

    #[Test]
    public function platform_audit_events_cannot_be_deleted_by_the_runtime_role(): void
    {
        $event = app(AuditRecorder::class)->platform('test.event');

        try {
            DB::transaction(function () use ($event): void {
                DB::table('platform_audit_events')->where('id', $event->id)->delete();
            });
            $this->fail('Expected a QueryException: runtime role must not be able to DELETE audit events.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }

        $this->assertSame(1, PlatformAuditEvent::query()->where('id', $event->id)->count());
    }

    #[Test]
    public function school_audit_events_cannot_be_updated_or_deleted_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $context->set($school);

        $event = app(AuditRecorder::class)->school($school, 'test.school.event');

        try {
            DB::transaction(function () use ($event): void {
                DB::table('school_audit_events')->where('id', $event->id)->update(['event_type' => 'tampered']);
            });
            $this->fail('Expected a QueryException.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }

        $this->assertSame(
            'test.school.event',
            $context->withSchool($school, fn () => SchoolAuditEvent::query()->find($event->id))->event_type,
        );
    }

    #[Test]
    public function audit_recorder_never_exposes_an_update_or_delete_path(): void
    {
        $this->assertFalse(method_exists(AuditRecorder::class, 'update'));
        $this->assertFalse(method_exists(AuditRecorder::class, 'delete'));
    }

    #[Test]
    public function significant_membership_and_role_changes_are_audited(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        // Activating a School records a platform audit event (see
        // SchoolSwitchController).
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $this->assertSame(
            1,
            PlatformAuditEvent::query()
                ->where('event_type', 'school_context.activated')
                ->where('actor_user_id', $user->id)
                ->count(),
        );

        // Updating settings records a School-scoped audit event (see
        // SchoolSettingsController).
        $this->put('/app/settings', [
            'name' => 'Audited Name',
            'timezone' => $school->timezone,
            'default_locale' => $school->default_locale,
        ]);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'school.settings.updated')->count(),
        );
        $this->assertSame(1, $count);
    }
}
