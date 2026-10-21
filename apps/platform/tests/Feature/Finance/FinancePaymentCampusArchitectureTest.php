<?php

namespace Tests\Feature\Finance;

use App\Domain\Payments\Application\Access\FinancePaymentAccess;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.7G-2 (ADR 0076 §51): the Campus payment-entry contract is pinned on
 * code -- entry resolves its scope first and writes only through the locked
 * same-Campus guard, every replay re-checks the current scope, only payment
 * ENTRY is Campus-aware, and corrections stay whole-School.
 */
class FinancePaymentCampusArchitectureTest extends TestCase
{
    private function source(string $class): string
    {
        return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
    }

    #[Test]
    public function payment_entry_resolves_scope_first_and_writes_and_replays_only_through_the_guard(): void
    {
        $service = $this->source(ManualPaymentRecordingService::class);
        $record = substr($service, strpos($service, 'public function record('), 4000);
        $this->assertLessThan(strpos($record, 'DB::transaction('), strpos($record, 'authorizeCampusScopeFor($actor, self::CAPABILITY, $school)'), 'the scope is resolved before any query');
        $this->assertSame(1, substr_count($service, '->lockAllocationsForCampusWrite('));
        $this->assertSame(2, substr_count($service, '->assertReplayable('), 'both replay paths (the advisory-lock path and the unique-index path) re-check the current scope');
        $this->assertLessThan(strpos($record, 'return $this->replay('), strpos($record, '->assertReplayable('), 'never a stored replay before the scope check');

        $callers = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            if (str_contains($file->getContents(), '->lockAllocationsForCampusWrite(')) {
                $callers[] = $file->getRelativePathname();
            }
        }
        $this->assertSame(['Domain/Payments/Application/ManualPaymentRecordingService.php'], $callers);

        $guard = $this->source(FinancePaymentAccess::class);
        foreach (['->lockChargeCampusesForAllocation(', 'sort($chargeIds', 'in_array(null, $campusIds, true)', 'count($distinct) !== 1', '->covers($distinct[0])', '$this->campuses->find('] as $check) {
            $this->assertStringContainsString($check, $guard);
        }
        foreach (['StudentOperational', 'StudentEnrollment', 'currentPlacement', 'StudentPurposeLookup'] as $current) {
            $this->assertStringNotContainsString($current, $guard, 'historical debt decides; the Student\'s current Campus never does');
        }
    }

    #[Test]
    public function only_payment_entry_is_campus_aware_and_corrections_stay_whole_school(): void
    {
        $finance = array_values(array_filter(array_keys(CampusScopeCatalogue::ADOPTED), fn (string $k) => str_starts_with($k, 'finance.')));
        sort($finance);
        $this->assertSame(['finance.charges.view', 'finance.payments.record', 'finance.payments.view'], $finance);

        $corrections = $this->source(PaymentCorrectionService::class);
        foreach (['authorizeCampusScope', 'campusScope(', 'FinancePaymentAccess'] as $campus) {
            $this->assertStringNotContainsString($campus, $corrections, 'reallocation, reversal and refund check their capabilities with the whole-School Gate only');
        }
        $this->assertContains('finance.charges.manage', PaymentCorrectionService::REALLOCATE_CAPABILITIES, 'reallocation also needs protected whole-School authority');
        $this->assertTrue(CampusScopeCatalogue::isCampusIncompatible('finance.charges.manage'));
    }
}
