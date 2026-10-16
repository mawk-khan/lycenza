<?php

namespace Tests\Feature\Students;

use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C4 (UAT-SRCH-01): staff find a Student the way they type -- a full
 * name in any order (middle names never in the way), any part of the
 * Student number -- in the Students directory and the Student pickers.
 * Matching only: the same School, capability and status rules decide who
 * can be found; typed `%` and `_` are literal.
 */
class StudentSearchTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->createStudent($this->school, ['first_name' => 'Riya', 'middle_name' => null, 'last_name' => 'Kapoor', 'student_number' => 'LDS-0103']);
        $this->createStudent($this->school, ['first_name' => 'Arjun', 'middle_name' => null, 'last_name' => 'Kapoor', 'student_number' => 'LDS-0107']);
        $this->createStudent($this->school, ['first_name' => 'Aarav', 'middle_name' => 'Kumar', 'last_name' => 'Mehta', 'student_number' => 'LDS-0210']);
        $this->createStudent($this->school, ['first_name' => 'Sam_100', 'middle_name' => null, 'last_name' => 'Percent', 'student_number' => 'LDS-0999']);
    }

    private function as(array $capabilities, ?School $school = null): User
    {
        $school ??= $this->school;
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    /** @return list<string> "First Last" of the directory rows */
    private function directory(string $query): array
    {
        return collect($this->get('/app/students?'.$query)->assertOk()->viewData('page')['props']['students']['data'])
            ->map(fn (array $s) => "{$s['firstName']} {$s['lastName']}")->sort()->values()->all();
    }

    /** @return list<string> student numbers the Finance charge picker returns */
    private function picker(string $q): array
    {
        return collect($this->getJson('/app/finance/charges/students/search?q='.urlencode($q))->assertOk()->json('data'))
            ->pluck('studentNumber')->sort()->values()->all();
    }

    #[Test]
    public function the_directory_finds_full_names_in_any_order_and_number_fragments(): void
    {
        $this->as(['students.view']);

        $this->assertSame(['Arjun Kapoor', 'Riya Kapoor'], $this->directory('name=kapoor'), 'One word: any name field.');
        $this->assertSame(['Riya Kapoor'], $this->directory('name=Riya+Kapoor'), 'UAT-SRCH-01: the full name.');
        $this->assertSame(['Riya Kapoor'], $this->directory('name=kapoor+riya'));
        $this->assertSame(['Aarav Mehta'], $this->directory('name=Aarav+Mehta'), 'A middle name is not in the way.');
        $this->assertSame(['Riya Kapoor'], $this->directory('student_number=0103'), 'UAT-SRCH-01: a number fragment.');
        $this->assertSame(['Riya Kapoor'], $this->directory('student_number=lds-0103'), 'Case-insensitive.');
        $this->assertSame([], $this->directory('name=Riya+Mehta'), 'Every word must match the same Student.');
        $this->assertSame(['Riya Kapoor'], $this->directory('name=riya&student_number=01'), 'Filters still combine.');
    }

    #[Test]
    public function typed_wildcards_are_literal(): void
    {
        $this->as(['students.view']);

        $this->assertSame([], $this->directory('name=%25'), 'A typed % matches only a literal %.');
        $this->assertSame(['Sam_100 Percent'], $this->directory('name=_1'), 'A typed _ matches only a literal _.');
        $this->assertSame([], $this->directory('student_number=LDS_0103'), '_ is not a single-character wildcard.');
    }

    #[Test]
    public function pickers_match_names_and_numbers_the_same_way(): void
    {
        $this->as(['finance.charges.manage']);

        $this->assertSame(['LDS-0103'], $this->picker('Riya Kapoor'));
        $this->assertSame(['LDS-0103'], $this->picker('0103'));
        $this->assertSame(['LDS-0103', 'LDS-0107'], $this->picker('kapoor'));
        $this->assertSame(['LDS-0210'], $this->picker('aarav 0210'), 'A name word and a number word together.');
    }

    #[Test]
    public function search_never_reaches_another_school_or_an_unauthorized_user(): void
    {
        $other = $this->createSchool();
        $this->createStudent($other, ['first_name' => 'Riya', 'last_name' => 'Kapoor', 'student_number' => 'OTH-0103']);

        $this->as(['students.view']);
        $this->assertSame(['Riya Kapoor'], $this->directory('name=Riya+Kapoor'));
        $this->as(['finance.charges.manage']);
        $this->assertSame(['LDS-0103'], $this->picker('Riya Kapoor'), "Never the other School's Riya Kapoor.");

        $this->as(['attendance.view']);
        $this->get('/app/students?name=Riya')->assertForbidden();
        $this->getJson('/app/finance/charges/students/search?q=Riya')->assertForbidden();
    }
}
