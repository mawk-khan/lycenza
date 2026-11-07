<?php

namespace Tests\Feature\HR;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * HR.R1-C10 (XRP-7, rule 130): staff type is a classification and grants nothing.
 * It held by absence only, so this pins the absence where authority is decided.
 */
class StaffTypeGrantsNothingTest extends TestCase
{
    #[Test]
    public function no_authority_code_reads_a_staff_type(): void
    {
        $roots = [app_path('Support/Authorization'), app_path('Support/Tenancy'), app_path('Http/Middleware'), app_path('Domain/Identity')];
        $checked = 0;
        foreach ($roots as $root) {
            $this->assertDirectoryExists($root);
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $checked++;
                $source = (string) file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression('/\bStaffType\b|staff_type|staffType/', $source, $file->getPathname().' reads a staff type: it must grant nothing');
            }
        }
        $this->assertGreaterThan(50, $checked, 'the scan reached the authority code');
    }
}
