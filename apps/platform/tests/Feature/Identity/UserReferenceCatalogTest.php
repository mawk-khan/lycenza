<?php

namespace Tests\Feature\Identity;

use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\Erasure\UserReferenceCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.4: every foreign key to `users` is classified, a new one fails until
 * it is, and a User can never be hard-deleted by the runtime role (F1).
 */
class UserReferenceCatalogTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return list<string> "table.column" of every live foreign key to users */
    private function live(): array
    {
        $refs = array_map(fn ($r) => "{$r->tbl}.{$r->col}", DB::select(
            "SELECT c.conrelid::regclass::text AS tbl, a.attname AS col FROM pg_constraint c
               JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
              WHERE c.contype = 'f' AND c.confrelid = 'public.users'::regclass",
        ));
        sort($refs);

        return $refs;
    }

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> app-relative files whose code matches $pattern */
    private function filesMatching(string $pattern): array
    {
        $files = [];
        exec('find '.escapeshellarg(app_path()).' -name "*.php"', $files);
        sort($files);

        return array_values(array_map(fn ($f) => substr($f, strlen(app_path()) + 1), array_filter($files, fn ($f) => preg_match($pattern, $this->code($f)) === 1)));
    }

    #[Test]
    public function every_live_reference_to_users_is_classified_exactly_once(): void
    {
        $catalog = array_keys(UserReferenceCatalog::treatments());
        sort($catalog);

        $this->assertSame($this->live(), $catalog, 'a new, renamed or dropped foreign key to users must be classified in UserReferenceCatalog');
        $this->assertCount(113, $catalog, 'E21.4: 87; HRX.1: +8 Leave actor references; HRX.1 correction: +1 (leave_year_start_changes); HRX.2: +4 (requests, decisions, closes, reconciliations); HRX.3: +2 (staff attendance recorder, corrector); HRX.5: +1 (HRX input capturer); OPF.4: +3 (Library fine policy publisher, fine assessor, voider); RES.2: +2 (StudentMark writer, mark revision writer); RES.3: +3 (marks locker, correction requester, decider); TCH-E: +2 (elective assignment creator, ender)');
        $this->assertSame([], UserReferenceCatalog::unclassified($this->live()));

        $counts = array_count_values(UserReferenceCatalog::treatments());
        $this->assertSame(6, $counts[UserReferenceCatalog::ACTIVE_PURPOSE_BLOCKER]);
        $this->assertSame(4, $counts[UserReferenceCatalog::DELETE_CHILD]);
        $this->assertSame(103, $counts[UserReferenceCatalog::RETAIN_REFERENCE], 'OPF.4 adds three retained Library fine actor references; RES.2 two StudentMark writer references; RES.3 three lock/correction actors; TCH-E two elective assignment actors');
        // Audit, D6 authority and Finance/Payroll operator references are kept, never nulled.
        foreach (['school_audit_events.actor_user_id', 'platform_audit_events.actor_user_id', 'membership_role_assignments.assigned_by_user_id',
            'platform_role_assignments.granted_by_user_id', 'school_elevations.actor_user_id', 'payroll_runs.posted_by_user_id', 'payments.recorded_by_user_id',
            'financial_periods.closed_by_user_id', 'teaching_assignments.created_by_user_id'] as $ref) {
            $this->assertNotSame(UserReferenceCatalog::DELETE_CHILD, UserReferenceCatalog::treatments()[$ref], $ref);
        }
    }

    #[Test]
    public function the_runtime_role_cannot_delete_a_user_even_one_nothing_references(): void
    {
        $user = $this->createUser();
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $this->assertFalse((bool) DB::selectOne("select has_table_privilege('school_os_app', 'users', 'DELETE') as d")->d);

        try {
            DB::transaction(fn () => DB::statement('DELETE FROM users WHERE id = ?', [$user->id]));
            $this->fail('The runtime role deleted a User.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
        $this->assertTrue(DB::table('users')->where('id', $user->id)->exists());

        $this->assertContains('users', DatabaseRoleVerifier::NO_RUNTIME_DELETE, 'platform:verify-database checks it');
        $this->assertSame('PASS', collect(app(DatabaseRoleVerifier::class)->verify())->firstWhere('code', 'runtime_destructive_privileges_restricted')?->status);
    }

    #[Test]
    public function no_application_path_hard_deletes_a_user_and_minimization_has_one_writer(): void
    {
        $this->assertSame([], $this->filesMatching("/(User::query\\(\\)|table\\('users'\\))[^;]*->(delete|forceDelete)\\(|DELETE\\s+FROM\\s+(public\\.)?users\\b/i"));
        $this->assertSame([], $this->filesMatching('/\$(user|lockedUser|actor|account)->(delete|forceDelete)\(\)/'));
        $this->assertSame([], array_column(DB::select("SELECT proname FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND prosrc ~* 'DELETE\\s+FROM\\s+(public\\.)?users\\M'"), 'proname'), 'no generic User purge function');

        $this->assertSame(['Domain/Identity/Application/Minimization/UserMinimizationService.php'], $this->filesMatching("/'minimized_at'\\s*=>\\s*\\$/"));
        $this->assertSame(['Domain/Identity/Application/Minimization/UserMinimizationService.php', 'Support/Retention/Erasure/Subjects/UserErasureAdapter.php'], $this->filesMatching('/minimizeLocked\(/'));
        $this->assertStringNotContainsStringIgnoringCase('minimiz', (string) file_get_contents(base_path('routes/console.php')), 'User minimization is never scheduled');
    }
}
