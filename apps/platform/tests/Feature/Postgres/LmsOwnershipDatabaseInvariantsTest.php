<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesLmsOwnershipFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.5B (ADR 0063 section 35) -- the LMS ownership and Section audience
 * invariants, proven with raw SQL under the real runtime role
 * (`school_os_app`) for both resources. Everything here happens in the test's
 * one transaction, so the deferred ">= 1 audience" check is fired with
 * SET CONSTRAINTS ... IMMEDIATE; its commit-time behaviour and the
 * "later transaction" refusals are proven in
 * LmsOwnershipCommitSemanticsTest.
 */
class LmsOwnershipDatabaseInvariantsTest extends TestCase
{
    use CreatesLmsOwnershipFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertParent(string $table, array $w, ?string $ownerId, array $overrides = []): string
    {
        $r = $this->lmsResource($table);
        $id = (string) new UuidV7;
        DB::connection('pgsql')->table($table)->insert(array_merge([
            'id' => $id, 'school_id' => $w['school']->id, 'subject_offering_id' => $w['offering']->id,
            'title' => 'Resource', 'status' => 'draft', 'owner_employee_id' => $ownerId,
            'created_at' => now(), 'updated_at' => now(),
        ], $r['columns'], $overrides));

        return $id;
    }

    private function insertAudience(string $table, array $w, string $parentId, $section, array $overrides = []): void
    {
        $r = $this->lmsResource($table);
        DB::connection('pgsql')->table($r['bridge'])->insert(array_merge([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, $r['fk'] => $parentId,
            'subject_offering_id' => $w['offering']->id, 'academic_year_id' => $section->academic_year_id,
            'campus_id' => $section->campus_id, 'grade_level_id' => $section->grade_level_id,
            'section_id' => $section->id, 'created_at' => now(),
        ], $overrides));
    }

