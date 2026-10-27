<?php

namespace App\Domain\Students\Application\DataHealth;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SIS 8.1: Student numbers of one School that differ only in letter case
 * (`ab-1` and `AB-1`). Uniqueness (`students_school_id_student_number_unique`,
 * the raw column), the API `?student_number=` filter and the Transport rider
 * import compare exactly; every search (StudentSearch, the directories,
 * pickers, Finance and Library lookups) compares case-insensitively. So such
 * a pair is legal but ambiguous: a search finds both, an exact match one.
 * Only an edited number can collide -- allocated numbers are digits only.
 *
 * Nothing is normalized or rewritten here; the identifier contract is a
 * later decision. One finding per case-insensitive number; the finding
 * total is the number of such numbers.
 */
class StudentNumberCaseCollisionCheck implements DataHealthCheck
{
    use PresentsStudentFindings;

    public function definition(): D
    {
        return new D(
            code: 'student.number_case_collision',
            area: 'Student records',
            title: 'Student numbers that differ only in letter case',
            condition: 'Two or more Students have Student numbers that are the same apart from upper and lower case letters, such as "ab-1" and "AB-1".',
            whyItMatters: 'Searches treat these numbers as the same and show every Student with them, while exact matches (the Student API filter and the transport rider import) treat them as different. Staff can pick or import the wrong Student.',
            severity: D::REVIEW,
            kind: D::INCONSISTENT,
            viewCapabilities: ['students.view', 'enrollments.view'],
            repair: 'Give one of the Students a clearly different Student number on its Edit page. Numbers are never changed automatically.',
        );
    }

    public function hasFindings(School $school): bool
    {
        return $this->collidingNumbers($school)->exists();
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        $keys = $this->collidingNumbers($school)->orderBy('number_key')
            ->paginate($perPage, ['*'], 'page', $page);
        $students = Student::query()->where('school_id', $school->id)
            ->whereIn(DB::raw('lower(student_number)'), collect($keys->items())->pluck('number_key')->all())
            // PostgreSQL's own lower(), the same folding the searches' ilike uses.
            ->select(self::STUDENT_COLUMNS)->selectRaw('lower(student_number) AS number_key')
            ->orderBy('student_number')->orderBy('id')->get()
            ->groupBy('number_key');

        return $keys->through(function ($row) use ($students) {
            $group = $students->get($row->getAttribute('number_key'), collect())->values();

            return new DataHealthFinding(
                entityType: 'student_number',
                entityId: (string) $group->first()?->id,
                label: $group->map(fn (Student $s): string => self::studentLabel($s))->implode(' · '),
                detail: $group->count().' Student numbers that differ only in letter case: '.$group->pluck('student_number')->implode(', '),
                links: $group->flatMap(fn (Student $s) => [
                    new DataHealthLink("Open {$s->student_number}", "/app/students/{$s->id}", ['students.view']),
                    new DataHealthLink("Edit {$s->student_number}", "/app/students/{$s->id}/edit", ['students.manage'], isRepair: true),
                ])->all(),
            );
        });
    }

    /** @return Builder<Student> one row per case-insensitive number held by two or more Students */
    private function collidingNumbers(School $school)
    {
        return Student::query()->where('school_id', $school->id)
            ->selectRaw('lower(student_number) AS number_key')
            ->groupByRaw('lower(student_number)')
            ->havingRaw('count(*) > 1');
    }
}
