<?php

namespace Tests\Feature\Fees;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OPF (ADR 0067 §4, §8): operational modules call trusted FEE seams; FEE
 * never reads them. Pinned on the source, as FeeSetupArchitectureGuardTest
 * pins Payments -> Fees.
 *
 * Two seams, two explicit allow-lists (OPF.4):
 * - the optional-SELECTION seam (FeeSourceSelectionService): Transport,
 *   Hostel and Admissions -- intent only;
 * - the EVENT-CHARGE seam (FeeSourceChargeService): Library fines only.
 * No source module references ChargeService itself, and no module joins
 * either list without its own reviewed OPF slice.
 */
class OperationalFeeSourceArchitectureGuardTest extends TestCase
{
    /** OPF source modules implemented so far (OPF.1-OPF.4), with their table prefix(es). */
    private const SOURCE_MODULES = [
        'Transport' => 'transport',
        'Hostel' => 'hostel',
        'Admissions' => '(?:admission|applicant)',
        'Library' => 'library',
    ];

    /** The optional-selection seam's callers: one reviewed Application service per approved module (ADR 0067 §8). */
    private const APPROVED_SELECTION_SEAM_CALLERS = [
        'Domain/Admissions/Application/AdmissionFeeSelectionService.php',
        'Domain/Hostel/Application/HostelFeeSelectionService.php',
        'Domain/Transport/Application/TransportFeeSelectionService.php',
    ];

    /**
     * The event-charge seam's callers (OPF.4): the Library fine service, which
     * alone assesses and cancels, and the Library fine policy service, which
     * only validates its ledger destination (chargeableFeeHead()).
     */
    private const APPROVED_CHARGE_SEAM_CALLERS = [
        'Domain/Library/Application/LibraryFinePolicyService.php',
        'Domain/Library/Application/LibraryFineService.php',
    ];

    /** The one caller that may assess or cancel through the event-charge seam. */
    private const CHARGE_SEAM_WRITER = 'Domain/Library/Application/LibraryFineService.php';

    /** Each source module's fee-integration tables and their one writer. */
    private const TABLE_WRITERS = [
        'Domain/Transport/Application/TransportFeeSelectionService.php' => ['TransportFeeSelection', 'TransportRouteFeeHead'],
        'Domain/Hostel/Application/HostelFeeSelectionService.php' => ['HostelFeeSelection', 'HostelFeeHead'],
        'Domain/Admissions/Application/AdmissionFeeSelectionService.php' => ['AdmissionFeeSelection', 'AdmissionFeeHead'],
        'Domain/Library/Application/LibraryFineService.php' => ['LibraryFine', 'LibraryFineVoid'],
        'Domain/Library/Application/LibraryFinePolicyService.php' => ['LibraryFinePolicy'],
    ];

    /** @return list<string> */
    private function files(string $relativeDirectory): array
    {
        $dir = app_path($relativeDirectory);
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> app-relative files outside the Fees seams that match $pattern */
    private function callersOf(string $pattern): array
    {
        $callers = [];
        foreach ($this->files('') as $file) {
            if (str_contains($file, '/Domain/Fees/Application/Sources/')) {
                continue;
            }
            if (preg_match($pattern, (string) file_get_contents($file))) {
                $callers[] = substr($file, strlen(app_path()) + 1);
            }
        }
        sort($callers);

        return $callers;
    }

    #[Test]
    public function fees_finance_and_payments_never_depend_on_or_read_a_source_module(): void
    {
        foreach (['Domain/Fees', 'Domain/Finance', 'Domain/Payments'] as $domain) {
            foreach ($this->files($domain) as $file) {
                $code = (string) file_get_contents($file);
                foreach (self::SOURCE_MODULES as $module => $prefix) {
                    $this->assertStringNotContainsString("App\\Domain\\{$module}\\", $code, "{$file} must not depend on {$module} (ADR 0067 §4)");
                    $this->assertDoesNotMatchRegularExpression("/'{$prefix}_[a-z_]+'/", $code, "{$file} must not read a {$module} table (ADR 0067 §4)");
                }
            }
        }
    }

    #[Test]
    public function only_the_approved_services_call_the_selection_seam(): void
    {
        $this->assertSame(self::APPROVED_SELECTION_SEAM_CALLERS, $this->callersOf('/\bFeeSourceSelectionService\b/'), 'the selection seam has no route and only the approved callers (ADR 0067 §8)');
    }

    #[Test]
    public function only_the_library_fine_services_call_the_event_charge_seam(): void
    {
        $this->assertSame(self::APPROVED_CHARGE_SEAM_CALLERS, $this->callersOf('/\bFeeSourceChargeService\b/'), 'the event-charge seam has no route and only the approved callers (ADR 0067 §17)');
        $this->assertSame([self::CHARGE_SEAM_WRITER], $this->callersOf('/\bFeeChargeSource::/'), 'only the Library fine service names an event-charge source, so only it can assess or cancel one');
    }

    #[Test]
    public function each_module_uses_only_its_own_seam_and_no_other_module_joins_without_an_opf_slice(): void
    {
        foreach (self::APPROVED_SELECTION_SEAM_CALLERS as $caller) {
            $this->assertDoesNotMatchRegularExpression('/\bFeeSourceChargeService\b|\bFeeChargeSource\b/', (string) file_get_contents(app_path($caller)), "{$caller}: selection sources never assess charges");
        }
        foreach ($this->files('Domain/Library') as $file) {
            $this->assertDoesNotMatchRegularExpression('/\bFeeSourceSelectionService\b|\bFeeSelectionSource\b/', (string) file_get_contents($file), "{$file}: Library fines are event charges, never selections (D6)");
        }
        foreach (['Canteen', 'Inventory', 'Visitor'] as $other) {
            foreach ($this->files("Domain/{$other}") as $file) {
                $this->assertDoesNotMatchRegularExpression('/\bFeeSource(Selection|Charge)Service\b|\bFee(Selection|Charge)Source\b/', (string) file_get_contents($file), "{$file} is not an approved OPF source");
            }
        }
    }

    #[Test]
    public function source_modules_reach_fees_only_through_their_application_seam(): void
    {
        foreach (array_keys(self::SOURCE_MODULES) as $module) {
            foreach ($this->files("Domain/{$module}") as $file) {
                $code = (string) file_get_contents($file);
                $this->assertStringNotContainsString('App\\Domain\\Fees\\Infrastructure', $code, "{$file} must not touch FEE models (ADR 0067 §4)");
                $this->assertStringNotContainsString('App\\Domain\\Finance\\', $code, $file);
                $this->assertStringNotContainsString('App\\Domain\\Payments\\', $code, $file);
                $this->assertDoesNotMatchRegularExpression("/'(charges|fee_optional_selections|fee_assessments)'/", $code, "{$file} must not read FEE tables");
                $this->assertDoesNotMatchRegularExpression('/\bChargeService\b|\bAssessChargeData\b|\bChargeAdministrationService\b/', $code, "{$file} must never reach FEE's charge service directly: only through the event-charge seam (D9)");
            }
        }
    }

    #[Test]
    public function only_each_source_fee_service_writes_its_fee_tables(): void
    {
        foreach ($this->files('') as $file) {
            $relative = substr($file, strlen(app_path()) + 1);
            $code = (string) file_get_contents($file);
            foreach (self::TABLE_WRITERS as $writer => $models) {
                if ($relative === $writer) {
                    continue;
                }
                foreach ($models as $model) {
                    $this->assertDoesNotMatchRegularExpression("/\\b{$model}::query\\(\\)->create/", $code, "{$relative} must not write {$model}");
                }
            }
        }
    }
}
