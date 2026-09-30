<?php

namespace App\Domain\LMS\Application\Ownership;

use App\Domain\LMS\Infrastructure\Assignment;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * TCH.5B (ADR 0063 section 35) -- reads the ownership state of one LMS
 * resource: Offering-wide, or teacher-owned with its owner Employee and
 * audience Sections. A fresh, tenant-scoped read, never cached, and never
 * an authorization decision (TCH.5C/TCH.5D combine it with the capability,
 * the ActingEmployee and TeachingAssignment coverage). An unknown or other
 * School's id is a ModelNotFoundException.
 */
class LmsResourceOwnershipReader
{
    public function __construct(private readonly TenantContext $context) {}

    public function forLearningContent(School $school, string $learningContentId): LmsResourceOwnership
    {
        return $this->read($school, LearningContent::query()->where('school_id', $school->id), $learningContentId);
    }

    public function forAssignment(School $school, string $assignmentId): LmsResourceOwnership
    {
        return $this->read($school, Assignment::query()->where('school_id', $school->id), $assignmentId);
    }

    /** @param  Builder<LearningContent>|Builder<Assignment>  $query */
    private function read(School $school, $query, string $id): LmsResourceOwnership
    {
        return $this->context->withSchool($school, function () use ($query, $id) {
            /** @var LearningContent|Assignment $resource */
            $resource = $query->findOrFail($id);

            return new LmsResourceOwnership(
                $resource->id,
                $resource->subject_offering_id,
                $resource->getAttribute('owner_employee_id'),
                $resource->sectionAudiences()->orderBy('section_id')->pluck('section_id')->all(),
            );
        });
    }
}
