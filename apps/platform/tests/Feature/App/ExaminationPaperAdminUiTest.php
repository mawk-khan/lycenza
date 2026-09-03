<?php

namespace Tests\Feature\App;

use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesExaminationPaperFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4B -- the session-authenticated administrative
 * ExaminationPaper drill-down surface: Examination context, ordered
 * Paper list, active-Offering-only selector scoped to the same
 * AcademicYear, create, edit/reschedule, status withdrawal and
 * reactivation, and the capability boundary.
 */
class ExaminationPaperAdminUiTest extends TestCase
{
    use CreatesExaminationPaperFixtures;

    private function actor(array $w, ?object $user = null): static
    {
        return $this->actingAs($user ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function papersUrl(array $w): string
    {
        return "/app/examinations/{$w['examination']->id}/papers";
    }

    private function reload(array $w, string $id): ExaminationPaper
    {
        return $this->inExaminationSchool($w['school'], fn () => ExaminationPaper::query()->findOrFail($id));
    }

    #[Test]
    public function the_page_shows_the_examination_and_an_empty_paper_list(): void
    {
        $w = $this->examinationPaperWorld();

        $this->actor($w)->get($this->papersUrl($w))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Examinations/Papers/Index')
                ->where('examination.id', $w['examination']->id)
                ->has('papers', 0)
                ->has('subjectOfferings', 1)
                ->where('statuses', ['active', 'inactive'])
                ->where('canManage', true)
            );
    }

    #[Test]
    public function the_offering_selector_includes_active_required_and_elective_offerings_in_the_same_year(): void
    {
        $w = $this->examinationPaperWorld();
        $this->inExaminationSchool($w['school'], fn () => $w['subjectOffering']->forceFill(['is_required' => true])->save());

        $elective = $this->createSubjectOffering($w['year'], $w['campus'], $w['gradeLevel'], $this->createSubject($w['school']), [
            'is_required' => false,
        ]);

        // Inactive Offering -- excluded from the selector.
        $this->createSubjectOffering($w['year'], $w['campus'], $w['gradeLevel'], $this->createSubject($w['school']), [
            'status' => 'inactive',
        ]);

        // A different AcademicYear's Offering -- excluded.
        $otherYear = $this->createAcademicYear($w['school'], [
            'code' => 'AY-NEXT', 'status' => 'draft',
            'starts_on' => $this->today()->addMonths(7)->toDateString(),
            'ends_on' => $this->today()->addMonths(18)->toDateString(),
        ]);
        $this->createSubjectOffering($otherYear, $w['campus'], $w['gradeLevel'], $this->createSubject($w['school']));

        $this->actor($w)->get($this->papersUrl($w))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('subjectOfferings', 2)
                ->where('subjectOfferings.0.id', $w['subjectOffering']->id)
                ->where('subjectOfferings.1.id', $elective->id)
            );
    }

    #[Test]
    public function a_paper_can_be_created_and_edited_through_the_ui(): void
    {
        $w = $this->examinationPaperWorld();
        $scheduledOn = $this->today()->addDays(10)->toDateString();

        $this->actor($w)->post($this->papersUrl($w), [
            'subject_offering_id' => $w['subjectOffering']->id,
            'scheduled_on' => $scheduledOn,
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
            'max_marks' => '100.00',
        ])->assertRedirect();

        $paper = $this->inExaminationSchool($w['school'], fn () => ExaminationPaper::query()->firstOrFail());
        $this->assertSame($scheduledOn, $paper->scheduled_on->toDateString());

        $newEnd = '12:00:00';
        $this->actor($w)->patch("{$this->papersUrl($w)}/{$paper->id}", [
            'ends_at' => $newEnd, 'status' => 'inactive',
        ])->assertRedirect();

        $fresh = $this->reload($w, $paper->id);
        $this->assertSame($newEnd, $fresh->ends_at);
        $this->assertSame('inactive', $fresh->status);
    }

    #[Test]
    public function inactive_papers_remain_visible_and_can_be_reactivated(): void
    {
        $w = $this->examinationPaperWorld();
        $paper = $this->createExaminationPaper($w['examination'], $w['subjectOffering'], ['status' => 'inactive']);

        $this->actor($w)->get($this->papersUrl($w))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('papers', 1)
                ->where('papers.0.status', 'inactive')
            );

        $this->actor($w)->patch("{$this->papersUrl($w)}/{$paper->id}", ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame('active', $this->reload($w, $paper->id)->status);
    }

    #[Test]
    public function a_reactivation_denial_surfaces_as_an_ordinary_form_error(): void
    {
        $w = $this->examinationPaperWorld();
        $paper = $this->createExaminationPaper($w['examination'], $w['subjectOffering'], ['status' => 'inactive']);
        $this->inExaminationSchool($w['school'], fn () => $w['examination']->forceFill(['status' => 'inactive'])->save());

        $this->actor($w)->from($this->papersUrl($w))
            ->patch("{$this->papersUrl($w)}/{$paper->id}", ['status' => 'active'])
            ->assertRedirect($this->papersUrl($w))
            ->assertSessionHasErrors('scheduled_on');

        $this->assertSame('inactive', $this->reload($w, $paper->id)->status);
    }

    #[Test]
    public function a_member_without_the_view_capability_cannot_reach_the_page(): void
    {
        $w = $this->examinationPaperWorld();
        $outsider = $this->createUserWithCapabilities($w['school'], [
            'examinations.definitions.view', 'examinations.definitions.manage',
        ]);

        $this->actor($w, $outsider)->get($this->papersUrl($w))->assertForbidden();
    }

    #[Test]
    public function a_view_only_member_sees_the_page_but_cannot_write(): void
    {
        $w = $this->examinationPaperWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['examinations.papers.view']);
        $paper = $this->createExaminationPaper($w['examination'], $w['subjectOffering']);

        $this->actor($w, $viewer)->get($this->papersUrl($w))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actor($w, $viewer)->post($this->papersUrl($w), [
            'subject_offering_id' => $w['subjectOffering']->id,
            'scheduled_on' => $this->today()->addDays(11)->toDateString(),
            'starts_at' => '09:00:00', 'ends_at' => '10:00:00', 'max_marks' => '50.00',
        ])->assertForbidden();

        $this->actor($w, $viewer)->patch("{$this->papersUrl($w)}/{$paper->id}", ['max_marks' => '1.00'])
            ->assertForbidden();
    }

    #[Test]
    public function another_schools_paper_cannot_be_mutated_through_the_ui(): void
    {
        $w = $this->examinationPaperWorld();
        $other = $this->examinationPaperWorld();
        $foreign = $this->createExaminationPaper($other['examination'], $other['subjectOffering']);

        $this->actor($w)->patch("{$this->papersUrl($w)}/{$foreign->id}", ['max_marks' => '1.00'])
            ->assertNotFound();

        $this->assertNotSame('1.00', (string) $this->inExaminationSchool(
            $other['school'], fn () => ExaminationPaper::query()->findOrFail($foreign->id)->max_marks,
        ));
    }

    #[Test]
    public function no_student_or_marks_data_is_present_on_the_page(): void
    {
        $w = $this->examinationPaperWorld();
        $this->createExaminationPaper($w['examination'], $w['subjectOffering']);

        $this->actor($w)->get($this->papersUrl($w))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('papers.0', fn (AssertableInertia $paper) => $paper
                    ->hasAll(['id', 'subjectOfferingId', 'scheduledOn', 'startsAt', 'endsAt', 'maxMarks', 'status'])
                    ->missing('studentId')
                    ->missing('teacherId')
                    ->missing('marks')
                )
            );
    }
}
