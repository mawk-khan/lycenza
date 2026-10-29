<?php

namespace Database\Seeders\Demo;

use App\Domain\Guardians\Application\StudentSiblingReader;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * SIS.EXP-1: proves the canonical SIS demonstration after every build --
 * semantic facts, read through the real readers where authority matters,
 * never by trusting the seed. DEMO-ONLY: run by DemoSeeder after
 * `ddev demo-reset` and by the automated suite; never a production health
 * check, endpoint or command.
 */
final class DemoSisVerifier
{
    /** @return list<string> problems (empty = the SIS demonstration is intact) */
    public function problems(): array
    {
        $school = School::query()->where('slug', DemoSisData::SCHOOL_SLUG)->first();
        if ($school === null) {
            return ['Northstar Gachibowli is missing'];
        }

        return app(TenantContext::class)->withSchool($school, function () use ($school): array {
            $problems = [];
            $check = function (bool $ok, string $message) use (&$problems): void {
                if (! $ok) {
                    $problems[] = $message;
                }
            };

            $placements = DB::table('student_enrollments as e')->join('campuses as c', 'c.id', '=', 'e.campus_id')
                ->where('e.school_id', $school->id)->where('e.status', 'active')
                ->selectRaw('c.code, count(*) as n')->groupBy('c.code')->pluck('n', 'code')->all();
            $check(Student::query()->count() === 13, 'Gachibowli does not have exactly 13 Students');
            $check(($placements['GCB-MAIN'] ?? 0) === 10 && ($placements['GCB-EY'] ?? 0) === 3, 'Gachibowli placements are not 10 on the Main Campus and 3 on Early Years');

            $aarav = Student::query()->where('first_name', 'Aarav')->where('last_name', 'Menon')->first();
            $diya = Student::query()->where('first_name', 'Diya')->where('last_name', 'Menon')->first();
            if ($aarav === null || $diya === null) {
                return [...$problems, 'the Menon siblings are missing'];
            }
            $check(DB::table('student_guardian_relationships')->where('student_id', $aarav->id)->count() === 2, 'Aarav Menon does not have two Guardians');
            $check(array_keys(app(StudentSiblingReader::class)->siblingsOf($aarav)) === [(string) $diya->id], 'Aarav Menon\'s only sibling is not Diya Menon');
            $placement = DB::table('student_enrollments as e')->join('sections as s', 's.id', '=', 'e.section_id')->join('grade_levels as g', 'g.id', '=', 'e.grade_level_id')->join('campuses as c', 'c.id', '=', 'e.campus_id')
                ->where('e.student_id', $aarav->id)->where('e.status', 'active')->first(['g.name as grade', 's.name as section', 'c.code as campus']);
            $check($placement !== null && [$placement->grade, $placement->section, $placement->campus] === ['Grade 5', 'A', 'GCB-MAIN'], 'Aarav Menon is not in Grade 5 · A on the Main Campus');

            // His Student 360 has something in every implemented card.
            $check(DB::table('charges')->where('student_id', $aarav->id)->whereNull('cancelled_at')->count() === 1, 'Aarav Menon has no single open Charge');
            $check(DB::table('payment_allocations')->join('charges', 'charges.id', '=', 'payment_allocations.charge_id')->where('charges.student_id', $aarav->id)->exists(), 'Aarav Menon\'s Charge has no payment');
            $check(DB::table('transport_student_assignments')->where('student_id', $aarav->id)->where('status', 'active')->exists(), 'Aarav Menon has no rider assignment');
            $check(DB::table('library_loans')->where('student_id', $aarav->id)->where('status', 'active')->exists(), 'Aarav Menon has no library loan');
            $check(DB::table('attendance_records as r')->join('student_enrollments as e', 'e.id', '=', 'r.student_enrollment_id')->where('e.student_id', $aarav->id)->count() === 5, 'Aarav Menon does not have five attendance records');

            // No relationship crosses a School (the composite keys say so; the demo proves it).
            $check(! DB::table('student_guardian_relationships as r')->join('students as st', 'st.id', '=', 'r.student_id')->join('guardians as g', 'g.id', '=', 'r.guardian_id')
                ->whereColumn('st.school_id', '!=', 'g.school_id')->exists(), 'a Student-Guardian link crosses Schools');

            // The Early Years Principal reaches exactly the Early Years Students.
            $principal = User::query()->where('email', 'gachibowli.ey.principal@example.test')->first();
            if ($principal === null) {
                $problems[] = 'the Early Years Principal is missing';
            } else {
                $scope = app(CampusScopeResolver::class)->campusScope($principal, 'students.view', $school);
                $reached = app(StudentAccess::class)->students($school, $scope)->orderBy('first_name')->pluck('first_name')->all();
                $check(! $scope->isWholeSchool() && $reached === ['Diya', 'Neel', 'Tara'], 'the Early Years Principal does not reach exactly the Early Years Students');
            }

            return $problems;
        });
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $school = School::query()->where('slug', DemoSisData::SCHOOL_SLUG)->firstOrFail();

        return app(TenantContext::class)->withSchool($school, fn (): array => [
            'gachibowliStudents' => Student::query()->count(),
            'gachibowliGuardians' => DB::table('guardians')->where('school_id', $school->id)->count(),
            'gachibowliActivePlacements' => DB::table('student_enrollments')->where('school_id', $school->id)->where('status', 'active')->count(),
        ]);
    }
}
