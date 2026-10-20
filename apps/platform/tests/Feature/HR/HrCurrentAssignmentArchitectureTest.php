<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\CurrentEmployeeAssignments;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.7E-0 (ADR 0076 §42): one definition of an OPERATIONALLY CURRENT
 * employee assignment and Campus set -- CurrentEmployeeAssignments, on the
 * School-local date.
 *
 * - A class that reads employee assignments with its own date-range
 *   ("covers the date") predicate is the canonical resolver or a DECLARED
 *   reader with a semantically different question (display summary,
 *   reporting line, manager candidates).
 * - HR code never judges "today" on the UTC/server clock.
 * - The model keeps no stray `isCurrent()` helper.
 * - ORG.7E-1: exactly the operational HR subset is Campus-adopted, and it
 *   reaches the resolver only through HrEmployeeAccess.
 *
 * Comments are stripped before matching.
 */
class HrCurrentAssignmentArchitectureTest extends TestCase
{
    /** Reads employee assignments AND applies its own date-range predicate. */
    private const DATE_RANGE = "/['\"](?:\\w+\\.)?starts_on['\"]\\s*,\\s*['\"]<=['\"]|['\"](?:\\w+\\.)?ends_on['\"]\\s*,\\s*['\"]>=['\"]|starts_on\\s*<=|ends_on\\s*>=|whereBetween\\(|CURRENT_DATE|isCurrentInterval\\(/i";

    private const READS_ASSIGNMENTS = '/EmployeeAssignment\b|employee_assignments|->assignments\(\)/';

    /** Declared readers with a different question than "operationally current". */
    private const DECLARED_READERS = [
        'app/Domain/HR/Application/CurrentEmployeeAssignments.php' => 'THE canonical operational-current definition',
        'app/Domain/HR/Application/EmployeeDirectoryService.php' => 'display summary (current primary placement) and its Department/Position filters; the Campus filter delegates to the canonical resolver',
        'app/Domain/HR/Application/EmployeeProfileWorkspaceService.php' => 'display summary of the current primary placement on the School-local date',
        'app/Domain/HR/Application/EmployeePickerReadService.php' => 'reporting-manager candidates (ORG.7E-1 will scope them)',
        'app/Domain/HR/Application/ReportingLine.php' => 'the live reporting line on the caller\'s date (leave approval)',
    ];

    /** @return array<string, string> relative path => source without comments */
    private function sources(): array
    {
        $out = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $code = '';
            foreach (token_get_all($file->getContents()) as $token) {
                $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
            }
            $out[str_replace(base_path().'/', '', $file->getRealPath())] = $code;
        }

        return $out;
    }

    #[Test]
    public function only_declared_readers_judge_assignment_currency_themselves(): void
    {
        $found = [];
        foreach ($this->sources() as $path => $code) {
            if (preg_match(self::READS_ASSIGNMENTS, $code) === 1 && preg_match(self::DATE_RANGE, $code) === 1) {
                $found[] = $path;
                $this->assertArrayHasKey($path, self::DECLARED_READERS, "{$path} decides assignment currency itself: use CurrentEmployeeAssignments, or declare the different question it answers (ADR 0076 §42).");
            }
        }
        foreach (array_keys(self::DECLARED_READERS) as $declared) {
            $this->assertContains($declared, $found, "{$declared} is declared but no longer reads assignment dates: remove the declaration");
        }
    }

    #[Test]
    public function the_guard_recognizes_the_readers_it_exists_to_catch(): void
    {
        $this->assertSame(1, preg_match(self::DATE_RANGE, "->where('ea.starts_on', '<=', \$today)"));
        $this->assertSame(1, preg_match(self::DATE_RANGE, "->orWhere('ends_on', '>=', \$today)"));
        $this->assertSame(1, preg_match(self::DATE_RANGE, "->whereRaw('ea.starts_on <= ?', [\$d])"));
        $this->assertSame(1, preg_match(self::DATE_RANGE, 'where ends_on >= CURRENT_DATE'));
        $this->assertSame(0, preg_match(self::DATE_RANGE, "->where('starts_on', '>', \$date)"));
    }

    #[Test]
    public function hr_code_never_judges_today_on_the_utc_clock(): void
    {
        foreach ($this->sources() as $path => $code) {
            if (! str_starts_with($path, 'app/Domain/HR/') && ! str_starts_with($path, 'app/Http/Controllers/App/HR/')) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/(?<!SchoolTimezone)::today\(|(?<![\w>:$])today\((\)|\s*[\'"])|now\(\)->(toDateString|format)\(|Carbon(Immutable)?::now\(\)->(toDateString|startOfDay|format)\(|\bdate\(\s*[\'"]Y-m-d/', $code, "{$path} uses the UTC/server date: use SchoolTimezone::today() (ORG.7E-0)");
        }
    }

    #[Test]
    public function the_canonical_rule_is_one_resolver_and_grants_nothing(): void
    {
        $this->assertFalse(method_exists(EmployeeAssignment::class, 'isCurrent'), 'no model-level date-only currency helper');
        $code = $this->sources()['app/Domain/HR/Application/CurrentEmployeeAssignments.php'];
        $this->assertStringContainsString('EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES', $code, 'operational employment states come from the one pinned list');
        $this->assertStringContainsString('SchoolTimezone::today(', $code, 'the School-local date');
        $this->assertStringNotContainsString('is_primary', $code, 'primary status never limits the Campus set');

        // Data, never authorization: no controller consumes it (ORG.7E-1: the
        // HR Campus access layer, HrEmployeeAccess, is the one consumer of
        // its predicates for authority).
        foreach ($this->sources() as $path => $code) {
            if (str_contains($path, '/Http/Controllers/')) {
                $this->assertStringNotContainsString(class_basename(CurrentEmployeeAssignments::class), $code, "{$path}: the resolver is data for the HR access layer, never a controller's authority");
            }
        }
    }

    #[Test]
    public function exactly_the_operational_hr_subset_is_campus_adopted(): void
    {
        $hr = array_values(array_filter(CampusScopeCatalogue::capabilities(), fn (string $c) => str_starts_with($c, 'hr.')));
        sort($hr);
        $this->assertSame(['hr.employees.assignments.manage', 'hr.employees.assignments.view', 'hr.employees.manage', 'hr.employees.view'], $hr, 'ORG.7E-1 (ADR 0076 §43) adopted exactly the operational HR subset; any other HR key needs its own owner approval.');
        $this->assertArrayHasKey('hr', CampusScopeCatalogue::DOMAINS);
    }
}
