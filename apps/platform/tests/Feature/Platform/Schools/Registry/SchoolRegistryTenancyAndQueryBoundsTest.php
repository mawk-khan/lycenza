<?php

namespace Tests\Feature\Platform\Schools\Registry;

use App\Domain\Campuses\Application\CampusStructureSummary;
use App\Domain\Platform\Application\Schools\Registry\SchoolRegistryFilters;
use App\Domain\Platform\Application\Schools\Registry\SchoolRegistryReadService;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Authorization\SchoolAdministrators;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.3 (ADR 0076 §9.2): the registry reads RLS-protected structure only
 * through the owning modules, inside ONE School's TenantContext at a time,
 * restoring whatever context was there before -- and the list's work is
 * bounded by the page size, never by the number of Schools.
 */
class SchoolRegistryTenancyAndQueryBoundsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function equippedSchool(string $name): School
    {
        [, $school] = $this->createSchoolAdmin();
        $school->forceFill(['name' => $name])->save();
        $this->createCampus($school);
        $this->createAcademicYear($school, ['status' => 'active']);

        return $school->fresh();
    }

    #[Test]
    public function each_summary_reads_only_the_requested_school_and_restores_the_context(): void
    {
        $a = $this->equippedSchool('Tenancy A');
        $b = $this->equippedSchool('Tenancy B');
        $this->createCampus($b);
        $this->createCampus($b, ['status' => 'inactive']);
        $context = app(TenantContext::class);

        $context->clear();
        $this->assertSame(['active' => 1, 'inactive' => 0], app(CampusStructureSummary::class)->counts($a));
        $this->assertSame(['active' => 2, 'inactive' => 1], app(CampusStructureSummary::class)->counts($b));
        $this->assertNull($context->school(), 'no School context is left behind');

        // An ambient context of another School is restored, never borrowed.
        $context->withSchool($b, function () use ($a, $b, $context): void {
            $this->assertSame(['active' => 1, 'inactive' => 0], app(CampusStructureSummary::class)->counts($a));
            $this->assertSame(SchoolAdministrators::READY, app(SchoolAdministrators::class)->readiness($a));
            $this->assertSame($b->id, $context->school()?->id);
        });

        app(SchoolRegistryReadService::class)->list(new SchoolRegistryFilters(search: 'Tenancy'));
        $this->assertNull($context->school(), 'the list leaves no School context behind');
    }

    #[Test]
    public function inside_one_schools_context_another_schools_campuses_are_invisible(): void
    {
        $a = $this->equippedSchool('Rls A');
        $b = $this->equippedSchool('Rls B');

        // FORCE RLS on the runtime connection: even a query that names School
        // B by id sees nothing while School A is the context.
        $count = app(TenantContext::class)->withSchool($a, fn () => DB::table('campuses')->where('school_id', $b->id)->count());
        $this->assertSame(0, $count);
    }

    #[Test]
    public function the_administrator_pre_filter_never_changes_the_answer(): void
    {
        $school = $this->createSchool();
        $administrators = app(SchoolAdministrators::class);
        $this->assertSame(SchoolAdministrators::MISSING, $administrators->readiness($school));

        // Holding only one of the two administrator capabilities never qualifies.
        $half = $this->createUserWithCapabilities($school, ['school.members.manage']);
        $this->assertSame(SchoolAdministrators::MISSING, $administrators->readiness($school));
        $this->assertCount(0, $administrators->holdingAuthority($school));

        // Both capabilities through two separate grants still qualify.
        $split = $this->createUserWithCapabilities($school, ['school.members.manage']);
        $membership = SchoolMembership::query()->where('user_id', $split->id)->where('school_id', $school->id)->firstOrFail();
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['school.roles.manage']));
        $holders = $administrators->holdingAuthority($school)->pluck('id')->all();
        $this->assertContains($split->id, $holders);
        $this->assertNotContains($half->id, $holders);
    }

    #[Test]
    public function the_list_issues_the_same_queries_whatever_the_number_of_schools(): void
    {
        foreach (range(1, 10) as $i) {
            $this->equippedSchool(sprintf('Bound %02d', $i));
        }
        $small = $this->queriesForFirstPage(10);

        foreach (range(11, 30) as $i) {
            $this->equippedSchool(sprintf('Bound %02d', $i));
        }
        $large = $this->queriesForFirstPage(10);

        // 30 Schools cost exactly what 10 did for one 10-row page.
        $this->assertSame($small, $large);
        // And each row costs a fixed, small number of statements.
        $this->assertLessThanOrEqual(10 * 20, $large, "page of 10 issued {$large} statements");
    }

    private function queriesForFirstPage(int $perPage): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SchoolRegistryReadService::class)->list(new SchoolRegistryFilters(search: 'Bound', perPage: $perPage));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
