<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\CampusStudentOnboardingService;
use App\Domain\Students\Application\StudentNameEditService;
use App\Http\Controllers\App\StudentDirectoryController;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Tests\Support\Authorization\Coverage;
use Tests\Support\Authorization\SchoolRouteCoverage;
use Tests\TestCase;

/**
 * ORG.7F-2 (ADR 0076 §46): the narrow Campus SIS writes stay narrow -- pinned
 * on the code itself, never on comments.
 *
 * - The name edit writes exactly first/middle/last name; its route validates
 *   exactly those.
 * - Onboarding validates exactly the approved fields: never a Student number,
 *   a Student id, a status or a Guardian; the Student number comes only from
 *   StudentNumberAllocator, and onboarding is the allocator's only caller.
 * - Only the name edit and onboarding are Campus (C6) write routes of
 *   students.manage / enrollments.manage; generic enroll, transfer,
 *   lifecycle, rollover, Guardian links, account links and the full identity
 *   edit are declared whole-School.
 */
class StudentCampusWriteArchitectureTest extends TestCase
{
    private function body(string $class, string $method): string
    {
        $m = new ReflectionMethod($class, $method);
        $lines = file((string) $m->getFileName());

        return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    /** @return list<string> the keys of the action's $request->validate([...]) */
    private function validatedKeys(string $method): array
    {
        preg_match('/->validate\(\[(.*?)\]\);/s', $this->body(StudentDirectoryController::class, $method), $m);
        preg_match_all("/^\\s*'([a-z_]+)' =>/m", $m[1] ?? '', $keys);
        $found = $keys[1];
        sort($found);

        return $found;
    }

    #[Test]
    public function the_name_edit_writes_and_accepts_exactly_the_three_names(): void
    {
        $this->assertSame(['first_name', 'middle_name', 'last_name'], StudentNameEditService::FIELDS);
        $this->assertSame(['first_name', 'last_name', 'middle_name'], $this->validatedKeys('updateNames'));
        $edit = $this->body(StudentNameEditService::class, 'edit');
        $this->assertStringContainsString('lockedWriteTarget(', $edit);
        foreach (['student_number', 'date_of_birth', "'status'", '->update(', '->fill('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $edit, "the name edit never touches {$forbidden}");
        }
    }

    #[Test]
    public function onboarding_accepts_only_the_approved_fields_and_numbers_come_from_the_allocator(): void
    {
        // SIS 2.3: plus the opaque duplicate acknowledgement (never a number or an id).
        $this->assertSame(['date_of_birth', 'duplicate_acknowledgement', 'first_name', 'last_name', 'middle_name', 'roll_number', 'section_id'], $this->validatedKeys('storeOnboarding'));

        $onboard = $this->body(CampusStudentOnboardingService::class, 'onboard');
        $this->assertStringContainsString('$this->students->create($school, [', $onboard, 'the one Student creation path (which allocates the number)');
        foreach (["'student_number'", "\$input['student_id']", "\$input['status']", 'Guardian', 'Student::query()'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $onboard, "onboarding never uses {$forbidden}: it creates identity, never finds one");
        }

        // SIS 2.3: ONE numbering policy -- StudentService::create() is the only
        // allocator caller, and the only writer that creates a Student row.
        $callers = [];
        $creators = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $contents = $file->getContents();
            if (str_contains($contents, 'StudentNumberAllocator') && ! str_ends_with($file->getRelativePathname(), 'StudentNumberAllocator.php')) {
                $callers[] = $file->getRelativePathname();
            }
            $code = (string) preg_replace('/^\s*(\*|\/\/|\/\*).*$/m', '', $contents);
            if (preg_match('/Student::(query\(\)->)?(create|forceCreate|insert|firstOrCreate|updateOrCreate)\(|->students\(\)->create\(/', $code) === 1) {
                $creators[] = $file->getRelativePathname();
            }
        }
        $this->assertSame(['Domain/Students/Application/StudentService.php'], $callers, 'every Student number comes from the School counter, through StudentService');
        $this->assertSame(['Domain/Students/Application/StudentService.php'], $creators, 'every Student is created by StudentService::create()');
    }

    #[Test]
    public function only_the_name_edit_and_onboarding_are_campus_writes_of_the_manage_keys(): void
    {
        $campusWrites = [];
        foreach (SchoolRouteCoverage::entries() as $entry) {
            if ($entry->class === Coverage::CAMPUS && array_intersect($entry->capabilities, ['students.manage', 'enrollments.manage']) !== []) {
                $campusWrites[] = $entry->route;
            }
        }
        sort($campusWrites);
        $this->assertSame(['api.v1.schools.enrollments.transfer', 'app.students.names.edit', 'app.students.names.update', 'app.students.onboard', 'app.students.onboard.store', 'app.students.transfer.create', 'app.students.transfer.store'], $campusWrites);

        foreach ([
            'app.students.create', 'app.students.store', 'app.students.edit', 'app.students.update', 'app.students.status',
            'app.students.enrollments.create', 'app.students.enrollments.store', 'api.v1.schools.students.enrollments.store',
            'app.enrollments.transfer.create', 'app.enrollments.transfer.store',
            'app.enrollments.withdraw', 'app.enrollments.complete', 'app.enrollments.cancel', 'app.enrollments.closed-year-placements.complete',
            'app.enrollment-rollovers.start', 'api.v1.schools.enrollment-rollovers.start',
            'app.students.guardians.link', 'app.relationships.destroy', 'app.students.account-link.store',
            'api.v1.schools.students.store', 'api.v1.schools.students.update', 'api.v1.schools.students.status',
            'app.students.show', 'app.students.history',
        ] as $route) {
            $this->assertArrayHasKey($route, CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS, "{$route} stays a declared whole-School operation");
        }
    }
}
