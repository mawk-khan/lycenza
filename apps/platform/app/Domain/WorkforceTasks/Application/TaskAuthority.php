<?php

namespace App\Domain\WorkforceTasks\Application;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Application\ReportingLine;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use Illuminate\Support\Facades\Gate;

/**
 * HR.13: who may act on whose tasks. Whole-School keys through the ordinary
 * `capability` Gate; the manager path additionally needs the holder's
 * acting employee to be the assignee employment's CURRENT HR.3A manager
 * (School-local today) -- a reporting line alone grants nothing, and a
 * former or future manager is "not found". Never one's own employment.
 */
final class TaskAuthority
{
    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly ActingEmployeeResolver $acting,
        private readonly ReportingLine $reporting,
        private readonly HrSelfAdministrationGuard $selfAdministration,
    ) {}

    public function require(User $actor, string $capability, School $school): void
    {
        Gate::forUser($actor)->authorize('capability', [$capability, $school]);
    }

    public function holds(User $actor, string $capability, School $school): bool
    {
        return $this->capabilities->can($actor, $capability, $school);
    }

    /** The acting employee's employment id, or null when the actor is not a current employee here. */
    public function actingEmploymentId(User $actor, School $school, bool $lock = false): ?string
    {
        try {
            return ($lock ? $this->acting->hold($actor, $school) : $this->acting->resolve($actor, $school))->employmentRecordId;
        } catch (ActingEmployeeUnavailableException) {
            return null;
        }
    }

    /**
     * The path the actor acts on this employment through: 'administrative'
     * (hr.tasks.manage), 'manager' (hr.tasks.team + current manager), or null.
     * $lock holds the reporting line FOR SHARE (call inside the write transaction).
     */
    public function pathFor(User $actor, School $school, string $employmentRecordId, bool $lock = false): ?string
    {
        if ($this->holds($actor, TaskCapabilities::MANAGE, $school)) {
            return 'administrative';
        }
        if (! $this->holds($actor, TaskCapabilities::TEAM, $school)) {
            return null;
        }
        try {
            $acting = $lock ? $this->acting->hold($actor, $school) : $this->acting->resolve($actor, $school);
        } catch (ActingEmployeeUnavailableException) {
            return null;
        }
        $today = SchoolTimezone::today($school);
        $manager = $lock ? $this->reporting->holdManagerOf($school, $employmentRecordId, $today) : $this->reporting->managerOf($school, $employmentRecordId, $today);

        return $manager !== null && $manager === $acting->employeeId ? 'manager' : null;
    }

    /** True when the employment belongs to the actor's own linked Employee. */
    public function ownsEmployment(User $actor, School $school, string $employmentRecordId): bool
    {
        return $this->selfAdministration->ownsEmployment($actor, $school, $employmentRecordId);
    }
}
