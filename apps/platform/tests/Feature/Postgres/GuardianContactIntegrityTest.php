<?php

namespace Tests\Feature\Postgres;

use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.3 (mandatory): `guardian_contacts` proven at the raw-SQL
 * level against real PostgreSQL, under the unprivileged `school_os_app`
 * runtime role, independent of Eloquent -- mirrors
 * StudentGuardianRelationshipIntegrityTest's pattern. Covers RLS
 * isolation, the composite-FK cross-School guardian integrity
 * guarantee, and (critically for this checkpoint) that no plaintext or
 * normalized-plaintext contact value is ever physically stored.
 */
class GuardianContactIntegrityTest extends TestCase
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
            ['guardian_contacts', 'public'],
        );

        $this->assertNotNull($row, 'guardian_contacts must exist');
        $this->assertTrue($row->relrowsecurity, 'guardian_contacts must have RLS enabled');
        $this->assertTrue($row->relforcerowsecurity, 'guardian_contacts must FORCE RLS');
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from guardian_contacts')->c;
        $this->assertSame(0, (int) $count, 'guardian_contacts must be invisible with no TenantContext');
    }

    #[Test]
    public function school_a_cannot_read_school_bs_contact_row(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);
        $contactB = $this->createGuardianContact($guardianB, ContactType::Email, 'parent@example.com');

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from guardian_contacts where id = ?', [$contactB->id]);
        $this->assertCount(0, $rows, "School A must not see School B's contact row");
    }

    #[Test]
    public function school_a_cannot_discover_school_bs_contact_by_uuid(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);
        $contactB = $this->createGuardianContact($guardianB, ContactType::Email, 'parent@example.com');

        $this->setSchool($schoolA->id);

        $this->assertCount(0, DB::connection('pgsql')->select('select 1 from guardian_contacts where id = ?', [$contactB->id]));
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);
        $contactB = $this->createGuardianContact($guardianB, ContactType::Email, 'parent@example.com');

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            'update guardian_contacts set is_primary = true where id = ?',
            [$contactB->id],
        ));
        $this->assertSame(0, DB::connection('pgsql')->delete(
            'delete from guardian_contacts where id = ?',
            [$contactB->id],
        ));
    }

    #[Test]
    public function school_a_cannot_create_a_contact_assigned_to_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        // Wrapped in its own transaction (a nested SAVEPOINT) so the
        // expected RLS WITH CHECK failure rolls back cleanly -- see
        // StudentGuardianRelationshipIntegrityTest's identical pattern.
        DB::connection('pgsql')->transaction(function () use ($schoolB, $guardianB): void {
            DB::connection('pgsql')->insert(
                'insert into guardian_contacts (id, school_id, guardian_id, type, encrypted_value, lookup_hash, created_at, updated_at) '.
                "values (?, ?, ?, 'email', 'irrelevant-ciphertext', 'irrelevant-hash', now(), now())",
                [(string) Str::orderedUuid(), $schoolB->id, $guardianB->id],
            );
        });
    }

    #[Test]
    public function a_contact_cannot_reference_another_schools_guardian(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);

        $this->expectException(QueryException::class);

        // Via pgsql_admin (bypasses RLS's own WITH CHECK, isolating
        // that the failure is purely the composite FK) -- mirrors
        // AcademicStructureCrossRelationTest's / Phase 1A.2's pattern.
        DB::connection('pgsql_admin')->insert(
            'insert into guardian_contacts (id, school_id, guardian_id, type, encrypted_value, lookup_hash, created_at, updated_at) '.
            "values (?, ?, ?, 'email', 'irrelevant-ciphertext', 'irrelevant-hash', now(), now())",
            [(string) Str::orderedUuid(), $schoolA->id, $guardianB->id],
        );
    }

    #[Test]
    public function plaintext_email_is_never_physically_stored(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $contact = $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');

        // Read via the SAME `pgsql` connection the write happened on
        // (with the matching School context re-set) -- `pgsql_admin`
        // is a genuinely separate database session, and
        // DatabaseTransactions only wraps the default `pgsql`
        // connection in a (never-committed) transaction, so a
        // pgsql_admin read cannot see this row at all until commit.
        $this->setSchool($school->id);

        $row = DB::connection('pgsql')->selectOne(
            'select encrypted_value, lookup_hash from guardian_contacts where id = ?',
            [$contact->id],
        );

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('parent@example.com', $row->encrypted_value);
        $this->assertStringNotContainsString('parent', $row->lookup_hash);
        $this->assertStringNotContainsString('example.com', $row->lookup_hash);
        // The lookup_hash is a fixed-length hex HMAC-SHA-256 digest --
        // not the plaintext, not a plain unkeyed hash of it either
        // (proven with the actual key in ContactLookupHasherTest).
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->lookup_hash);
    }

    #[Test]
    public function plaintext_mobile_is_never_physically_stored(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $contact = $this->createGuardianContact($guardian, ContactType::Mobile, '+919876543210');

        $this->setSchool($school->id);

        $row = DB::connection('pgsql')->selectOne(
            'select encrypted_value, lookup_hash from guardian_contacts where id = ?',
            [$contact->id],
        );

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('919876543210', $row->encrypted_value);
        $this->assertStringNotContainsString('919876543210', $row->lookup_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->lookup_hash);
    }

    #[Test]
    public function encryption_is_non_deterministic_for_the_same_plaintext(): void
    {
        $school = $this->createSchool();
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);
        $service = app(GuardianContactService::class);

        $contactA = $service->create($guardianA, ContactType::Email, 'shared@example.com');
        $contactB = $service->create($guardianB, ContactType::Email, 'shared@example.com');

        $this->setSchool($school->id);

        $rows = DB::connection('pgsql')->select(
            'select id, encrypted_value from guardian_contacts where id in (?, ?)',
            [$contactA->id, $contactB->id],
        );

        $this->assertCount(2, $rows);
        $ciphertexts = collect($rows)->pluck('encrypted_value')->all();
        $this->assertNotSame($ciphertexts[0], $ciphertexts[1], 'Identical plaintext must not produce identical ciphertext.');
    }
}
