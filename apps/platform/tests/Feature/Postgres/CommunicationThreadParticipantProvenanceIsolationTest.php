<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.1 §9/§39/§40 (mandatory per root CLAUDE.md rule 28) --
 * database-level proof for the Guardian/Student domain-provenance
 * columns added to `communication_thread_participants`: the composite
 * FKs reject a cross-School reference, and the CHECK constraints keep
 * `participant_kind` from ever drifting out of sync with
 * `guardian_id`/`student_id`.
 */
class CommunicationThreadParticipantProvenanceIsolationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function baseRow(string $schoolId, string $threadId, string $userId, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) new UuidV7,
            'school_id' => $schoolId,
            'thread_id' => $threadId,
            'user_id' => $userId,
            'joined_at' => now(),
            'participant_kind' => 'membership',
            'guardian_id' => null,
            'student_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    #[Test]
    public function a_cross_school_guardian_reference_is_rejected_at_insert_time(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $thread = $this->createThread($schoolA, $adminA);
        $guardianB = $this->createGuardian($this->createSchool());

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolA, $thread, $adminA, $guardianB, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolA, $thread, $adminA, $guardianB): void {
                    DB::connection('pgsql')->table('communication_thread_participants')->insert(
                        $this->baseRow($schoolA->id, $thread->id, $adminA->id, [
                            'participant_kind' => 'guardian',
                            'guardian_id' => $guardianB->id,
                        ]),
                    );
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The composite FK against guardians(id, school_id) must reject a cross-School Guardian.');
    }

    #[Test]
    public function a_cross_school_student_reference_is_rejected_at_insert_time(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $thread = $this->createThread($schoolA, $adminA);
        $studentB = $this->createStudent($this->createSchool());

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolA, $thread, $adminA, $studentB, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolA, $thread, $adminA, $studentB): void {
                    DB::connection('pgsql')->table('communication_thread_participants')->insert(
                        $this->baseRow($schoolA->id, $thread->id, $adminA->id, [
                            'participant_kind' => 'student',
                            'student_id' => $studentB->id,
                        ]),
                    );
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The composite FK against students(id, school_id) must reject a cross-School Student.');
    }

    #[Test]
    public function participant_kind_guardian_requires_a_guardian_id(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->createThread($school, $admin);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $thread, $admin, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $thread, $admin): void {
                    DB::connection('pgsql')->table('communication_thread_participants')->insert(
                        $this->baseRow($school->id, $thread->id, $admin->id, ['participant_kind' => 'guardian']),
                    );
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'ctp_kind_provenance_consistency_check must reject kind=guardian with a null guardian_id.');
    }

    #[Test]
    public function participant_kind_membership_rejects_a_non_null_guardian_id(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->createThread($school, $admin);
        $guardian = $this->createGuardian($school);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $thread, $admin, $guardian, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $thread, $admin, $guardian): void {
                    DB::connection('pgsql')->table('communication_thread_participants')->insert(
                        $this->baseRow($school->id, $thread->id, $admin->id, [
                            'participant_kind' => 'membership',
                            'guardian_id' => $guardian->id,
                        ]),
                    );
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'ctp_kind_provenance_consistency_check must reject kind=membership with a non-null guardian_id.');
    }

    #[Test]
    public function an_unrecognized_participant_kind_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->createThread($school, $admin);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $thread, $admin, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $thread, $admin): void {
                    DB::connection('pgsql')->table('communication_thread_participants')->insert(
                        $this->baseRow($school->id, $thread->id, $admin->id, ['participant_kind' => 'bogus']),
                    );
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'ctp_participant_kind_check must reject an unrecognized kind value.');
    }
}
