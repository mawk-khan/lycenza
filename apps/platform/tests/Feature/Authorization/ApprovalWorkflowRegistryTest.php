<?php

namespace Tests\Feature\Authorization;

use App\Support\Approval\ApprovalWorkflowRegistry;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.8A (ADR 0076 §58): proves the code-owned approval-workflow inventory
 * against the database and the code. A new decider/requester column, a new
 * request/approve capability, a vanished workflow, a missing database
 * backstop, a 'shared' workflow deciding without the shared policy, or a
 * fresh-MFA proof minted outside FreshMfaRequirement all fail here. The
 * registry stays an architecture guard: nothing routes or decides by it.
 */
class ApprovalWorkflowRegistryTest extends TestCase
{
    /** Decision-shaped column names (structural evidence of an approval workflow). */
    private const DECISION_COLUMNS = '(decided|approved|requested|reviewed|rejected|prepared|previewed|submitted|verified|authori[sz]ed|published|signed_off)_by_(user|employee)_id$|^(decider|approver|requester|reviewer)_(user|employee)_id$';

    /** @return list<string> every declared table.column (workflow participants/deciders and NOT_WORKFLOWS) */
    private function declaredColumns(): array
    {
        $columns = array_keys(ApprovalWorkflowRegistry::NOT_WORKFLOWS);
        foreach (ApprovalWorkflowRegistry::WORKFLOWS as $workflow) {
            array_push($columns, ...$workflow['participants']);
            if ($workflow['decider'] !== null) {
                $columns[] = $workflow['decider'];
            }
            array_push($columns, ...($workflow['otherDecisionColumns'] ?? []));
        }

        return $columns;
    }

