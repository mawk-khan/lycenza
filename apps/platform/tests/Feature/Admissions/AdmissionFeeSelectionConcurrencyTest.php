<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\AdmissionFeeSelectionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\AdmissionFeeSelection;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Students\Infrastructure\Student;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * OPF.3 (ADR 0067 §10, §29): Admission-fee intent is recorded exactly once
 * under real concurrency -- two genuinely separate OS processes against real
 * PostgreSQL, the holder's writes held uncommitted until the contender is
 * observed blocked on them (ForcesConcurrentOverlap). Committed fixtures.
 * The application row lock (taken by the conversion, and again by the
 * fee-link) serializes the lifecycle races; FEE's one-active key resolves a
 * race at the seam itself.
 */
class AdmissionFeeSelectionConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesFeeAssessmentFixtures, ForcesConcurrentOverlap;

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/admission-fee-op.php', ...$args];
    }

    /** @return array<string, mixed> a School with an optional one-time Admission line and its fee head mapped, and an accepted application */
    private function world(): array
    {
        $w = $this->feeWorld();
        $w['section'] = $this->createSection($w['year'], $w['campus'], $w['grade']);
        $w['admissionHead'] = $this->makeFeeHead($w, ['code' => 'ADMISSION', 'name' => 'Admission fee']);
        $service = app(FeeStructureService::class);
        $structure = $this->makeDraftStructure($w, ['code' => 'G-DEF']);
        $line = $service->addLine($w['school'], $structure->id, ['fee_head_id' => $w['admissionHead']->id, 'amount' => '2500.00', 'is_optional' => true], $w['actor']);
        $service->replaceInstallments($w['school'], $structure->id, $line->id, [
            ['label' => 'Admission', 'billing_period_key' => 'ADM', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2027-05-31', 'due_date' => '2026-06-10', 'amount' => '2500.00'],
        ], $w['actor']);
        $service->activate($w['school'], $structure->id, $w['actor']);
        app(AdmissionFeeSelectionService::class)->setFeeHead($w['school'], $w['admissionHead']->id, null);
        $w['application'] = $this->createAdmissionApplication($this->createApplicant($w['school']), $w['year'], $w['campus'], $w['grade'], ['status' => 'accepted']);

        return $w;
    }

    /** @param  array<string, mixed>  $w */
    private function rows(array $w, string $model, array $where = []): int
    {
        return $this->inSchool($w['school'], fn () => $model::query()->where($where)->count());
    }

    #[Test]
    public function two_concurrent_conversions_leave_one_student_one_provenance_row_and_one_selection(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('convert', $w['school']->id, $w['application']->id, $w['section']->id, 'S-RACE-A', '01'),
            $this->op('convert', $w['school']->id, $w['application']->id, $w['section']->id, 'S-RACE-B', '02'),
        );

        $this->assertStringStartsWith('converted:', $holder, $holder);
        $this->assertSame('error:App\\Domain\\Admissions\\Application\\Exceptions\\AdmissionApplicationAlreadyConvertedException', $contender,
            'the contender waited on the application row lock, then saw it converted -- no second Student, no uniqueness error');
        $studentId = substr($holder, strlen('converted:'));
        $this->assertSame(1, $this->rows($w, Student::class));
        $this->assertSame(1, $this->rows($w, AdmissionFeeSelection::class, ['admission_application_id' => $w['application']->id, 'student_id' => $studentId]));
        $this->assertSame(1, $this->rows($w, FeeOptionalSelection::class, ['student_id' => $studentId, 'status' => 'active']));
        $this->assertSame(0, (int) DB::connection('pgsql_admin')->table('charges')->where('school_id', $w['school']->id)->count(), 'intent only: no charge');
    }

    #[Test]
    public function two_concurrent_fee_links_for_one_converted_application_record_one_provenance_row_and_one_selection(): void
    {
        $w = $this->world();
        // Converted while unconfigured, so its fee-link is still unrecorded; then configured.
        app(AdmissionFeeSelectionService::class)->setFeeHead($w['school'], null, null);
        $converted = app(AdmissionConversionService::class)->convert($w['application'], 'S-LINK', $w['section'], '01', '2026-06-01')->application;
        app(AdmissionFeeSelectionService::class)->setFeeHead($w['school'], $w['admissionHead']->id, null);
        $args = ['link', $w['school']->id, $converted->id];

        [$holder, $contender] = $this->raceWithHeldHolder($this->op(...$args), $this->op(...$args));

        $this->assertSame('linked', $holder);
        $this->assertSame('already_linked', $contender, 'the contender waited on the application row lock, then found the committed link');
        $this->assertSame(1, $this->rows($w, AdmissionFeeSelection::class, ['admission_application_id' => $converted->id]));
        $this->assertSame(1, $this->rows($w, FeeOptionalSelection::class, ['student_id' => $converted->converted_student_id, 'status' => 'active']));
    }

    #[Test]
    public function two_concurrent_admissions_source_selections_resolve_to_one_selection_and_never_surface_the_unique_race(): void
    {
        $w = $this->world();
        app(AdmissionFeeSelectionService::class)->setFeeHead($w['school'], null, null);
        $converted = app(AdmissionConversionService::class)->convert($w['application'], 'S-SEAM', $w['section'], '01', '2026-06-01')->application;
        $args = ['select', $w['school']->id, $converted->converted_student_id, $w['year']->id, $w['admissionHead']->id, $converted->id];

        [$holder, $contender] = $this->raceWithHeldHolder($this->op(...$args), $this->op(...$args));

        [$holderOutcome, $selectionId] = explode(':', $holder);
        $this->assertSame('created', $holderOutcome, $holder);
        $this->assertSame("reused:{$selectionId}", $contender, 'the contender waited on the unique index, then reused the winner -- no error surfaced');
        $this->assertSame(1, $this->rows($w, FeeOptionalSelection::class, ['student_id' => $converted->converted_student_id, 'status' => 'active']));
        $this->assertSame(1, $this->rows($w, AdmissionApplication::class, ['id' => $converted->id, 'status' => 'converted']));
    }
}
