<?php

namespace Tests\Feature\Retention;

use App\Domain\Identity\Application\Exceptions\InvitationNotUsableException;
use App\Domain\Identity\Application\GuardianAccountActivationService;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Models\School;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.3B (E21.2G I2, project-adopted, pending legal ratification): an ended
 * portal invitation is deleted 7 days after its canonical end (accepted_at,
 * revoked_at, or expires_at while still pending), never by `updated_at`
 * and never while usable. The invitation secret was unusable from the
 * moment it ended; retention only bounds the metadata.
 */
class PortalInvitationRetentionTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures;

    private const NOW = '2026-05-20 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.portal_invitation_days' => 7, 'retention.hold_school_ids' => []]);
        $this->travelTo(Carbon::parse(self::NOW, 'UTC'));
    }

    private function in(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    private function prune(array $options = []): PendingCommand
    {
        return $this->artisan('platform:portal-invitations-prune', $options);
    }

    /** @param  array<string, mixed>  $attributes */
    private function invitation(School $school, array $attributes): string
    {
        $id = (string) Str::uuid7();
        $this->in($school, fn () => DB::table('identity_account_invitations')->insert(array_merge([
            'id' => $id, 'school_id' => $school->id, 'guardian_id' => $this->createGuardian($school)->id, 'student_id' => null,
            'token_hash' => hash('sha256', Str::random(40)), 'destination_email_hash' => hash('sha256', Str::random(12)), 'status' => 'pending',
            'expires_at' => '2026-05-27 12:00:00', 'invited_by_user_id' => $this->createUser()->id,
            // updated_at is deliberately ancient: it is never the trigger.
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
        ], $attributes)));

        return $id;
    }

    private function exists(School $school, string $id): bool
    {
        return $this->in($school, fn () => DB::table('identity_account_invitations')->where('id', $id)->exists());
    }

    #[Test]
    public function nothing_is_deleted_while_unconfigured_and_an_invalid_period_fails(): void
    {
        $school = $this->createSchool();
        $id = $this->invitation($school, ['status' => 'revoked', 'revoked_at' => '2026-01-01 00:00:00']);

        config(['retention.portal_invitation_days' => null]);
        $this->prune()->expectsOutputToContain('not configured')->assertSuccessful();
        config(['retention.portal_invitation_days' => 'soon']);
        $this->prune()->assertFailed();

        $this->assertTrue($this->exists($school, $id));
    }

    #[Test]
    public function only_ended_invitations_go_and_only_seven_days_after_their_canonical_end(): void
    {
        $school = $this->createSchool();
        $kept = [
            'usable' => $this->invitation($school, ['expires_at' => '2026-05-25 00:00:00']),
            'expired 6 d ago' => $this->invitation($school, ['expires_at' => '2026-05-14 12:00:00']),
            'expired exactly 7 d ago' => $this->invitation($school, ['expires_at' => '2026-05-13 12:00:00']),
            'accepted 6 d ago' => $this->invitation($school, ['status' => 'accepted', 'accepted_at' => '2026-05-14 12:00:00', 'expires_at' => '2026-05-01 00:00:00']),
            'revoked exactly 7 d ago' => $this->invitation($school, ['status' => 'revoked', 'revoked_at' => '2026-05-13 12:00:00', 'expires_at' => '2026-05-01 00:00:00']),
            'accepted without a timestamp' => $this->invitation($school, ['status' => 'accepted', 'accepted_at' => null, 'expires_at' => '2026-01-01 00:00:00']),
        ];
        $gone = [
            'expired 7 d + 1 s ago' => $this->invitation($school, ['expires_at' => '2026-05-13 11:59:59']),
            'accepted 8 d ago' => $this->invitation($school, ['status' => 'accepted', 'accepted_at' => '2026-05-12 12:00:00', 'expires_at' => '2026-05-19 00:00:00']),
            'revoked 8 d ago' => $this->invitation($school, ['status' => 'revoked', 'revoked_at' => '2026-05-12 12:00:00', 'expires_at' => '2026-05-19 00:00:00']),
        ];
        $hashes = $this->in($school, fn () => DB::table('identity_account_invitations')->whereIn('id', $kept)->orderBy('id')->pluck('token_hash')->all());
        $audit = $this->in($school, fn () => DB::table('school_audit_events')->count());

        $this->prune(['--dry-run' => true])->expectsOutputToContain('Dry run: would delete 3 ended portal invitation(s) (held: 0, dependency-blocked: 0, errors: 0)')->assertSuccessful();
        foreach ($gone as $id) {
            $this->assertTrue($this->exists($school, $id), 'a dry run deletes nothing');
        }

        $this->prune()->expectsOutputToContain('Deleted 3 ended portal invitation(s) (held: 0, dependency-blocked: 0, errors: 0)')->assertSuccessful();
        foreach ($kept as $label => $id) {
            $this->assertTrue($this->exists($school, $id), "{$label}: kept");
        }
        foreach ($gone as $label => $id) {
            $this->assertFalse($this->exists($school, $id), "{$label}: deleted");
        }

        // Nothing kept was rewritten, and the credential TTL is unchanged.
        $this->assertSame($hashes, $this->in($school, fn () => DB::table('identity_account_invitations')->whereIn('id', $kept)->orderBy('id')->pluck('token_hash')->all()));
        $expired = $this->in($school, fn () => GuardianAccountInvitation::query()->findOrFail($kept['expired 6 d ago']));
        $this->assertSame('expired', $expired->effectiveStatus(), 'a retained expired invitation stays unusable');
        $this->assertSame($audit, $this->in($school, fn () => DB::table('school_audit_events')->count()), 'nothing is copied into audit');

        // Rerun is safe.
        $this->prune()->expectsOutputToContain('Deleted 0 ended portal invitation(s)')->assertSuccessful();
    }

    #[Test]
    public function a_held_school_is_counted_only_and_another_school_is_unaffected(): void
    {
        $held = $this->createSchool();
        $other = $this->createSchool();
        config(['retention.hold_school_ids' => [$held->id]]);
        // E21-RH.6: destructive retention refuses while a configured hold is unrecorded; record it (as reconcile does).
        app(RetentionHolds::class)->place($held->id, 'litigation', 'TEST-HOLD');
        $heldId = $this->invitation($held, ['status' => 'revoked', 'revoked_at' => '2026-01-01 00:00:00']);
        $otherId = $this->invitation($other, ['status' => 'revoked', 'revoked_at' => '2026-01-01 00:00:00']);

        $this->prune()->expectsOutputToContain('Deleted 1 ended portal invitation(s) (held: 1, dependency-blocked: 0, errors: 0)')->assertSuccessful();

        $this->assertTrue($this->exists($held, $heldId));
        $this->assertFalse($this->exists($other, $otherId));
        // One School's context never sees the other's invitations (RLS).
        $this->assertFalse($this->exists($held, $otherId));
    }

    #[Test]
    public function an_ended_student_subject_invitation_stops_keeping_its_student(): void
    {
        config(['retention.student_operational_years' => 7, 'retention.student_core_years' => 25, 'retention.authority_history_years' => 7]);
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['starts_on' => '2000-04-01', 'ends_on' => '2001-03-31', 'status' => 'closed', 'code' => 'AY00']);
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $this->createStudentEnrollment($student, $this->createSection($year, $this->createCampus($school), $this->createGradeLevel($school)), ['status' => 'completed', 'starts_on' => '2000-06-01', 'ends_on' => '2001-03-31']);
        $id = $this->invitation($school, ['guardian_id' => null, 'student_id' => $student->id, 'status' => 'revoked', 'revoked_at' => '2001-04-01 00:00:00']);

        $this->artisan('platform:student-retention-prune', ['--only' => 'core'])->expectsOutputToContain('core record of 0 Student(s) (unresolved exit: 0, dependency-blocked: 1')->assertSuccessful();

        $this->prune()->expectsOutputToContain('Deleted 1 ended portal invitation(s)')->assertSuccessful();
        $this->assertFalse($this->exists($school, $id));
        $this->artisan('platform:student-retention-prune', ['--only' => 'core'])->expectsOutputToContain('core record of 1 Student(s) (unresolved exit: 0, dependency-blocked: 0')->assertSuccessful();
    }

    #[Test]
    public function a_deleted_invitation_can_no_longer_be_accepted(): void
    {
        $school = $this->createSchool();
        $id = $this->invitation($school, ['expires_at' => '2026-05-01 00:00:00']);
        $invitation = $this->in($school, fn () => GuardianAccountInvitation::query()->findOrFail($id));

        $this->prune()->expectsOutputToContain('Deleted 1 ended portal invitation(s)')->assertSuccessful();

        $this->expectException(InvitationNotUsableException::class);
        app(GuardianAccountActivationService::class)->accept($school, $invitation, null, 'a-new-password-1');
    }
}
