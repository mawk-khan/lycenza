<?php

namespace Tests\Concerns;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\LMS\Application\LearningContentService;
use App\Domain\LMS\Application\TeacherLearningContentAccess;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\SchoolMembership;
use App\Models\User;

/**
 * TCH.5C fixtures on top of CreatesLmsOwnershipFixtures' world (a required
 * Offering with active Sections "A" and "B", a sibling Offering, Sections of
 * the wrong grade/campus/year): an administrator holding Tier 1 LMS and
 * TeachingAssignment administration, teachers who teach given Sections from
 * 2026-06-01 (open-ended, so today is covered), and rows written through the
 * real LearningContentService.
 *
 * Requires CreatesLmsOwnershipFixtures, CreatesTeacherDeliveryFixtures
 * (teacher()/own()) and CreatesTenancyFixtures.
 */
trait CreatesTeacherLearningContentFixtures
{
    /** @return array<string, mixed> */
    protected function contentWorld(): array
    {
        $w = $this->ownershipWorld();
        $w['section'] = $w['sectionA'];
        $w['admin'] = $this->createUserWithCapabilities($w['school'], [
            'teaching.assignments.view', 'teaching.assignments.manage',
            'lms.content.view', 'lms.content.manage', 'lms.assignments.view', 'lms.assignments.manage',
        ]);

        return $w;
    }

    /**
     * A teacher (production `teacher` role unless $roleKey says otherwise)
     * teaching each named Section of $offering from 2026-06-01.
     *
     * @param  list<string>  $sections  keys of $w
     * @return array{0: User, 1: Employee, 2: SchoolMembership, 3: list<TeachingAssignment>}
     */
    protected function contentTeacher(array $w, array $sections = ['sectionA'], ?string $roleKey = 'teacher', $offering = null): array
    {
        [$user, $employee, $membership] = $this->teacher($w, $roleKey);
        $assignments = array_map(fn (string $s) => $this->own($w, $employee, '2026-06-01', null, $w[$s], $offering ?? $w['offering']), $sections);

        return [$user, $employee, $membership, $assignments];
    }

    protected function endTeaching(array $w, TeachingAssignment $assignment, string $endsOn = '2026-08-31'): void
    {
        app(TeachingAssignmentService::class)->end($w['school'], $assignment->id, $endsOn, 'reassigned', $w['admin']);
    }

    /** An administrative (Offering-wide) row in the given status. */
    protected function adminContent(array $w, string $status = 'published', $offering = null): LearningContent
    {
        $service = app(LearningContentService::class);
        $content = $service->create($w['school'], ($offering ?? $w['offering'])->id, ['title' => 'School reading'], $w['admin']);

        return $this->toStatus($w, $content, $status, fn ($c, $s) => $s === 'published'
            ? $service->publish($w['school'], $c, $w['admin'])
            : $service->archive($w['school'], $c, $w['admin']));
    }

    /**
     * A teacher-owned row created through the owned path.
     *
     * @param  list<string>  $sections  keys of $w
     */
    protected function teacherContent(array $w, User $teacher, array $sections = ['sectionA'], string $status = 'draft'): LearningContent
    {
        $service = app(LearningContentService::class);
        $access = app(TeacherLearningContentAccess::class);
        $content = $service->createOwned($w['school'], $w['offering']->id, ['title' => 'My reading'],
            array_map(fn (string $s) => $w[$s]->id, $sections), $teacher, $access->guard($teacher));

        return $this->toStatus($w, $content, $status, fn ($c, $s) => $s === 'published'
            ? $service->publish($w['school'], $c, $teacher, $access->guard($teacher))
            : $service->archive($w['school'], $c, $teacher, $access->guard($teacher)));
    }

    private function toStatus(array $w, LearningContent $content, string $status, callable $step): LearningContent
    {
        if ($status === 'draft') {
            return $content;
        }
        $content = $step($content, 'published');

        return $status === 'archived' ? $step($content, 'archived') : $content;
    }
}
