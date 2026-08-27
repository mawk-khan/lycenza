<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10A (mandatory per root CLAUDE.md rule 28).
 */
class LibraryCopiesRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['library_copies', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $title = $this->createLibraryTitle($school);
        $this->createLibraryCopy($title);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from library_copies')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_copy(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $titleB = $this->createLibraryTitle($schoolB);
        $copyB = $this->createLibraryCopy($titleB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from library_copies where id = ?', [$copyB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_copy_for_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $titleB = $this->createLibraryTitle($schoolB);

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($schoolB, $titleB): void {
                DB::connection('pgsql')->table('library_copies')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolB->id,
                    'library_title_id' => $titleB->id,
                    'code' => 'CROSS-1',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'RLS WITH CHECK must reject a row written under School A context but claiming School B.');
    }

    #[Test]
    public function school_a_cannot_update_school_bs_copy(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $titleB = $this->createLibraryTitle($schoolB);
        $copyB = $this->createLibraryCopy($titleB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update library_copies set status = ? where id = ?',
            ['inactive', $copyB->id],
        );

        $this->assertSame(0, $updated);
    }

    /**
     * The exclusive composite-FK proof (checkpoint brief section 8): a
     * Copy row cannot reference a `library_title_id` belonging to a
     * DIFFERENT School than its own `school_id`, even under a raw
     * privileged (`pgsql_admin`) INSERT that bypasses RLS entirely --
     * this is PostgreSQL's own foreign-key constraint mechanism, not
     * RLS, rejecting the row.
     */
    #[Test]
    public function a_copy_cannot_reference_a_title_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $titleB = $this->createLibraryTitle($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('library_copies')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'library_title_id' => $titleB->id,
                'code' => 'CROSS-FK-1',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (library_title_id, school_id) -> library_titles(id, school_id) must reject a cross-School parent reference at the database level.');
    }
}
