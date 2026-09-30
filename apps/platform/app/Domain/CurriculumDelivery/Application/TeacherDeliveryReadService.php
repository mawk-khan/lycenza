<?php

namespace App\Domain\CurriculumDelivery\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Domain\TeachingAssignments\Application\OwnedTeachingPeriod;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * TCH.3 -- the owned-teacher READ side of Curriculum Delivery. Every method
 * takes a TeacherDeliveryScope (TeacherDeliveryAccess::scope(): capability
 * + fresh ActingEmployee + ownership periods) and returns only what that
 * scope covers, filtered in the query:
 *
 * - contexts(): the teacher's own classes (Section + required Offering)
 *   with their own ownership periods -- never another Employee's, and not
 *   TeachingAssignment administration (no `teaching.assignments.view`);
 * - units(): for ONE owned class, its active SyllabusUnits (the catalogue
 *   the class must cover, readable here without School-wide
 *   `syllabus.view`) with the delivery state visible to this teacher.
 *   A unit whose delivery exists but lies wholly outside the teacher's
 *   periods is `unavailable`: no id, dates or status leave the server;
 * - find(): one delivery, or the same not-found as a missing row.
 *
 * Tier 1 administrators keep their own School-wide reads unchanged.
 */
class TeacherDeliveryReadService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function contexts(School $school, TeacherDeliveryScope $scope): array
    {
        $contexts = $scope->contexts();
        if ($contexts === []) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $scope, $contexts) {
            $sections = Section::query()->where('school_id', $school->id)
                ->whereIn('id', array_column($contexts, 'sectionId'))->with('gradeLevel')->get()->keyBy('id');
            $offerings = SubjectOffering::query()->where('school_id', $school->id)
                ->whereIn('id', array_column($contexts, 'subjectOfferingId'))->with('subject')->get()->keyBy('id');

            return array_map(fn (array $c) => [
                'sectionId' => $c['sectionId'],
                'sectionName' => $sections->get($c['sectionId'])?->name,
                'sectionCode' => $sections->get($c['sectionId'])?->code,
                'gradeLevelName' => $sections->get($c['sectionId'])?->gradeLevel?->name,
                'subjectOfferingId' => $c['subjectOfferingId'],
                'subjectCode' => $offerings->get($c['subjectOfferingId'])?->subject?->code,
                'subjectName' => $offerings->get($c['subjectOfferingId'])?->subject?->name,
                'current' => array_filter($c['periods'], fn (OwnedTeachingPeriod $p) => $p->covers($scope->asOf)) !== [],
                'periods' => array_map(fn (OwnedTeachingPeriod $p) => ['startsOn' => $p->startsOn, 'endsOn' => $p->endsOn], $c['periods']),
            ], $contexts);
        });
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ModelNotFoundException when the class is not the teacher's
     */
    public function units(School $school, TeacherDeliveryScope $scope, string $sectionId, string $subjectOfferingId): array
    {
        if (! $scope->ownsContext($sectionId, $subjectOfferingId)) {
            throw (new ModelNotFoundException)->setModel(SubjectOffering::class, [$subjectOfferingId]);
        }

        return $this->context->withSchool($school, function () use ($school, $scope, $sectionId, $subjectOfferingId) {
            $units = SyllabusUnit::query()->where('school_id', $school->id)
                ->where('subject_offering_id', $subjectOfferingId)
                ->where('status', 'active')
                ->orderBy('sequence')->orderByRaw('upper(code)')
                ->get();

            $base = fn () => CurriculumDelivery::query()->where('school_id', $school->id)
                ->where('section_id', $sectionId)->where('subject_offering_id', $subjectOfferingId);

            $visible = $scope->constrain($base())->get()->keyBy('syllabus_unit_id')->all();
            // Existence only (no id, date or status) of the deliveries
            // outside the teacher's periods, so a covered unit is never
            // offered as "not started".
            $elsewhere = array_flip($base()->whereNotIn('id', array_map(fn ($d) => $d->id, $visible))->pluck('syllabus_unit_id')->all());

            return $units->map(function (SyllabusUnit $unit) use ($visible, $elsewhere) {
                $delivery = $visible[$unit->id] ?? null;

                return [
                    'syllabusUnitId' => $unit->id,
                    'code' => $unit->code,
                    'title' => $unit->title,
                    'sequence' => $unit->sequence,
                    'state' => $delivery !== null ? $delivery->status : (isset($elsewhere[$unit->id]) ? 'unavailable' : 'not_started'),
                    'deliveryId' => $delivery?->id,
                    'startedOn' => $delivery?->started_on->toDateString(),
                    'completedOn' => $delivery?->completed_on?->toDateString(),
                ];
            })->values()->all();
        });
    }

    /**
     * @throws ModelNotFoundException when the delivery is missing or not visible
     */
    public function find(School $school, TeacherDeliveryScope $scope, string $deliveryId): CurriculumDelivery
    {
        return $this->context->withSchool($school, fn () => $scope->constrain(
            CurriculumDelivery::query()->where('school_id', $school->id)->whereKey($deliveryId),
        )->firstOrFail());
    }

    /** @return array<string, mixed> The Tier 1 API's delivery shape, unchanged. */
    public static function present(CurriculumDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'sectionId' => $delivery->section_id,
            'subjectOfferingId' => $delivery->subject_offering_id,
            'syllabusUnitId' => $delivery->syllabus_unit_id,
            'startedOn' => $delivery->started_on->toDateString(),
            'completedOn' => $delivery->completed_on?->toDateString(),
            'status' => $delivery->status,
        ];
    }
}
