<?php

namespace Tests\Feature\Attendance;

use App\Domain\AcademicStructure\Http\Controllers\SubjectOfferingController;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2 premise guard.
 *
 * AttendanceSession deliberately does NOT snapshot `subject_id`: it
 * relies on `subject_offering_id` alone being a stable historical
 * Subject anchor, which is only true while no supported write path can
 * repoint an existing SubjectOffering's identity columns. That premise
 * was verified by inspection during the architecture gate; this test
 * makes it a STANDING regression check, so an Academic Structure change
 * that quietly invalidated it fails Attendance's own suite instead of
 * silently corrupting historical registers.
 *
 * If this test ever fails, the correct response is NOT to relax it:
 * Attendance would need `subject_id` snapshotted (with a same-School
 * RESTRICT FK) before the offending write path ships.
 *
 * Note this guards the SUPPORTED paths only. `subject_offerings` has no
 * model-level immutability guard equivalent to `Employee`'s
 * `employee_number` one; adding it is a documented, non-blocking
 * Academic Structure backlog item deliberately not done in this
 * checkpoint (it would be an unrelated change to another module).
 */
class SubjectOfferingIdentityGuardTest extends TestCase
{
    use CreatesAttendanceFixtures;

    #[Test]
    public function the_supported_update_endpoint_cannot_repoint_a_subject_offering_identity(): void
    {
        $w = $this->attendanceWorld();
        $actor = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view', 'academics.subjects.manage']);

        $otherSubject = $this->createSubject($w['school'], ['code' => 'OTHER']);
        $otherYear = $this->createAcademicYear($w['school'], [
            'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-03-31', 'code' => 'AY2',
        ]);
        $otherCampus = $this->createCampus($w['school'], ['code' => 'C2']);
        $otherGrade = $this->createGradeLevel($w['school'], ['code' => 'G9', 'sequence' => 99]);

        $before = $this->inSchool($w['school'], fn () => SubjectOffering::query()->findOrFail($w['offering']->id));

        // Every identity column, submitted at once through the ONLY
        // supported update endpoint, alongside a genuinely mutable field
        // so the request itself definitely succeeds.
        $this->actingAs($actor)->withHeader('X-School-Id', $w['school']->id)
            ->patchJson("/api/v1/schools/{$w['school']->id}/subject-offerings/{$w['offering']->id}", [
                'weekly_periods_target' => 7,
                'subject_id' => $otherSubject->id,
                'academic_year_id' => $otherYear->id,
                'campus_id' => $otherCampus->id,
                'grade_level_id' => $otherGrade->id,
            ])->assertOk();

        $after = $this->inSchool($w['school'], fn () => SubjectOffering::query()->findOrFail($w['offering']->id));

        $this->assertSame($before->subject_id, $after->subject_id, 'subject_id must be immutable.');
        $this->assertSame($before->academic_year_id, $after->academic_year_id, 'academic_year_id must be immutable.');
        $this->assertSame($before->campus_id, $after->campus_id, 'campus_id must be immutable.');
        $this->assertSame($before->grade_level_id, $after->grade_level_id, 'grade_level_id must be immutable.');

        // The mutable configuration field DID change -- proving the
        // request was genuinely processed, not silently rejected.
        $this->assertSame(7, $after->weekly_periods_target);
    }

    #[Test]
    public function the_subject_offering_update_path_accepts_no_identity_column(): void
    {
        // A precise structural guard on the ONE supported mutation
        // entry point: read `update()`'s own source and assert none of
        // the four identity columns is in its validated input. Laravel's
        // validate() returns only validated keys, and this controller
        // passes exactly that array to update(), so a key absent here
        // cannot reach Eloquent at all.
        $method = new \ReflectionMethod(
            SubjectOfferingController::class,
            'update',
        );
        $lines = file($method->getFileName());
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        foreach (['subject_id', 'academic_year_id', 'campus_id', 'grade_level_id'] as $identityColumn) {
            $this->assertStringNotContainsString(
                "'{$identityColumn}'", $source,
                "SubjectOfferingController::update() must not accept {$identityColumn}: Attendance's ".
                'subject-provenance design depends on subject_offering_id being a stable historical anchor. '.
                'If this changes, AttendanceSession must snapshot subject_id before that write path ships.',
            );
        }

        // And the update path must still pass ONLY validated input to
        // the model -- never $request->all().
        $this->assertStringContainsString('$model->update($validated)', $source);
        $this->assertStringNotContainsString('$request->all()', $source);
    }
}
