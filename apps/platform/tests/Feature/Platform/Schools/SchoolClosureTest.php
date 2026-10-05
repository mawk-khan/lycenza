<?php

namespace Tests\Feature\Platform\Schools;

use App\Domain\Platform\Application\Schools\SchoolLifecycleAudit;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.2F (E21-D11): closure is freeze -> retain -> controlled purge. Close
 * freezes the School through the existing `suspended` status and records the
 * closure durably; it deletes nothing. Reopen withdraws a mistaken closure.
 * No School deletion exists.
 */
class SchoolClosureTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, SchoolLifecycleTestHelpers;

    /** @return array{0: User, 1: School} */
    private function activeSchool(): array
    {
        $root = $this->platformAdmin();

        return [$root, $this->activeSchoolViaPlatform($root, $this->createUser())];
    }

    #[Test]
    public function closing_freezes_the_school_and_deletes_nothing(): void
    {
        [$root, $school] = $this->activeSchool();
        $student = $this->createStudent($school);
        $memberships = SchoolMembership::query()->where('school_id', $school->id)->count();

        $this->lifecycleAction($root, $school, 'close', ['reason_code' => 'contract_ended'])->assertSessionHasNoErrors()->assertRedirect("/app/platform/schools/{$school->id}");

        $school->refresh();
        $this->assertTrue($school->isSuspended());
        $this->assertTrue($school->isClosed());
        $this->assertSame('contract_ended', $school->closure_reason);
        $this->assertSame($root->id, $school->closed_by_user_id);
        $this->assertFalse(app(SchoolOperationalGuard::class)->isOperational($school->id), 'ordinary School use is refused by the canonical guard');

        $this->assertSame($memberships, SchoolMembership::query()->where('school_id', $school->id)->count());
        $this->assertTrue(app(TenantContext::class)->withSchool($school, fn () => DB::table('students')->where('id', $student->id)->exists()));
        $this->assertTrue(DB::table('schools')->where('id', $school->id)->exists());

        $events = $this->lifecycleEvents($school, SchoolLifecycleAudit::CLOSED);
        $this->assertCount(1, $events);
        $this->assertEqualsCanonicalizing(['from' => 'active', 'to' => 'suspended', 'reason_code' => 'contract_ended'], $events[0]->metadata);
    }

    #[Test]
    public function closing_is_idempotent_and_a_suspended_school_can_be_closed(): void
    {
        [$root, $school] = $this->activeSchool();
        $this->suspendViaPlatform($root, $school);

        $this->lifecycleAction($root, $school, 'close', ['reason_code' => 'ceased_operations'])->assertSessionHasNoErrors();
        $closedAt = $school->fresh()->closed_at;
        $this->travel(1)->hours();
        $this->lifecycleAction($root, $school, 'close', ['reason_code' => 'merged_or_transferred'])->assertSessionHasNoErrors();

        $school->refresh();
        $this->assertSame('ceased_operations', $school->closure_reason, 'a second close changes nothing');
        $this->assertTrue($closedAt->equalTo($school->closed_at));
        $this->assertCount(1, $this->lifecycleEvents($school, SchoolLifecycleAudit::CLOSED));
    }

    #[Test]
    public function a_closed_school_cannot_be_resumed_only_reopened(): void
    {
        [$root, $school] = $this->activeSchool();
        $this->lifecycleAction($root, $school, 'close', ['reason_code' => 'contract_ended'])->assertSessionHasNoErrors();

        $this->lifecycleAction($root, $school, 'resume')->assertSessionHasErrors('school');
        $this->assertTrue($school->fresh()->isClosed());

        // The database refuses an active School that still carries a closure.
        try {
            DB::transaction(fn () => DB::table('schools')->where('id', $school->id)->update(['status' => 'active']));
            $this->fail('An active closed School must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('schools_closed_is_frozen_check', $e->getMessage());
        }

        $this->lifecycleAction($root, $school, 'reopen')->assertSessionHasNoErrors();
        $school->refresh();
        $this->assertTrue($school->isActive());
        $this->assertFalse($school->isClosed());
        $this->assertNull($school->closure_reason);
        $this->assertCount(1, $this->lifecycleEvents($school, SchoolLifecycleAudit::REOPENED));
    }

    #[Test]
    public function closure_needs_a_listed_reason_and_the_platform_capability(): void
    {
        [$root, $school] = $this->activeSchool();

        $this->lifecycleAction($root, $school, 'close', ['reason_code' => 'because'])->assertSessionHasErrors('reason_code');
        $this->assertFalse($school->fresh()->isClosed());

        $member = $this->createUserWithCapabilities($school, ['school.profile.manage']);
        $this->actingAs($member)->post("/app/platform/schools/{$school->id}/close", ['reason_code' => 'contract_ended', 'confirmed' => '1', 'mfa_code' => '000000'])->assertForbidden();
        $this->assertFalse($school->fresh()->isClosed());

        // Reopen is only for a closed School.
        $this->lifecycleAction($root, $school, 'reopen')->assertSessionHasErrors('school');
    }

    #[Test]
    public function retention_maintenance_still_walks_a_closed_school(): void
    {
        [$root, $school] = $this->activeSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $section = $this->createSection($year, $campus, $this->createGradeLevel($school), ['code' => 'A']);
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $this->createStudentEnrollment($student, $section, ['status' => 'withdrawn', 'starts_on' => '2026-06-01', 'ends_on' => '2026-09-30']);
        $this->createStudentGuardianRelationship($student, $this->createGuardian($school));
        $this->lifecycleAction($root, $school, 'close', ['reason_code' => 'ceased_operations'])->assertSessionHasNoErrors();

        config(['retention.student_operational_years' => 7, 'retention.hold_school_ids' => []]);
        $this->travelTo(Carbon::parse('2034-01-01 12:00:00', 'UTC'));
        $this->artisan('platform:student-retention-prune', ['--only' => 'operational'])->expectsOutputToContain('Guardian relationships of 1')->assertSuccessful();

        $this->assertFalse(app(TenantContext::class)->withSchool($school, fn () => DB::table('student_guardian_relationships')->where('student_id', $student->id)->exists()));
        $this->assertTrue($school->fresh()->isClosed());
    }
}
