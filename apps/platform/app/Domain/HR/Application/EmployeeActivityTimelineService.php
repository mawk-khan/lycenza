<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Phase 8A.11 -- the Employee Activity Timeline: a READ PROJECTION over
 * the existing `App\Models\SchoolAuditEvent` ledger (ADR 0017). This is
 * NOT a second audit/event store -- no new table is introduced, no
 * event is ever written by this class, and `AuditRecorder`/
 * `SchoolAuditEvent` remain the sole authoritative audit mechanism.
 * Reading the Timeline is itself NEVER audited (see "Timeline reads are
 * not audited" below) -- this class has no write path at all.
 *
 * EMPLOYEE-CENTRIC, NOT ACTOR-CENTRIC (checkpoint brief section 6/7):
 * an event belongs to an Employee's timeline because the event is
 * ABOUT that Employee (its `subject` is the Employee, or its metadata
 * explicitly names the Employee), never because the Employee's linked
 * User happened to be the ACTOR who performed some unrelated action.
 * `actor_user_id` is never used to decide timeline membership.
 *
 * LINKAGE STRATEGY (brief section 8, in the required preference order):
 * 1. `subject_type = Employee::class AND subject_id = $employee->id`
 *    -- used by `employee.created` and
 *    `hr.employee_document.sensitive_viewed` (both audit calls pass
 *    the Employee itself as `$subject`).
 * 2. `metadata->>'employeeId' = $employee->id` -- used by every other
 *    HR mutation event. Personal/professional/document events already
 *    carried this from 8A.1-8A.10; 8A.11 additionally back-fills it
 *    (going FORWARD only, see each service's own inline comment) onto
 *    `hr.employment.updated`, `hr.assignment.created/ended/primary_changed`,
 *    and `hr.assignment.manager_changed`, which previously only carried
 *    a child-resource id. No historical row is rewritten.
 * No "resolve through a still-existing child record" (brief's option 3)
 * is needed or used -- option 2 alone now covers every mapped event
 * type. A `hr.department.*`/`hr.position.*` audit event is NEVER
 * Employee-linked (Department/Position are org-wide reference data,
 * not per-Employee) and is correctly, structurally absent from every
 * Employee's timeline -- it is simply not in `EVENT_CATEGORIES` at all.
 *
 * NO BACKFILL (brief section 9/48): an `hr.employment.updated`,
 * `hr.assignment.*`, or `hr.assignment.manager_changed` row written
 * BEFORE this checkpoint lacks `metadata.employeeId` and will not
 * appear in any Timeline -- this is a documented, accepted, fail-safe
 * limitation (exclude rather than guess), not a bug. In this
 * repository's actual history there is no such pre-8A.11 production
 * data (Phase 8A has never run against real school data), so this is
 * a theoretical-only gap today, recorded for completeness.
 */
class EmployeeActivityTimelineService
{
    use AuthorizesCapability;

    public const int MAX_PER_PAGE = 100;

    /**
     * Closed allow-list, read-model-only categorization -- never
     * written back to `SchoolAuditEvent.event_type`. An event name not
     * present here is invisible to every Timeline request regardless
     * of actor/capability (brief section 55, "unknown HR event: fail
     * closed").
     *
     * @var array<string, string>
     */
    public const array EVENT_CATEGORIES = [
        'employee.created' => 'employee',

        'employee.personal_details.updated' => 'personal',
        'employee.address.created' => 'personal',
        'employee.address.updated' => 'personal',
        'employee.address.removed' => 'personal',
        'employee.emergency_contact.created' => 'personal',
        'employee.emergency_contact.updated' => 'personal',
        'employee.emergency_contact.removed' => 'personal',
        'employee.emergency_contact.primary_changed' => 'personal',

        'hr.employment.created' => 'employment',
        'hr.employment.updated' => 'employment',
        'hr.employment.ended' => 'employment',
        'hr.assignment.created' => 'employment',
        'hr.assignment.ended' => 'employment',
        'hr.assignment.primary_changed' => 'employment',
        'hr.assignment.manager_changed' => 'employment',

        'hr.qualification.created' => 'professional',
        'hr.qualification.updated' => 'professional',
        'hr.qualification.removed' => 'professional',
        'hr.qualification.verified' => 'professional',
        'hr.qualification.rejected' => 'professional',
        'hr.experience.created' => 'professional',
        'hr.experience.updated' => 'professional',
        'hr.experience.removed' => 'professional',
        'hr.certification.created' => 'professional',
        'hr.certification.updated' => 'professional',
        'hr.certification.removed' => 'professional',
        'hr.certification.verified' => 'professional',
        'hr.certification.rejected' => 'professional',

        'hr.employee_document.created' => 'document',
        'hr.employee_document.updated' => 'document',
        'hr.employee_document.archived' => 'document',

        'hr.employee_document.sensitive_viewed' => 'sensitive_access',
    ];

    public const array CATEGORIES = ['employee', 'personal', 'employment', 'professional', 'document', 'sensitive_access'];

    /**
     * Category -> the ADDITIONAL capability required beyond the base
     * Timeline entry gate (`hr.employees.personal.view`). `null` means
     * the entry gate alone is sufficient -- exactly mirrors
     * `EmployeeProfileWorkspaceService`'s section-level model from
     * 8A.10, reusing the identical capability set (brief section 14:
     * "the smallest coherent capability model wins" -- no
     * `hr.employees.activity.view` capability was created).
     *
     * @var array<string, string|null>
     */
    private const array CATEGORY_CAPABILITY = [
        'employee' => null,
        'personal' => null,
        'employment' => 'hr.employees.assignments.view',
        'professional' => 'hr.employees.qualifications.view',
        'document' => 'hr.employees.documents.view',
        'sensitive_access' => 'hr.employees.sensitive.view',
    ];

    private const array DOCUMENT_EVENT_TYPES = [
        'hr.employee_document.created',
        'hr.employee_document.updated',
        'hr.employee_document.archived',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
    ) {}

    public function get(School $school, string $employeeId, User $actor, EmployeeActivityTimelineQuery $query): ?LengthAwarePaginator
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.personal.view', $school);

        $visibleCategories = $this->visibleCategories($actor, $school);
        $categoriesToQuery = $query->category !== null ? array_intersect([$query->category], $visibleCategories) : $visibleCategories;
        $canViewSensitive = in_array('sensitive_access', $visibleCategories, true);

        $eventTypes = array_keys(array_filter(
            self::EVENT_CATEGORIES,
            fn (string $category) => in_array($category, $categoriesToQuery, true),
        ));

        return $this->context->withSchool($school, function () use ($school, $employeeId, $eventTypes, $canViewSensitive, $query) {
            $employee = Employee::query()->where('school_id', $school->id)->find($employeeId);

            if ($employee === null) {
                return null;
            }

            if ($eventTypes === []) {
                return new LengthAwarePaginator([], 0, $query->perPage, $query->page);
            }

            $sqlQuery = SchoolAuditEvent::query()
                ->where('school_id', $school->id)
                ->where(function ($q) use ($employee) {
                    $q->where(fn ($q2) => $q2->where('subject_type', Employee::class)->where('subject_id', $employee->id))
                        ->orWhereRaw("metadata->>'employeeId' = ?", [$employee->id]);
                })
                ->whereIn('event_type', $eventTypes);

            if (! $canViewSensitive) {
                $sqlQuery->where(function ($q) {
                    $q->whereNotIn('event_type', self::DOCUMENT_EVENT_TYPES)
                        ->orWhereRaw("event_type = 'hr.employee_document.created' AND metadata->>'classificationTier' = 'restricted'")
                        ->orWhereRaw("event_type = 'hr.employee_document.updated' AND metadata->>'classificationChanged' = 'false' AND metadata->>'classificationTier' = 'restricted'")
                        ->orWhereRaw("event_type = 'hr.employee_document.archived' AND metadata->>'classificationTier' = 'restricted'");
                });
            }

            if ($query->occurredFrom !== null) {
                $sqlQuery->where('occurred_at', '>=', $query->occurredFrom);
            }

            if ($query->occurredTo !== null) {
                $sqlQuery->where('occurred_at', '<=', $query->occurredTo);
            }

            $sqlQuery->orderByDesc('occurred_at')->orderByDesc('id');

            $paginator = $sqlQuery->paginate($query->perPage, ['*'], 'page', $query->page);

            return new LengthAwarePaginator(
                $this->transform($paginator->getCollection()),
                $paginator->total(),
                $paginator->perPage(),
                $paginator->currentPage(),
                ['path' => $paginator->path()],
            );
        });
    }

    /**
     * @return array<int, string>
     */
    private function visibleCategories(User $actor, School $school): array
    {
        $categories = [];

        foreach (self::CATEGORIES as $category) {
            $requiredCapability = self::CATEGORY_CAPABILITY[$category];

            if ($requiredCapability === null || $this->capabilities->canInSchool($actor, $requiredCapability, $school)) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /**
     * @param  Collection<int, SchoolAuditEvent>  $events
     * @return array<int, EmployeeActivityTimelineEntry>
     */
    private function transform(Collection $events): array
    {
        if ($events->isEmpty()) {
            return [];
        }

        $actorIds = $events->pluck('actor_user_id')->filter()->unique()->all();
        $actorNamesById = $actorIds === [] ? collect() : User::query()->whereIn('id', $actorIds)->pluck('name', 'id');

        return $events->map(function (SchoolAuditEvent $event) use ($actorNamesById) {
            $metadata = is_array($event->metadata) ? $event->metadata : [];
            $fields = $metadata['fields'] ?? [];
            $changedFields = is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [];

            return new EmployeeActivityTimelineEntry(
                id: $event->id,
                eventType: $event->event_type,
                category: self::EVENT_CATEGORIES[$event->event_type] ?? 'employee',
                occurredAt: $event->occurred_at->toIso8601String(),
                actorUserId: $event->actor_user_id,
                actorDisplayName: $event->actor_user_id !== null ? $actorNamesById->get($event->actor_user_id) : null,
                changedFields: $changedFields,
            );
        })->all();
    }
}
