<?php

namespace App\Support\Retention\Erasure;

use App\Models\ErasureCase;
use App\Models\School;
use App\Support\Retention\Erasure\Subjects\EmployeeErasureAdapter;
use App\Support\Retention\Erasure\Subjects\GuardianErasureAdapter;
use App\Support\Retention\Erasure\Subjects\StudentErasureAdapter;
use App\Support\Retention\Erasure\Subjects\UserErasureAdapter;
use InvalidArgumentException;

/**
 * E21.2F (E21-D10): the ONE place a reviewed erasure case is planned and
 * executed. It holds no retention rule of its own. It picks the subject
 * type's closed-list adapter, which reuses that domain's canonical
 * eligibility and locked purges:
 * - Student: D7;
 * - Employee: D9, with Payroll;
 * - Guardian: G1 (E21.3C);
 * - User: E21.4 minimization (a non-login tombstone), only when no current
 *   purpose or hold remains anywhere; never physical deletion.
 *
 * There is no generic table or SQL executor. A subject type outside the
 * closed map fails closed.
 *
 * A School-scope case is planned only inside its own School; a
 * platform-scope (User) case never reads or touches any School's records.
 */
final class DataSubjectErasurePlanner
{
    /** subject type => its closed-list adapter */
    public const ADAPTERS = [
        'student' => StudentErasureAdapter::class,
        'employee' => EmployeeErasureAdapter::class,
        'guardian' => GuardianErasureAdapter::class,
        'user' => UserErasureAdapter::class,
    ];

    public function adapter(string $subjectType): ErasureSubjectAdapter
    {
        $class = self::ADAPTERS[$subjectType] ?? throw new InvalidArgumentException("No erasure adapter for subject type: {$subjectType}");

        return app($class);
    }

    /** @return list<ErasureCategory> read-only */
    public function plan(ErasureCase $case): array
    {
        return $this->adapter($case->subject_type)->plan($this->school($case), $case->subject_id);
    }

    /** @return list<ErasureCategory> after removing only the eligible categories */
    public function execute(ErasureCase $case): array
    {
        return $this->adapter($case->subject_type)->execute($this->school($case), $case->subject_id);
    }

    private function school(ErasureCase $case): ?School
    {
        return $case->school_id === null ? null : School::query()->findOrFail($case->school_id);
    }
}
