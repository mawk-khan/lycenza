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
        $this->assertCount(185, $catalog, 'HR.R1-C7: +4 (account link user and actors); HR.R1-C6: +2 (reconciliation reviewer and reverser); HR.R1-C4: +1 (posting attestor); OD-HR-2: +1 (compensation change author); HR.13: +9 (category, template and version authors, template retirer, task assigner, completer and canceller, timeline actor, generation starter); HR.12: +11 (seven separation case actors, the timeline actor, clearance requirement author and retirer, clearance item clearer); HR.10: +1 (accrual run starter); HR.9: +2 (eligibility rule author, eligibility run executor); HR.2A: +8 (six profile-record archivers, reference code recorder and retirer); HR.3A: +3 (reporting line starter, ender, voider); HR.1A: +2 (employment terms and placement change recorders); E21.4: 87; HRX.1: +8 Leave actor references; HRX.1 correction: +1 (leave_year_start_changes); HRX.2: +4 (requests, decisions, closes, reconciliations); HRX.3: +2 (staff attendance recorder, corrector); HRX.5: +1 (HRX input capturer); OPF.4: +3 (Library fine policy publisher, fine assessor, voider); RES.2: +2 (StudentMark writer, mark revision writer); RES.3: +3 (marks locker, correction requester, decider); TCH-E: +2 (elective assignment creator, ender); UAT-C3: +1 (payment correction actor); UAT-C6: +1 (fee-run overlap decider); ORG.8B: +1 (payroll run calculator); ORG.8C: +3 (payment correction requester, decider, withdrawer); ORG.8E: +2 (attendance corrector, lifecycle actor); ORG.8G: +1 (Charge canceller); ORG.9D-3: +3 (calendar Event preparer, publisher, canceller); ORG.12C: +1 (Campus service recorder); SIS.EXP-3: +2 (Student document uploader, archiver); SIS.EXP-4: +2 (certificate issuer, voider); SIS.EXP-5: +1 (import committer); SIS 11.5: +6 (Student document verifier; pre-admission document uploader, archiver, verifier; requirement author, retirer); SIS 11.7: +4 (House membership starter, ender; responsibility assigner, ender)');
        $this->assertSame([], UserReferenceCatalog::unclassified($this->live()));

        $counts = array_count_values(UserReferenceCatalog::treatments());
        $this->assertSame(6, $counts[UserReferenceCatalog::ACTIVE_PURPOSE_BLOCKER]);
        $this->assertSame(4, $counts[UserReferenceCatalog::DELETE_CHILD]);
        $this->assertSame(175, $counts[UserReferenceCatalog::RETAIN_REFERENCE], 'HR.R1-C7 the account link user and actors; HR.R1-C6 the reconciliation reviewer and reverser; HR.R1-C4 the posting attestor; OD-HR-2 the compensation change author; HR.13 nine staff task actors; HR.12 eleven separation case, timeline and clearance actors; HR.10 the accrual run starter; HR.9 the eligibility rule author and run executor; HR.2A six profile-record archivers and the reference code recorder and retirer; HR.3A the reporting line starter, ender and voider; HR.1A the employment-terms and placement-change recorders; OPF.4 adds three retained Library fine actor references; RES.2 two StudentMark writer references; RES.3 three lock/correction actors; TCH-E two elective assignment actors; UAT-C3 the payment correction actor; UAT-C6 the fee-run overlap decider; ORG.8B the payroll run calculator; ORG.8C the payment correction requester, decider and withdrawer; ORG.8E the attendance corrector and lifecycle actor; ORG.8G the Charge canceller; ORG.9D-3 the calendar Event preparer, publisher and canceller; ORG.12C the Campus service recorder; SIS.EXP-3 the Student document uploader and archiver; SIS.EXP-4 the certificate issuer and voider; SIS.EXP-5 the import committer; SIS 11.5 six retained document verification, pre-admission document and requirement-rule actors; SIS 11.7 four House membership and responsibility actors');
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
