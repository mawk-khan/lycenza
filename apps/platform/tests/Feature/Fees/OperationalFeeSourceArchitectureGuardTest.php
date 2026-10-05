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
    /** OPF source modules implemented so far (OPF.1). */
    private const SOURCE_MODULES = ['Transport'];

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
                foreach (self::SOURCE_MODULES as $module) {
                    $this->assertStringNotContainsString("App\\Domain\\{$module}\\", $code, "{$file} must not depend on {$module} (ADR 0067 §4)");
                }
                $this->assertDoesNotMatchRegularExpression("/'transport_[a-z_]+'/", $code, "{$file} must not read a Transport table (ADR 0067 §4)");
            }
        }
    }

    #[Test]
    public function only_source_module_application_services_call_the_trusted_seam(): void
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

        $this->assertSame(['Domain/Transport/Application/TransportFeeSelectionService.php'], $callers, 'the seam has no route and no other caller (ADR 0067 §8)');
    }

    #[Test]
    public function transport_reaches_fees_only_through_its_application_seam(): void
    {
        foreach ($this->files('Domain/Transport') as $file) {
            $code = (string) file_get_contents($file);
            $this->assertStringNotContainsString('App\\Domain\\Fees\\Infrastructure', $code, "{$file} must not touch FEE models (ADR 0067 §4)");
            $this->assertStringNotContainsString('App\\Domain\\Finance\\', $code, $file);
            $this->assertStringNotContainsString('App\\Domain\\Payments\\', $code, $file);
            $this->assertDoesNotMatchRegularExpression("/'(charges|fee_optional_selections|fee_assessments)'/", $code, "{$file} must not read FEE tables");
        }
    }

    #[Test]
    public function only_the_transport_fee_service_writes_the_transport_fee_tables(): void
    {
        foreach ($this->files('') as $file) {
            $relative = substr($file, strlen(app_path()) + 1);
            if ($relative === 'Domain/Transport/Application/TransportFeeSelectionService.php') {
                continue;
            }
            $code = (string) file_get_contents($file);
            foreach (['TransportFeeSelection::query()->create', 'TransportRouteFeeHead::query()->create'] as $write) {
                $this->assertStringNotContainsString($write, $code, "{$relative} must not write Transport fee intent");
            }
        }
    }
}
