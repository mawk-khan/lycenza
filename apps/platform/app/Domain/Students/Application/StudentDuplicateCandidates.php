<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Support\Collection;

/**
 * SIS 2.3: THE duplicate-risk evaluator for a new Student. One explainable
 * rule, School-scoped, never fuzzy:
 *
 *   same date of birth AND (the same first and last name, OR the same full
 *   name)
 *
 * where a name is compared by its {@see self::key()}: lower case, with every
 * space, hyphen, apostrophe and other punctuation removed. So "Riya Kapoor",
 * "RIYA  KAPOOR" and "Riya Kapoor" with a middle name all match, and
 * "Kabir Das" + "Gupta" matches "Kabir" + "Das Gupta" (the full name moved
 * between fields). No phonetic, edit-distance or AI matching, no
 * cross-School matching, no Guardian or contact data (ADR 0041).
 *
 * A match is a WARNING, never a refusal on its own: two different children
 * may share a name and a birthday. StudentService::create() turns it into
 * PossibleDuplicateStudentException unless the caller presents
 * {@see self::acknowledgement()} for exactly the current candidate set.
 *
 * Inactive Students are candidates too (a returning child must be noticed,
 * never silently recreated). The scope limits WHICH Students may be compared
 * -- a Campus onboarder is compared only with the Students they may already
 * see -- it never widens anything.
 */
final class StudentDuplicateCandidates
{
    public function __construct(private readonly StudentAccess $access) {}

    /**
     * The School's Students matching this identity, in Student-number order.
     * Runs inside the caller's School context (and, for creation, after the
     * School's Student-number counter lock, so concurrent creations in one
     * School each see every Student committed before them).
     *
     * $scope null = the whole School; a CampusScope narrows the comparison to
     * the Students that scope reaches (StudentAccess), none to nobody.
     *
     * @param  array{first_name: string, middle_name?: string|null, last_name?: string|null, date_of_birth: string}  $identity
     * @return Collection<int, Student>
     */
    public function find(School $school, array $identity, ?CampusScope $scope = null): Collection
    {
        if ($scope?->isNone()) {
            return new Collection;
        }

        $students = $scope === null ? Student::query()->where('students.school_id', $school->id) : $this->access->students($school, $scope);

        return $students
            ->whereDate('students.date_of_birth', $identity['date_of_birth'])
            ->orderBy('students.student_number')
            ->get()
            ->filter(fn (Student $s): bool => self::matches($identity, [
                'first_name' => $s->first_name, 'middle_name' => $s->middle_name, 'last_name' => $s->last_name,
            ]))
            ->values()
            ->toBase();
    }

    /**
     * SIS 8.1: every group of the School's EXISTING Students this same rule
     * links -- the Data Health view of possible duplicates. SQL narrows to
     * Students who share a date of birth with another Student of the School;
     * the name comparison is {@see self::matches()} itself, so creation,
     * conversion and Data Health can never disagree. A group is the
     * connected set (A matches B, B matches C: one group of three), each in
     * Student-number order; groups are ordered by their first Student
     * number. Inactive Students are included. Whole-School by construction:
     * the caller authorizes whole-School `students.view` first.
     *
     * @return list<list<Student>>
     */
    public function groupsIn(School $school): array
    {
        $sharedBirthdays = Student::query()->where('school_id', $school->id)
            ->select('date_of_birth')->groupBy('date_of_birth')->havingRaw('count(*) > 1');

        $groups = [];
        $bucket = [];
        $birthday = null;
        $rows = Student::query()->where('school_id', $school->id)
            ->whereIn('date_of_birth', $sharedBirthdays)
            ->select(['id', 'school_id', 'student_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'status'])
            ->orderBy('date_of_birth')->orderBy('student_number')->orderBy('id')
            ->lazy(500);
        foreach ($rows as $student) {
            $day = $student->date_of_birth->toDateString();
            if ($day !== $birthday) {
                array_push($groups, ...self::link($bucket));
                $bucket = [];
                $birthday = $day;
            }
            $bucket[] = $student;
        }
        array_push($groups, ...self::link($bucket));

        usort($groups, fn (array $a, array $b): int => strcmp($a[0]->student_number, $b[0]->student_number));

        return $groups;
    }

    /**
     * The connected groups (two or more) among Students of one birthday.
     *
     * @param  list<Student>  $students
     * @return list<list<Student>>
     */
    private static function link(array $students): array
    {
        $parent = array_keys($students);
        $root = function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $i = $parent[$i] = $parent[$parent[$i]];
            }

            return $i;
        };
        foreach ($students as $i => $a) {
            foreach (array_slice($students, $i + 1, null, true) as $j => $b) {
                if (self::matches($a->only(['first_name', 'middle_name', 'last_name']), $b->only(['first_name', 'middle_name', 'last_name']))) {
                    $parent[$root($j)] = $root($i);
                }
            }
        }

        $groups = [];
        foreach ($students as $i => $student) {
            $groups[$root($i)][] = $student;
        }

        return array_values(array_filter($groups, fn (array $group): bool => count($group) > 1));
    }

    /**
     * The acknowledgement that lets this exact identity be created despite
     * exactly these candidates. Opaque and keyed (never stored): it changes
     * when the names or date of birth are edited, or when a candidate
     * appears or disappears, so an acknowledgement of an earlier warning
     * never covers a different one.
     *
     * @param  array{first_name: string, middle_name?: string|null, last_name?: string|null, date_of_birth: string}  $identity
     * @param  Collection<int, Student>  $candidates
     */
    public function acknowledgement(School $school, array $identity, Collection $candidates): string
    {
        $ids = $candidates->map(fn (Student $s): string => (string) $s->id)->sort()->values()->all();

        return hash_hmac('sha256', implode('|', [
            'student-duplicate-v1',
            $school->id,
            substr($identity['date_of_birth'], 0, 10),
            self::key($identity['first_name']),
            self::key($identity['middle_name'] ?? null),
            self::key($identity['last_name'] ?? null),
            ...$ids,
        ]), (string) config('app.key'));
    }

    /**
     * Name comparison key: lower case; letters and digits only.
     */
    public static function key(?string $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $value));
    }

    /**
     * @param  array{first_name: string, middle_name?: string|null, last_name?: string|null}  $a
     * @param  array{first_name: string, middle_name?: string|null, last_name?: string|null}  $b
     */
    public static function matches(array $a, array $b): bool
    {
        $first = self::key($a['first_name']);
        if ($first !== '' && $first === self::key($b['first_name']) && self::key($a['last_name'] ?? null) === self::key($b['last_name'] ?? null)) {
            return true;
        }

        $full = self::key($a['first_name'].($a['middle_name'] ?? '').($a['last_name'] ?? ''));

        return $full !== '' && $full === self::key($b['first_name'].($b['middle_name'] ?? '').($b['last_name'] ?? ''));
    }
}
