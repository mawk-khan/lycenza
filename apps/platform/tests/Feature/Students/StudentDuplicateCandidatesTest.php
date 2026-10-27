<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\StudentDuplicateCandidates;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 2.3: the one duplicate-risk rule -- same date of birth AND (same first
 * and last name, or same full name), compared case-, space- and
 * punctuation-insensitively, within one School only. Explainable, never
 * fuzzy: no phonetic or edit-distance matching.
 */
class StudentDuplicateCandidatesTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return array<string, array{0: array<string, string|null>, 1: array<string, string|null>, 2: bool}> */
    public static function names(): array
    {
        $riya = ['first_name' => 'Riya', 'middle_name' => null, 'last_name' => 'Kapoor'];

        return [
            'identical' => [$riya, $riya, true],
            'case and spacing' => [$riya, ['first_name' => '  RIYA ', 'middle_name' => null, 'last_name' => 'kapoor '], true],
            'a middle name on one side only' => [$riya, ['first_name' => 'Riya', 'middle_name' => 'Devi', 'last_name' => 'Kapoor'], true],
            'different middle names' => [['first_name' => 'Riya', 'middle_name' => 'Anand', 'last_name' => 'Kapoor'], ['first_name' => 'Riya', 'middle_name' => 'Devi', 'last_name' => 'Kapoor'], true],
            'punctuation and hyphens' => [['first_name' => 'Anne-Marie', 'middle_name' => null, 'last_name' => "D'Souza"], ['first_name' => 'Anne Marie', 'middle_name' => null, 'last_name' => 'DSouza'], true],
            'compound surname moved between fields' => [['first_name' => 'Kabir Das', 'middle_name' => null, 'last_name' => 'Gupta'], ['first_name' => 'Kabir', 'middle_name' => null, 'last_name' => 'Das Gupta'], true],
            'no last name on either side' => [['first_name' => 'Riya', 'middle_name' => null, 'last_name' => null], ['first_name' => 'Riya', 'middle_name' => null, 'last_name' => null], true],
            'different first name' => [$riya, ['first_name' => 'Riyaa', 'middle_name' => null, 'last_name' => 'Kapoor'], false],
            'different last name' => [$riya, ['first_name' => 'Riya', 'middle_name' => null, 'last_name' => 'Kapur'], false],
            'swapped first and last' => [$riya, ['first_name' => 'Kapoor', 'middle_name' => null, 'last_name' => 'Riya'], false],
        ];
    }

    /**
     * @param  array{first_name: string, middle_name: string|null, last_name: string|null}  $a
     * @param  array{first_name: string, middle_name: string|null, last_name: string|null}  $b
     */
    #[Test]
    #[DataProvider('names')]
    public function the_name_rule_is_explainable_and_symmetric(array $a, array $b, bool $expected): void
    {
        $this->assertSame($expected, StudentDuplicateCandidates::matches($a, $b));
        $this->assertSame($expected, StudentDuplicateCandidates::matches($b, $a));
    }

    #[Test]
    public function only_same_school_same_birthday_matches_are_candidates_inactive_included(): void
    {
        $school = $this->createSchool();
        $active = $this->createStudent($school, ['first_name' => 'Riya', 'middle_name' => null, 'last_name' => 'Kapoor', 'date_of_birth' => '2016-03-04', 'student_number' => 'B-2']);
        $inactive = $this->createStudent($school, ['first_name' => 'riya', 'middle_name' => 'D', 'last_name' => 'KAPOOR', 'date_of_birth' => '2016-03-04', 'student_number' => 'A-1', 'status' => 'inactive']);
        $this->createStudent($school, ['first_name' => 'Riya', 'middle_name' => null, 'last_name' => 'Kapoor', 'date_of_birth' => '2016-03-05']);
        $this->createStudent($this->createSchool(), ['first_name' => 'Riya', 'middle_name' => null, 'last_name' => 'Kapoor', 'date_of_birth' => '2016-03-04']);

        $found = $this->find($school, null);

        $this->assertSame([$inactive->id, $active->id], $found, 'Student-number order; different birthday and another School excluded');
        $this->assertSame($found, $this->find($school, CampusScope::wholeSchool()), 'a whole-School scope is the same set');
        $this->assertSame([], $this->find($school, CampusScope::none()), 'a scope of none compares with nobody');
    }

    #[Test]
    public function the_acknowledgement_binds_the_school_the_identity_and_the_exact_candidate_set(): void
    {
        $school = $this->createSchool();
        $evaluator = app(StudentDuplicateCandidates::class);
        $identity = ['first_name' => 'Riya', 'middle_name' => null, 'last_name' => 'Kapoor', 'date_of_birth' => '2016-03-04'];
        $one = $this->createStudent($school, ['first_name' => 'Riya', 'last_name' => 'Kapoor', 'date_of_birth' => '2016-03-04']);
        $two = $this->createStudent($school, ['first_name' => 'Riya', 'last_name' => 'Kapoor', 'date_of_birth' => '2016-03-04']);

        $base = $evaluator->acknowledgement($school, $identity, collect([$one, $two]));
        $this->assertSame($base, $evaluator->acknowledgement($school, $identity, collect([$two, $one])), 'order-independent');
        $this->assertNotSame($base, $evaluator->acknowledgement($school, $identity, collect([$one])), 'a different candidate set');
        $this->assertNotSame($base, $evaluator->acknowledgement($school, [...$identity, 'middle_name' => 'D'], collect([$one, $two])), 'an edited identity');
        $this->assertNotSame($base, $evaluator->acknowledgement($this->createSchool(), $identity, collect([$one, $two])), 'another School');
    }

    /** @return list<string> */
    private function find(School $school, ?CampusScope $scope): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => app(StudentDuplicateCandidates::class)
            ->find($school, ['first_name' => 'Riya', 'middle_name' => null, 'last_name' => 'Kapoor', 'date_of_birth' => '2016-03-04'], $scope)
            ->pluck('id')->map(fn ($id) => (string) $id)->all());
    }
}
