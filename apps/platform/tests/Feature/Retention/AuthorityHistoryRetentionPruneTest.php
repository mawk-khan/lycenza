<?php

namespace Tests\Feature\Retention;

use App\Domain\LMS\Infrastructure\LearningContentSectionAudience;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Support\Retention\EmbeddedAuthorityRetention;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesLmsOwnershipFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeacherLearningContentFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * E21.2B (E21-D6): `platform:authority-history-prune`. Revoked and ended
 * authority expires seven calendar years after its end, through the narrow
 * retention functions. Active authority, LMS owner/audience and current
 * teaching are never touched. Fixed past clock: 2024-06-15 12:00 UTC, so the
 * cutoff is 2017-06-15 12:00 (School-local date 2017-06-15).
 */
class AuthorityHistoryRetentionPruneTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesLmsOwnershipFixtures, CreatesTeacherDeliveryFixtures, CreatesTeacherLearningContentFixtures, CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures, GroupTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2024-06-15 12:00:00', 'UTC'));
        config(['retention.authority_history_years' => 7, 'retention.audit_years' => 7, 'retention.hold_school_ids' => [], 'retention.hold_platform' => false, 'retention.batch_size' => 500]);
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    /** A School role grant, revoked at $revokedAt (null = active). */
    private function schoolGrant(School $school, ?string $revokedAt): string
    {
        $user = $this->createUser();
        $grant = $this->assignSchoolRole($this->createMembership($user, $school), 'principal');

        if ($revokedAt !== null) {
            $this->inSchool($school, fn () => DB::table('membership_role_assignments')->where('id', $grant->id)->update([
                'revoked_at' => $revokedAt, 'revoked_by_user_id' => $this->createUser()->id, 'revocation_reason' => 'revoked',
            ]));
        }

        return $grant->id;
    }

    private function schoolGrantExists(School $school, string $id): bool
    {
        return $this->inSchool($school, fn () => DB::table('membership_role_assignments')->where('id', $id)->exists());
    }

    /** A TeachingAssignment in $w's class whose last effective day is $endsOn (null = open). */
    private function teaching(array $w, ?string $endsOn): string
    {
        $id = (string) Str::uuid7();
        $this->inSchool($w['school'], fn () => DB::table('teaching_assignments')->insert([
            'id' => $id, 'school_id' => $w['school']->id, 'employee_id' => $this->employedTeacher($w['school'])->id,
            'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id,
            'section_id' => $w['section']->id, 'subject_offering_id' => $w['offering']->id,
            'starts_on' => '2010-01-01', 'ends_on' => $endsOn, 'created_by_user_id' => $w['admin']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function teachingExists(School $school, string $id): bool
    {
        return $this->inSchool($school, fn () => DB::table('teaching_assignments')->where('id', $id)->exists());
    }

    private function elevation(School $school, string $endedAt): string
    {
        $id = (string) Str::uuid7();
        $start = Carbon::parse($endedAt, 'UTC')->subMinutes(10);
        DB::table('school_elevations')->insert([
            'id' => $id, 'actor_user_id' => $this->createUser()->id, 'school_id' => $school->id, 'authority_type' => 'platform',
            'reason_code' => 'operational_support', 'status' => 'ended', 'started_at' => $start, 'expires_at' => $start->copy()->addMinutes(30),
            'ended_at' => $endedAt, 'end_reason' => 'exited', 'created_at' => $start, 'updated_at' => $endedAt,
        ]);

        return $id;
    }

    private function auditFor(School $school, string $elevationId, string $occurredAt): void
    {
        $this->inSchool($school, fn () => DB::table('school_audit_events')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'occurred_at' => $occurredAt, 'event_type' => 'test.elevated',
            'metadata' => '{}', 'created_at' => $occurredAt, 'elevation_id' => $elevationId,
        ]));
    }

    #[Test]
    public function nothing_is_deleted_while_unconfigured(): void
    {
        $school = $this->createSchool();
        $old = $this->schoolGrant($school, '2010-01-01 00:00:00');
        config(['retention.authority_history_years' => null]);

        $this->artisan('platform:authority-history-prune')->expectsOutputToContain('not configured')->assertSuccessful();
        $this->assertTrue($this->schoolGrantExists($school, $old));
    }

    #[Test]
    public function revoked_school_grants_expire_seven_years_after_revocation_and_active_ones_never(): void
    {
        $a = $this->createSchool();
        $held = $this->createSchool();
        config(['retention.hold_school_ids' => [$held->id]]);
        $active = $this->schoolGrant($a, null);
        $exactly = $this->schoolGrant($a, '2017-06-15 12:00:00');
        $older = $this->schoolGrant($a, '2017-06-15 11:59:59');
        $heldOld = $this->schoolGrant($held, '2012-01-01 00:00:00');

        $this->artisan('platform:authority-history-prune')->expectsOutputToContain('Deleted 1 school_role_grant; held: 1.')->assertSuccessful();

        $this->assertTrue($this->schoolGrantExists($a, $active));
        $this->assertTrue($this->schoolGrantExists($a, $exactly));
        $this->assertFalse($this->schoolGrantExists($a, $older));
        $this->assertTrue($this->schoolGrantExists($held, $heldOld));
    }

    #[Test]
    public function teaching_assignments_expire_from_their_last_effective_day_and_current_teaching_is_untouched(): void
    {
        $w = $this->teachingWorld();
        $other = $this->teachingWorld();
        $ended = $this->teaching($w, '2017-06-14');
        $boundary = $this->teaching($w, '2017-06-15');
        $open = $this->teaching($w, null);
        $otherEnded = $this->teaching($other, '2014-01-01');
        // The current, open assignment of the world's own teacher.
        $current = $this->assign($w, '2026-06-01');

        $this->artisan('platform:authority-history-prune')->assertSuccessful();

        $this->assertFalse($this->teachingExists($w['school'], $ended));
        $this->assertTrue($this->teachingExists($w['school'], $boundary));
        $this->assertTrue($this->teachingExists($w['school'], $open));
        $this->assertFalse($this->teachingExists($other['school'], $otherEnded));
        // Current teaching authority is unchanged.
        $periods = app(TeachingOwnership::class)->periods($w['school'], $w['employee']->id);
        $this->assertSame([$current->id], array_map(fn ($p) => $p->assignmentId, $periods));
    }

    #[Test]
    public function a_held_schools_teaching_history_survives_even_a_direct_call_by_the_retention_login(): void
    {
        // E21-RH.4: the hold is enforced inside the function; the 7-year floor and the ends_on rule are unchanged.
        $w = $this->teachingWorld();
        $ended = $this->teaching($w, '2014-01-01');
        $boundary = $this->teaching($w, '2017-06-15');
        $holds = app(RetentionHolds::class);
        $direct = fn (string $cutoff, bool $dryRun): mixed => (function () use ($w, $cutoff, $dryRun) {
            try {
                return DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, fn () => $this->inSchool($w['school'], fn () => (int) DB::selectOne(
                    'SELECT retention_expire_teaching_assignments(?, ?, 500, ?) AS n', [$w['school']->id, $cutoff, $dryRun ? 'true' : 'false'],
                )->n));
            } catch (QueryException $e) {
                return $e->getMessage();
            }
        })();

        $holds->place($w['school']->id, 'litigation', 'TA-HOLD-1');
        $this->assertStringContainsString('retention_hold', (string) $direct('2017-06-15', false));
        $this->assertSame(1, $direct('2017-06-15', true), 'a dry run only counts');
        $this->artisan('platform:authority-history-prune')->assertSuccessful();
        $this->assertTrue($this->teachingExists($w['school'], $ended), 'held: kept');

        $holds->place(null, 'regulatory_inquiry', 'TA-HOLD-2');
        $holds->release($w['school']->id, 'matter_concluded', 'TA-HOLD-1');
        $this->assertStringContainsString('retention_hold', (string) $direct('2017-06-15', false), 'the platform hold blocks School history too');

        $holds->release(null, 'inquiry_closed', 'TA-HOLD-2');
        $this->assertStringContainsString('retention_floor', (string) $direct('2023-01-01', false), 'the inline 7-year floor is unchanged');
        $this->assertSame(1, $direct('2017-06-15', false));
        $this->assertFalse($this->teachingExists($w['school'], $ended));
        $this->assertTrue($this->teachingExists($w['school'], $boundary), 'ends_on < cutoff, strictly');
    }

    #[Test]
    public function an_elevation_waits_for_its_audit_events_and_then_expires(): void
    {
        $school = $this->createSchool();
        $referenced = $this->elevation($school, '2016-01-01 10:00:00');
        $this->auditFor($school, $referenced, '2016-01-01 09:55:00');
        $recent = $this->elevation($school, '2020-01-01 10:00:00');

        // The audit event still references it (longest period wins).
        $this->artisan('platform:authority-history-prune')->assertSuccessful();
        $this->assertTrue(DB::table('school_elevations')->where('id', $referenced)->exists());

        // Audit expires first (scheduled earlier), then the elevation.
        $this->artisan('platform:audit-prune')->assertSuccessful();
        $this->artisan('platform:authority-history-prune')->assertSuccessful();
        $this->assertFalse(DB::table('school_elevations')->where('id', $referenced)->exists());
        $this->assertTrue(DB::table('school_elevations')->where('id', $recent)->exists());
    }

    #[Test]
    public function revoked_group_and_platform_grants_expire_unless_the_platform_is_held(): void
    {
        $group = $this->createGroup();
        $oldGroup = $this->grantGroupRole($this->createUser(), $group);
        $activeGroup = $this->grantGroupRole($this->createUser(), $group);
        DB::table('group_role_assignments')->where('id', $oldGroup->id)->update(['revoked_at' => '2015-01-01 00:00:00', 'revoked_by_user_id' => $this->createUser()->id]);

        $grantor = $this->createUser();
        $auditor = $this->assignPlatformRole($this->createUser(), 'platform_auditor', $grantor);
        DB::table('platform_role_assignments')->where('id', $auditor->id)->update(['revoked_at' => '2015-01-01 00:00:00', 'revoked_by_user_id' => $grantor->id]);

        config(['retention.hold_platform' => true]);
        $this->artisan('platform:authority-history-prune')->assertSuccessful();
        $this->assertTrue(DB::table('group_role_assignments')->where('id', $oldGroup->id)->exists());
        $this->assertTrue(DB::table('platform_role_assignments')->where('id', $auditor->id)->exists());

        config(['retention.hold_platform' => false]);
        $this->artisan('platform:authority-history-prune')->assertSuccessful();
        $this->assertFalse(DB::table('group_role_assignments')->where('id', $oldGroup->id)->exists());
        $this->assertTrue(DB::table('group_role_assignments')->where('id', $activeGroup->id)->exists());
        $this->assertFalse(DB::table('platform_role_assignments')->where('id', $auditor->id)->exists());
    }

    #[Test]
    public function lms_owner_and_audience_are_never_removed_by_authority_expiry(): void
    {
        // The teacher-owned row is created through the real owned path, which
        // needs an eligible employment today, so this test runs on the real clock.
        $this->travelBack();
        $w = $this->contentWorld();
        [$teacher] = $this->contentTeacher($w);
        $row = $this->teacherContent($w, $teacher);
        $owner = $this->inSchool($w['school'], fn () => DB::table('learning_content')->where('id', $row->id)->value('owner_employee_id'));
        $audience = $this->inSchool($w['school'], fn () => LearningContentSectionAudience::query()->where('learning_content_id', $row->id)->count());

        // The database floor compares against PostgreSQL now(), the test
        // transaction's START. The fixture above takes real time, so a cutoff
        // from the current PHP clock can land a second past that floor and be
        // refused. Step the PHP clock back so the cutoff is strictly older.
        $this->travelTo(now()->subMinute());
        $this->artisan('platform:authority-history-prune')->assertSuccessful();

        $this->assertSame($owner, $this->inSchool($w['school'], fn () => DB::table('learning_content')->where('id', $row->id)->value('owner_employee_id')));
        $this->assertSame($audience, $this->inSchool($w['school'], fn () => LearningContentSectionAudience::query()->where('learning_content_id', $row->id)->count()));
        $this->assertNotNull($owner);

        // The D6 minimum a future parent purge must also hold (unset = never).
        $this->travelTo(Carbon::parse('2024-06-15 12:00:00', 'UTC'));
        $this->assertTrue(EmbeddedAuthorityRetention::mayRemoveWithParent(Carbon::parse('2017-06-15 11:59:59', 'UTC')));
        $this->assertFalse(EmbeddedAuthorityRetention::mayRemoveWithParent(Carbon::parse('2017-06-15 12:00:00', 'UTC')));
        config(['retention.authority_history_years' => null]);
        $this->assertFalse(EmbeddedAuthorityRetention::mayRemoveWithParent(Carbon::parse('1990-01-01', 'UTC')));
    }

    #[Test]
    public function dry_run_deletes_nothing(): void
    {
        $school = $this->createSchool();
        $old = $this->schoolGrant($school, '2012-01-01 00:00:00');

        $this->artisan('platform:authority-history-prune', ['--dry-run' => true])->expectsOutputToContain('Dry run: would delete 1 school_role_grant')->assertSuccessful();
        $this->assertTrue($this->schoolGrantExists($school, $old));
    }
}