    /** Fires the deferred ">= 1 audience" check now. */
    private function checkDeferred(): void
    {
        DB::connection('pgsql')->statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::connection('pgsql')->statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function assertRefused(string $needle, callable $op, string $message): void
    {
        try {
            DB::connection('pgsql')->transaction($op);
            $this->fail($message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage(), $message);
        }
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function an_offering_wide_row_has_no_owner_and_no_audience(string $table): void
    {
        $w = $this->ownershipWorld();
        $this->setSchool($w['school']->id);
        $id = $this->insertParent($table, $w, null, ['ownership_txid' => '12345']);
        $this->checkDeferred();

        $row = DB::connection('pgsql')->table($table)->where('id', $id)->first();
        $this->assertNull($row->owner_employee_id);
        $this->assertNull($row->ownership_txid, 'An unowned row never records an ownership transaction, whatever the caller sends.');
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function a_teacher_owned_row_takes_one_or_more_audience_sections_in_its_creating_transaction(string $table): void
    {
        $w = $this->ownershipWorld();
        $this->setSchool($w['school']->id);

        $one = $this->insertParent($table, $w, $w['employee']->id, ['ownership_txid' => '1']);
        $this->insertAudience($table, $w, $one, $w['sectionA']);
        $two = $this->insertParent($table, $w, $w['employee']->id);
        $this->insertAudience($table, $w, $two, $w['sectionA']);
        $this->insertAudience($table, $w, $two, $w['sectionB']);
        $this->checkDeferred();

        $bridge = $this->lmsResource($table)['bridge'];
        $fk = $this->lmsResource($table)['fk'];
        $this->assertSame(1, DB::connection('pgsql')->table($bridge)->where($fk, $one)->count());
        $this->assertSame(2, DB::connection('pgsql')->table($bridge)->where($fk, $two)->count());
        $txid = DB::connection('pgsql')->selectOne("select ownership_txid::text as t, pg_current_xact_id()::text as now from {$table} where id = ?", [$one]);
        $this->assertSame($txid->now, $txid->t, 'The trigger overwrote the caller-supplied transaction id.');

        $this->assertRefused("{$bridge}_unique", fn () => $this->insertAudience($table, $w, $one, $w['sectionA']), 'A Section appears once per audience.');
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function a_teacher_owned_row_without_an_audience_is_refused(string $table): void
    {
        $w = $this->ownershipWorld();
        $this->setSchool($w['school']->id);

        $this->assertRefused('needs at least one Section audience', function () use ($table, $w) {
            $this->insertParent($table, $w, $w['employee']->id);
            DB::connection('pgsql')->statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'An owned row with zero audience rows must not survive the deferred check.');
        DB::connection('pgsql')->statement('SET CONSTRAINTS ALL DEFERRED');
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function an_offering_wide_row_cannot_carry_an_audience(string $table): void
    {
        $w = $this->ownershipWorld();
        $this->setSchool($w['school']->id);
        $id = $this->insertParent($table, $w, null);

        $this->assertRefused('Offering-wide (unowned) resource cannot carry a Section audience',
            fn () => $this->insertAudience($table, $w, $id, $w['sectionA']), 'owner NULL + audience is not a valid state.');
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function the_owner_is_immutable_in_every_direction(string $table): void
    {
        $w = $this->ownershipWorld();
        $this->setSchool($w['school']->id);
        $owned = $this->insertParent($table, $w, $w['employee']->id);
        $this->insertAudience($table, $w, $owned, $w['sectionA']);
        $legacy = $this->insertParent($table, $w, null);
        $rows = DB::connection('pgsql')->table($table);

        foreach ([
            'teacher -> another Employee' => [$owned, ['owner_employee_id' => $w['employee2']->id]],
            'teacher -> NULL' => [$owned, ['owner_employee_id' => null]],
            'NULL -> teacher' => [$legacy, ['owner_employee_id' => $w['employee']->id]],
            'ownership transaction' => [$owned, ['ownership_txid' => '1']],
        ] as $label => [$id, $change]) {
            $this->assertRefused('owner of an LMS resource is immutable', fn () => (clone $rows)->where('id', $id)->update($change), $label);
        }

        // Every other field keeps its ordinary LMS rules.
        $this->assertSame(1, (clone $rows)->where('id', $owned)->update(['title' => 'Renamed', 'status' => 'published']));
        $this->assertSame(1, (clone $rows)->where('id', $legacy)->update(['title' => 'Renamed', 'status' => 'published']));
        $this->checkDeferred();
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function the_audience_is_immutable(string $table): void
    {
        $w = $this->ownershipWorld();
        $this->setSchool($w['school']->id);
        $r = $this->lmsResource($table);
        $owned = $this->insertParent($table, $w, $w['employee']->id);
        $this->insertAudience($table, $w, $owned, $w['sectionA']);
        $this->checkDeferred();
        $audience = fn (string $connection) => DB::connection($connection)->table($r['bridge'])->where($r['fk'], $owned);

        // The runtime role holds neither UPDATE nor DELETE on the bridge ...
        $this->assertRefused('permission denied', fn () => $audience('pgsql')->update(['section_id' => $w['sectionB']->id]), 'runtime repoint');
        $this->assertRefused('permission denied', fn () => $audience('pgsql')->delete(), 'runtime delete');

        $this->assertSame(1, $audience('pgsql')->count());

        // The parent's Offering cannot move out from under its audience.
        $this->assertRefused("{$r['bridge']}_parent_fk",
            fn () => DB::connection('pgsql')->table($table)->where('id', $owned)->update(['subject_offering_id' => $w['sibling']->id]),
            'An owned row keeps its Offering.');
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function the_owner_must_be_an_employee_of_the_same_school(string $table): void
    {
        $w = $this->ownershipWorld();
        $other = $this->ownershipWorld();
        $this->setSchool($w['school']->id);

        $this->assertRefused("{$table}_owner_employee_fk",
            fn () => $this->insertParent($table, $w, $other['employee']->id), 'Another School\'s Employee cannot own this row.');
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function every_audience_section_is_pinned_to_the_parents_offering_context(string $table): void
    {
        $w = $this->ownershipWorld();
        $other = $this->ownershipWorld();
        $this->setSchool($w['school']->id);
        $bridge = $this->lmsResource($table)['bridge'];
        $owned = $this->insertParent($table, $w, $w['employee']->id);
        $this->insertAudience($table, $w, $owned, $w['sectionA']);

        // A Section outside the context, carried with its OWN true context:
        // the row then contradicts the Offering's context.
        foreach (['otherGrade', 'otherCampus', 'otherYear'] as $section) {
            $this->assertRefused("{$bridge}_offering_fk",
                fn () => $this->insertAudience($table, $w, $owned, $w[$section]), "{$section} (own context)");
        }

        // The same Section carried with the OFFERING's context instead: the
        // Section FK then refuses it.
        foreach (['otherGrade', 'otherCampus', 'otherYear'] as $section) {
            $this->assertRefused("{$bridge}_section_fk", fn () => $this->insertAudience($table, $w, $owned, $w[$section], [
                'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id,
            ]), "{$section} (Offering's context)");
        }

        // Another School's Section.
        $this->assertRefused("{$bridge}_section_fk", fn () => $this->insertAudience($table, $w, $owned, $other['sectionA'], [
            'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id,
        ]), 'cross-School Section');

        // The audience row claiming another Offering than its parent's.
        $this->assertRefused("{$bridge}_parent_fk",
            fn () => $this->insertAudience($table, $w, $owned, $w['sectionB'], ['subject_offering_id' => $w['sibling']->id]),
            'An audience row cannot name another Offering than its parent.');
        $this->checkDeferred();
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function the_audience_table_is_tenant_isolated(string $table): void
    {
        $bridge = $this->lmsResource($table)['bridge'];
        $flags = DB::connection('pgsql_admin')->selectOne(
            "select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = 'public'::regnamespace", [$bridge],
        );
        $this->assertTrue($flags->relrowsecurity && $flags->relforcerowsecurity, "{$bridge} must have RLS enabled and forced.");

        $w = $this->ownershipWorld();
        $other = $this->ownershipWorld();
        $this->setSchool($w['school']->id);
        $owned = $this->insertParent($table, $w, $w['employee']->id);
        $this->insertAudience($table, $w, $owned, $w['sectionA']);
        $this->checkDeferred();
        $this->assertSame(1, DB::connection('pgsql')->table($bridge)->count());

        $this->setSchool($other['school']->id);
        $this->assertSame(0, DB::connection('pgsql')->table($bridge)->count(), 'School B sees none of School A\'s audience.');
        // Under School B's context the parent is invisible, so the insert
        // guard refuses before the RLS WITH CHECK would.
        $this->assertRefused('not found', fn () => $this->insertAudience($table, $w, $owned, $w['sectionB']),
            'A School A audience row cannot be written under School B\'s context.');

        $this->setSchool('');
        $this->assertSame(0, DB::connection('pgsql')->table($bridge)->count(), 'No School context sees nothing.');
    }
}
