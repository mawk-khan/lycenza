<?php

namespace Tests\Feature\Tenancy;

use App\Models\Campus;
use App\Support\Tenancy\SchoolScope;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextRequiredException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Proves Laravel's application-layer scoping (Layer 1) independently
 * of PostgreSQL RLS (Layer 2, tested separately in tests/Postgres) --
 * section 36. RLS is defense in depth, not permission to write unsafe
 * application queries; both layers must independently hold.
 */
class SchoolScopeTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function orm_cannot_retrieve_school_bs_record_while_school_a_is_active(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB, ['name' => 'Campus B']);

        app(TenantContext::class)->set($schoolA);

        $this->assertNull(Campus::query()->find($campusB->id));
        $this->assertSame(0, Campus::query()->count());
    }

    #[Test]
    public function orm_returns_only_the_active_schools_own_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusA = $this->createCampus($schoolA);
        $this->createCampus($schoolB);

        app(TenantContext::class)->set($schoolA);

        $results = Campus::query()->get();

        $this->assertCount(1, $results);
        $this->assertSame($campusA->id, $results->first()->id);
    }

    #[Test]
    public function creating_a_tenant_owned_row_without_school_context_throws(): void
    {
        app(TenantContext::class)->clear();

        $this->expectException(TenantContextRequiredException::class);

        Campus::query()->create(['name' => 'Orphan', 'code' => 'ORP']);
    }

    #[Test]
    public function removing_the_eloquent_scope_still_cannot_leak_another_schools_row_because_rls_catches_it(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createCampus($schoolA);
        $this->createCampus($schoolB);

        app(TenantContext::class)->set($schoolA);

        // Simulates exactly the bug class RLS exists to catch: a query
        // that forgot/bypassed the Eloquent scope. Layer 1 is removed
        // here on purpose; Layer 2 (RLS, using the real school_os_app
        // connection this whole suite runs on -- ADR 0024) must still
        // return only School A's row.
        $unscopedResults = Campus::query()->withoutGlobalScope(SchoolScope::class)->get();

        $this->assertCount(1, $unscopedResults, 'RLS should have hidden School B\'s row even though the Eloquent scope was removed.');
    }
}
