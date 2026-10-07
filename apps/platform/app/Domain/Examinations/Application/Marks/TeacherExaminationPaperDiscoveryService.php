<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\ExaminationPaperMarkState;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;

/**
 * RES.4A (ADR 0068 §26): "My examination papers" -- which ExaminationPapers
 * the teacher can open on the RES.4 marks surface. Discovery only: it answers
 * "is this paper inside my teaching ownership?", never "which Students may I
 * process?" -- the marks surface still decides every Student (P3, ownership,
 * ADR 0038) and every mark.
 *
 * A paper is listed only when ALL hold, judged on the paper's `scheduled_on`
 * exactly as TeacherStudentMarkGuard::admitPaper() / the RES.4 read do:
 * - the development-only block, `examinations.marks.teacher` and an eligible
 *   ActingEmployee today (TeacherStudentMarkAccess::scope());
 * - the teacher owns the paper's Offering on that date: a TeachingAssignment
 *   of some Section of a required Offering, or the TCH-E elective assignment
 *   (TeacherStudentMarkScope::ownsOffering()) -- never a role, School
 *   membership, StudentSubjectEnrollment or ownership on another date;
 * - the paper is active and its AcademicYear is not closed -- the papers the
 *   RES.4 read can open. A LOCKED paper is listed (read-only,
 *   `entryAvailable: false`); inactive papers and closed years are omitted.
 *
 * Three queries whatever the size (the teacher's periods, the candidate
 * papers with their display rows, their mark states); no Student, mark,
 * processing authorization or correction is read. Each row is minimal: ids,
 * the Examination and Subject names, the date, the maximum and the marks
 * state. Every successful listing is audited
 * (`examinations.examination_papers.teacher_listed`, ids and a count).
 */
class TeacherExaminationPaperDiscoveryService
{
    public const string AUDIT_LISTED = 'examinations.examination_papers.teacher_listed';

    public function __construct(
        private readonly TeacherStudentMarkAccess $access,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return list<array<string, mixed>> */
    public function papers(School $school, User $actor): array
    {
        $scope = $this->access->scope($actor, $school);

        return $this->context->withSchool($school, function () use ($school, $actor, $scope): array {
            $offeringIds = $scope->offeringIds();
            $papers = $offeringIds === [] ? collect() : ExaminationPaper::query()
                ->where('school_id', $school->id)
                ->where('status', ExaminationPaper::STATUS_ACTIVE)
                ->whereIn('subject_offering_id', $offeringIds)
                ->whereIn('academic_year_id', AcademicYear::query()->where('school_id', $school->id)->where('status', '!=', 'closed')->select('id'))
                ->with(['examination:id,name', 'subjectOffering:id,subject_id,grade_level_id', 'subjectOffering.subject:id,name,code', 'subjectOffering.gradeLevel:id,name'])
                ->orderByDesc('scheduled_on')->orderBy('id')
                ->get()
                ->filter(fn (ExaminationPaper $paper) => $scope->ownsOffering($paper->subject_offering_id, $paper->scheduled_on->toDateString()))
                ->values();

            $states = ExaminationPaperMarkState::query()->where('school_id', $school->id)
                ->whereIn('examination_paper_id', $papers->pluck('id')->all())->pluck('state', 'examination_paper_id');

            $rows = $papers->map(function (ExaminationPaper $paper) use ($states): array {
                $state = $states[$paper->id] ?? ExaminationPaperMarkState::STATE_OPEN;
                $offering = $paper->subjectOffering;

                return [
                    'id' => $paper->id,
                    'examination' => ['id' => $paper->examination_id, 'name' => $paper->examination->name ?? null],
                    'subjectOfferingId' => $paper->subject_offering_id,
                    'subject' => ['name' => $offering?->subject->name ?? null, 'code' => $offering?->subject->code ?? null],
                    'gradeLevelName' => $offering?->gradeLevel->name ?? null,
                    'scheduledOn' => $paper->scheduled_on->toDateString(),
                    'maxMarks' => (string) $paper->max_marks,
                    'marksState' => $state,
                    'entryAvailable' => $state === ExaminationPaperMarkState::STATE_OPEN,
                    'marksUrl' => "/app/my-examination-papers/{$paper->id}/marks",
                ];
            })->all();

            $this->audit->school($school, self::AUDIT_LISTED, actor: $actor, metadata: [
                'employeeId' => $scope->employeeId,
                'paperCount' => count($rows),
            ]);

            return $rows;
        });
    }
}
