<?php

namespace Tests\Feature\Demo;

use App\Domain\Students\Application\Import\StudentGuardianImport;
use App\Models\School;
use App\Models\User;
use Database\Seeders\Demo\DemoDataBuilder;
use Database\Seeders\Demo\DemoSisData;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SIS.EXP-5: the committed demo import fixtures (docs/demo/fixtures) stay one
 * fully valid and one fully refused file against the canonical demo world.
 * They live under docs/, so a docs-only change to them runs this test too.
 */
#[Group('repository-documents')]
class DemoImportFixturesTest extends TestCase
{
    private function school(): School
    {
        return School::query()->where('slug', DemoSisData::SCHOOL_SLUG)->firstOrFail();
    }

    #[Test]
    public function the_demo_import_fixtures_stay_valid_and_invalid_against_the_canonical_world(): void
    {
        app(DemoDataBuilder::class)->build();
        $school = $this->school();
        $admin = User::query()->where('email', 'gachibowli.admin@example.test')->firstOrFail();
        $import = app(StudentGuardianImport::class);

        $valid = $import->preview($school, $admin, $import->parse((string) file_get_contents(base_path('../../docs/demo/fixtures/sis-exp-5-import-valid.csv'))));
        $this->assertTrue($valid['committable'], json_encode($valid['rows']));
        $this->assertSame(['rows' => 4, 'ready' => 4, 'warning' => 0, 'error' => 0, 'students' => 4, 'guardians' => 4, 'relationships' => 5, 'placements' => 4, 'campuses' => 2], $valid['summary']);

        $invalid = $import->preview($school, $admin, $import->parse((string) file_get_contents(base_path('../../docs/demo/fixtures/sis-exp-5-import-invalid.csv'))));
        $this->assertFalse($invalid['committable']);
        // Row 3 repeats row 2's Student (error); row 2 itself only warns that the same contact is on two new Guardians.
        $this->assertSame(['error', 'warning', 'error', 'error', 'error'], array_column($invalid['rows'], 'result'));
    }
}
