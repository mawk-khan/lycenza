<?php

namespace Tests\Feature\Transport;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Application\Exceptions\TransportRiderImportNotCommittableException;
use App\Domain\Transport\Application\TransportFeeSelectionService;
use App\Domain\Transport\Application\TransportRiderImport;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportFeeSelection;
use App\Support\Authorization\Campus\CampusScope;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * ORG.9C-4 (ADR 0076 §72): the import's only financial effect is the one the
 * single assignment already has -- Transport fee INTENT for the active year,
 * recorded inside assign() through Fees' source seam (OPF.1). Exactly once per
 * new rider, never for a no-op or a refused batch, and never a Charge.
 */
class TransportRiderImportFeeTest extends TestCase
{
    use CreatesFeeAssessmentFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->feeWorld();
        $w['transport'] = $this->createUserWithCapabilities($w['school'], ['transport.assignments.view', 'transport.assignments.manage', 'transport.routes.view', 'transport.routes.manage']);
        $w['section'] = $this->createSection($w['year'], $w['campus'], $w['grade']);
        $w['head'] = $this->makeFeeHead($w, ['code' => 'TRANSPORT', 'name' => 'Transport']);
        $this->activeTwoTermStructure($w, ['code' => 'G-DEF'], $w['head'], true, $this->makeFeeHead($w));
        $w['route'] = $this->createTransportRoute($w['school'], ['code' => 'BUS-1', 'status' => 'active']);
        app(TransportFeeSelectionService::class)->setRouteFeeHead($w['route'], $w['head']->id, $w['transport']);

        return $w;
    }

    /** @param  array<string, mixed>  $w */
    private function rider(array $w, string $number): Student
    {
        return $this->inSchool($w['school'], fn () => Student::query()->findOrFail($this->enroll($w, '2026-06-01', [], null, $this->createStudent($w['school'], ['student_number' => $number, 'status' => 'active']))->student_id));
    }

    /** @param  array<string, mixed>  $w */
    private function import(array $w, array $numbers): array
    {
        $import = app(TransportRiderImport::class);
        $rows = $import->parse("student_number,route_code\n".implode("\n", array_map(fn ($n) => "{$n},BUS-1", $numbers))."\n");
        $preview = $import->preview($w['school'], CampusScope::wholeSchool(), $w['transport'], $rows);

        return $import->commit($w['school'], CampusScope::wholeSchool(), $w['transport'], $rows, $preview['issuedAt'], $preview['signature']);
    }

    /** @param  array<string, mixed>  $w */
    private function counts(array $w): array
    {
        return $this->inSchool($w['school'], fn () => [
            FeeOptionalSelection::query()->count(), TransportFeeSelection::query()->count(), Charge::query()->count(),
        ]);
    }

    #[Test]
    public function fee_intent_is_recorded_once_per_new_rider_never_on_a_replay_or_refusal_and_never_as_a_charge(): void
    {
        $w = $this->world();
        $this->rider($w, 'F-1');
        $this->rider($w, 'F-2');
        $before = $this->counts($w);

        $this->assertSame(['created' => 2, 'alreadyAssigned' => 0, 'rows' => 2], $this->import($w, ['F-1', 'F-2']));
        $this->assertSame([$before[0] + 2, $before[1] + 2, $before[2]], $this->counts($w), 'one selection and one provenance row each; no Charge');

        $this->assertSame(['created' => 0, 'alreadyAssigned' => 2, 'rows' => 2], $this->import($w, ['F-1', 'F-2']));
        $this->assertSame([$before[0] + 2, $before[1] + 2, $before[2]], $this->counts($w), 'a replay records nothing');

        // A batch refused at commit leaves no intent behind (the whole transaction rolls back).
        $f3 = $this->rider($w, 'F-3');
        $f4 = $this->rider($w, 'F-4');
        $import = app(TransportRiderImport::class);
        $rows = $import->parse("student_number,route_code\nF-3,BUS-1\nF-4,BUS-1\n");
        $preview = $import->preview($w['school'], CampusScope::wholeSchool(), $w['transport'], $rows);
        $other = $this->createTransportRoute($w['school'], ['code' => 'BUS-2', 'status' => 'active']);
        $this->inSchool($w['school'], fn () => app(TransportStudentAssignmentService::class)->assign($f4, $other, null, null));
        $this->assertThrows(fn () => $import->commit($w['school'], CampusScope::wholeSchool(), $w['transport'], $rows, $preview['issuedAt'], $preview['signature']), TransportRiderImportNotCommittableException::class);
        $this->assertSame([$before[0] + 2, $before[1] + 2, $before[2]], $this->counts($w), 'F-4 on an unmapped route records nothing; F-3 was rolled back with the batch');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => FeeOptionalSelection::query()->where('student_id', $f3->id)->count()));
    }
}
