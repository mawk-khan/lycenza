<?php

namespace Tests\Feature\Postgres;

use App\Domain\Identity\Application\AccountLinkService;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * ORG.8A-R1 (ADR 0076 §59): as the real runtime role, a raw terminal decision
 * of a fee concession whose recorded decider is a Guardian of its Student
 * (any account link, active or revoked, to a Guardian currently related to
 * the Student) is refused by `fee_concessions_beneficiary_guardian_guard`,
 * for approve and reject; an unrelated decider's write stands; the
 * requester stays refused by `fee_concessions_sod_check`; another School's
 * link never counts. Pins the installed objects, and the documented limit:
 * relationship rows record no actor, so Rule B (no self-administration) is
 * the service's (§59.6), never claimed as a database rule.
 */
class FeeConcessionBeneficiaryGuardianDbTest extends TestCase
{
    use CreatesFeeConcessionFixtures;

    private function decide(School $school, string $concessionId, string $status, string $deciderId): int|string
    {
        $db = DB::connection('pgsql');
        $db->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);
        try {
            return $db->transaction(function () use ($db, $concessionId, $status, $deciderId) {
                $rows = $db->update('update fee_concessions set status = ?, decided_by_user_id = ?, decided_at = now() where id = ?', [$status, $deciderId, $concessionId]);
                throw new \RuntimeException((string) $rows);
            });
        } catch (QueryException $e) {
            return $e->getMessage();
        } catch (\RuntimeException $e) {
            return (int) $e->getMessage();
        }
    }

    private function linkAccount(School $school, User $user, $guardian, User $by): void
    {
        $membership = $this->inSchool($school, fn () => SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $school->id)->firstOrFail());
        $this->inSchool($school, fn () => app(AccountLinkService::class)->linkGuardian($school, $guardian, $membership, $by));
    }

    #[Test]
    public function the_database_refuses_a_guardian_decider_for_approve_and_reject_and_keeps_every_other_rule(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w, '100.00');
        $guardianDecider = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.approve']);
        $guardian = $this->createGuardian($w['school']);
        $this->createStudentGuardianRelationship($w['student'], $guardian);
        $this->linkAccount($w['school'], $guardianDecider, $guardian, $w['recorder']);
        $this->inSchool($w['school'], fn () => app(AccountLinkService::class)->unlinkGuardian($w['school'], $guardian, $w['recorder']));
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u, 'proven on the real runtime role');

        foreach (['approved', 'rejected'] as $status) {
            $this->assertStringContainsString('fee_concessions_beneficiary_guardian_guard', (string) $this->decide($w['school'], $concession->id, $status, $guardianDecider->id), "{$status}: a revoked link still counts");
        }
        $this->assertStringContainsString('fee_concessions_sod_check', (string) $this->decide($w['school'], $concession->id, 'approved', $w['maker']->id), 'requester != decider unchanged');
        $this->assertSame(1, $this->decide($w['school'], $concession->id, 'rejected', $w['checker']->id), 'an unrelated decider stands');

        [, $otherSchool] = $this->createSchoolAdmin('school_admin');
        $this->assignSchoolRole($this->createMembership($w['checker'], $otherSchool), 'school_admin');
        $elsewhere = $this->createGuardian($otherSchool);
        $this->createStudentGuardianRelationship($this->createStudent($otherSchool), $elsewhere);
        $this->linkAccount($otherSchool, $w['checker'], $elsewhere, $w['checker']);
        $this->assertSame(1, $this->decide($w['school'], $concession->id, 'approved', $w['checker']->id), 'another School\'s Guardian link never counts');
    }

    #[Test]
    public function the_installed_objects_and_the_documented_rule_b_boundary(): void
    {
        foreach (['fees_concession_decider_is_beneficiary_guardian', 'fees_refuse_beneficiary_guardian_decision'] as $function) {
            $fn = DB::selectOne("select p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, has_function_privilege('public', p.oid, 'EXECUTE') as public_exec, pg_get_userbyid(p.proowner) as owner from pg_proc p where p.proname = ?", [$function]);
            $this->assertFalse($fn->prosecdef, "{$function} runs as the caller");
            $this->assertStringContainsString('search_path=pg_catalog, public, pg_temp', $fn->config);
            $this->assertFalse($fn->public_exec);
            $this->assertNotSame('school_os_app', $fn->owner);
        }
        $trigger = DB::selectOne("select tgenabled, pg_get_triggerdef(oid) as def from pg_trigger where tgname = 'fee_concessions_beneficiary_guardian_guard'");
        $this->assertSame('O', $trigger->tgenabled);
        $this->assertStringContainsString('BEFORE UPDATE OF status, decided_by_user_id ON public.fee_concessions', $trigger->def);
        $this->assertTrue((bool) DB::selectOne("select 1 as ok from pg_constraint where conname = 'fee_concessions_sod_check'")->ok, 'the maker/checker backstop is kept');

        foreach (['fee_concessions', 'student_guardian_relationships', 'student_guardian_account_links'] as $table) {
            $rls = DB::connection('pgsql_admin')->selectOne('select relrowsecurity, relforcerowsecurity from pg_class where oid = ?::regclass', ["public.{$table}"]);
            $this->assertTrue($rls->relrowsecurity && $rls->relforcerowsecurity, "{$table}: RLS forced");
        }

        // §59.6: a relationship row records no actor and the database has no trusted actor context, so Rule B is the
        // service's. If an actor column is ever added, revisit a database rule.
        $actorColumns = DB::select("select column_name from information_schema.columns where table_name = 'student_guardian_relationships' and column_name ~ '(_by_user_id|actor)'");
        $this->assertSame([], $actorColumns);
    }
}