    #[Test]
    public function every_decision_shaped_column_in_the_schema_is_a_declared_workflow_column(): void
    {
        $found = collect(DB::select("select table_name || '.' || column_name as col from information_schema.columns
            where table_schema = 'public' and column_name ~ ?", [self::DECISION_COLUMNS]))->pluck('col');

        foreach ($found as $column) {
            $this->assertContains($column, $this->declaredColumns(), "{$column} looks like an approval decision: register its workflow in ApprovalWorkflowRegistry, or declare it in NOT_WORKFLOWS with the reason");
        }
    }

    #[Test]
    public function every_registered_table_column_and_backstop_exists(): void
    {
        foreach (ApprovalWorkflowRegistry::WORKFLOWS as $key => $workflow) {
            foreach ($workflow['tables'] as $table) {
                $this->assertNotNull(DB::selectOne('select to_regclass(?) as t', ["public.{$table}"])->t, "{$key}: table {$table}");
            }
            foreach ([...$workflow['participants'], ...array_filter([$workflow['decider']]), ...($workflow['otherDecisionColumns'] ?? [])] as $column) {
                [$table, $name] = explode('.', $column);
                $this->assertNotNull(DB::selectOne('select 1 as ok from information_schema.columns where table_name = ? and column_name = ?', [$table, $name]), "{$key}: column {$column}");
            }
            if ($workflow['dbBackstop'] !== null) {
                $this->assertNotNull(DB::selectOne('select 1 as ok from pg_constraint where conname = ?', [$workflow['dbBackstop']]), "{$key}: backstop {$workflow['dbBackstop']}");
            }
            if ($workflow['service'] === null) {
                $this->assertSame('student_mark_corrections', $key, 'only isolated Marks code is registered without naming its class (ADR 0068 §19.2)');

                continue;
            }
            $this->assertTrue(class_exists($workflow['service']), "{$key}: {$workflow['service']}");
            foreach ($workflow['decisions'] as $method) {
                $this->assertTrue(method_exists($workflow['service'], $method), "{$key}: {$workflow['service']}::{$method}");
            }
            $this->assertContains($workflow['makerChecker'], ['shared', 'domain']);
            $this->assertContains($workflow['execution'], ['immediate', 'separate']);
            $this->assertContains($workflow['drift'], ['immutable_terms', 'fingerprint', 'frozen_at_approval']);
            $this->assertContains($workflow['compatibility'], ['pilot', 'as_is', 'adapter', 'special_rules', 'not_applicable']);
        }
    }

    #[Test]
    public function every_request_or_approve_capability_is_classified_and_none_is_campus_adopted(): void
    {
        $registered = [];
        foreach (ApprovalWorkflowRegistry::WORKFLOWS as $workflow) {
            array_push($registered, ...$workflow['request'], ...$workflow['decide']);
        }
        $seeded = DB::table('capabilities')->pluck('key')->all();

        foreach ($seeded as $key) {
            if (preg_match('/\.(approve|request|decide|review|authori[sz]e|verify|publish|sign_off)(\.|$)/', $key) === 1) {
                $this->assertContains($key, $registered, "{$key} is an approval capability: classify its workflow in ApprovalWorkflowRegistry");
            }
        }
        foreach (array_unique($registered) as $key) {
            $this->assertContains($key, $seeded, "{$key} is a seeded capability");
            $this->assertFalse(CampusScopeCatalogue::isAdopted($key), "{$key}: approval authority is not Campus-adopted in v1");
        }
    }

    #[Test]
    public function a_shared_workflow_decides_through_the_shared_foundation_and_only_it_does(): void
    {
        $shared = array_filter(ApprovalWorkflowRegistry::WORKFLOWS, fn (array $w) => $w['makerChecker'] === 'shared');
        $this->assertSame(['fee_concessions'], array_keys($shared), 'ORG.8A pilots exactly one workflow');

        foreach ($shared as $key => $workflow) {
            $class = new ReflectionClass($workflow['service']);
            $source = (string) file_get_contents((string) $class->getFileName());
            $this->assertStringContainsString('makerChecker->permits(', $source, "{$key} decides through MakerCheckerPolicy");
            foreach ($workflow['decisions'] as $decision) {
                $method = $class->getMethod($decision);
                $body = implode('', array_slice(file((string) $class->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                $this->assertStringContainsString('$this->assertMayDecide(', $body, "{$key}::{$decision} decides through the shared authority + maker/checker");
            }
            $this->assertStringContainsString('authority->requireCurrent(', $source, "{$key} re-checks current authority");
            $this->assertStringContainsString('ApprovalProvenance::metadata(', $source, "{$key} records approval provenance");
            $this->assertMatchesRegularExpression('/public static function participants\(/', $source, "{$key} exposes its participant set");
            $this->assertDoesNotMatchRegularExpression('/requested_by_user_id\s*===\s*\$actor->id\)\s*\{\s*throw new SelfApprovalNotAllowedException/', $source, "{$key} has no private requester-only maker/checker");
        }

        foreach (['MakerCheckerPolicy', 'ApprovalDecisionAuthority', 'ApprovalProvenance', 'ApprovalParticipants'] as $part) {
            $users = [];
            foreach (Finder::create()->files()->name('*.php')->in(app_path())->notPath('Support/Approval') as $file) {
                // Code use (import, static call or type), never a docblock mention.
                if (preg_match('/use App\\\\Support\\\\Approval\\\\'.$part.';|\b'.$part.'::|\b'.$part.'\s+\$/', $file->getContents()) === 1) {
                    $users[] = $file->getRelativePathname();
                }
            }
            $this->assertSame(['Domain/Fees/Application/FeeConcessionService.php'], $users, "only registered shared workflows use {$part}");
        }
    }

    #[Test]
    public function only_fee_concessions_declare_the_guardian_beneficiary_conflict_and_it_is_enforced(): void
    {
        $declared = array_filter(ApprovalWorkflowRegistry::WORKFLOWS, fn (array $w) => isset($w['relationshipConflict']));
        $this->assertSame(['fee_concessions' => 'guardian_of_student_beneficiary'], array_map(fn (array $w) => $w['relationshipConflict'], $declared));

        $source = (string) file_get_contents((string) (new ReflectionClass(ApprovalWorkflowRegistry::WORKFLOWS['fee_concessions']['service']))->getFileName());
        $this->assertStringContainsString('beneficiary->isGuardianOf(', $source, 'the decision checks the beneficiary conflict');
        $this->assertSame('O', DB::selectOne("select tgenabled from pg_trigger where tgname = 'fee_concessions_beneficiary_guardian_guard'")->tgenabled, 'and the database backs it');
        $policy = (string) file_get_contents(app_path('Support/Approval/MakerCheckerPolicy.php'));
        $this->assertStringNotContainsStringIgnoringCase('guardian', $policy, 'maker/checker stays generic');
    }

    #[Test]
    public function a_fresh_mfa_proof_is_minted_only_by_fresh_mfa_requirement(): void
    {
        $minters = [];
        foreach (Finder::create()->files()->name('*.php')->in(app_path()) as $file) {
            // Any construction: plain, fully qualified, aliased or reflective.
            if (preg_match('/new\s+[\w\\\\]*FreshMfaProof\s*\(|FreshMfaProof::class\)\s*->\s*newInstance|FreshMfaProof\s+as\s+\w/', $file->getContents()) === 1) {
                $minters[] = $file->getRelativePathname();
            }
        }
        $this->assertSame(['Support/Auth/Mfa/FreshMfaRequirement.php'], $minters);
    }

    #[Test]
    public function the_toolkit_owns_no_table_state_or_queue(): void
    {
        foreach (Finder::create()->files()->name('*.php')->in(app_path('Support/Approval')) as $file) {
            $source = $file->getContents();
            $this->assertDoesNotMatchRegularExpression('/DB::|::query\(\)|extends Model|Schema::/', $source, "{$file->getFilename()}: the shared toolkit never reads or writes business data");
        }
        $this->assertNull(DB::selectOne("select to_regclass('public.approval_requests') as t")->t, 'no generic approval table');
    }
}
