<?php

namespace Tests\Feature\Fees;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OPF (ADR 0067 §4, §8): operational modules call trusted FEE seams; FEE
 * never reads them. Pinned on the source, as FeeSetupArchitectureGuardTest
 * pins Payments -> Fees.
 */
class OperationalFeeSourceArchitectureGuardTest extends TestCase
{
    /** OPF source modules implemented so far (OPF.1 Transport, OPF.2 Hostel, OPF.3 Admissions), with their table prefix(es). */
    private const SOURCE_MODULES = ['Transport' => 'transport', 'Hostel' => 'hostel', 'Admissions' => '(?:admission|applicant)'];

    /**
     * The explicit allow-list of the trusted selection seam's callers: one
     * reviewed Application service per approved source module. Library or
     * any other caller is a failure until its own OPF slice adds it here
     * (ADR 0067 §8).
     */
    private const APPROVED_SEAM_CALLERS = [
        'Domain/Admissions/Application/AdmissionFeeSelectionService.php',
        'Domain/Hostel/Application/HostelFeeSelectionService.php',
        'Domain/Transport/Application/TransportFeeSelectionService.php',
    ];

    /** Each source module's fee-integration tables and their one writer. */
    private const TABLE_WRITERS = [
        'Domain/Transport/Application/TransportFeeSelectionService.php' => ['TransportFeeSelection', 'TransportRouteFeeHead'],
        'Domain/Hostel/Application/HostelFeeSelectionService.php' => ['HostelFeeSelection', 'HostelFeeHead'],
        'Domain/Admissions/Application/AdmissionFeeSelectionService.php' => ['AdmissionFeeSelection', 'AdmissionFeeHead'],
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
    public function only_the_approved_source_module_services_call_the_trusted_seam(): void
    {
        $callers = [];
        foreach ($this->files('') as $file) {
            if (str_contains($file, '/Domain/Fees/Application/Sources/')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file), 'FeeSourceSelectionService')) {
                $callers[] = substr($file, strlen(app_path()) + 1);
            }
        }
        sort($callers);

        $this->assertSame(self::APPROVED_SEAM_CALLERS, $callers, 'the seam has no route and only the approved callers (ADR 0067 §8)');
    }

    #[Test]
    public function the_seam_allow_list_admits_no_module_without_an_opf_slice(): void
    {
        foreach (['Library'] as $excluded) {
            foreach (self::APPROVED_SEAM_CALLERS as $caller) {
                $this->assertStringStartsNotWith("Domain/{$excluded}/", $caller, "{$excluded} is not an approved OPF source yet");
            }
            foreach ($this->files("Domain/{$excluded}") as $file) {
                $this->assertStringNotContainsString('FeeSourceSelectionService', (string) file_get_contents($file), "{$file} must not call the seam");
                $this->assertStringNotContainsString('FeeSelectionSource', (string) file_get_contents($file), "{$file} must not name a selection source");
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
                $this->assertStringNotContainsString('ChargeService', $code, "{$file} must never create a charge: only Finance assessment runs do (D9)");
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
                    $this->assertStringNotContainsString("{$model}::query()->create", $code, "{$relative} must not write {$model}");
                }
            }
        }
    }
}
