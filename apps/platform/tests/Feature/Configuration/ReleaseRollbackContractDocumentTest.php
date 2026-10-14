<?php

namespace Tests\Feature\Configuration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PPR.1 (ADR 0074 section 26.7, finding F-4): the release runbooks state
 * ONE rollback contract -- application rollback never runs a migration
 * `down()` in a hardened environment; a schema defect is corrected forward,
 * or by an approved PITR recovery. Neither runbook may again prescribe
 * `migrate:rollback` as the rollback path.
 */
#[Group('repository-documents')]
class ReleaseRollbackContractDocumentTest extends TestCase
{
    #[Test]
    public function both_release_runbooks_carry_the_one_rollback_contract(): void
    {
        foreach ([
            'MAINTENANCE-WINDOW-RELEASE.md' => dirname(base_path(), 2).'/docs/operations/MAINTENANCE-WINDOW-RELEASE.md',
            'PRODUCTION-RELEASE.md' => dirname(base_path(), 2).'/docs/architecture/PRODUCTION-RELEASE.md',
        ] as $relative => $file) {
            $this->assertFileExists($file);
            $source = (string) file_get_contents($file);

            $this->assertStringContainsString('Rollback contract (ADR 0074 §26.7', $source, $relative);
            $this->assertStringContainsString('**Application rollback.**', $source, $relative);
            $this->assertStringContainsString('**Database correction.**', $source, $relative);
            $this->assertStringContainsString('never runs a migration `down()`', $source, $relative);
            $this->assertStringContainsString('forward corrective migration', $source, $relative);
            $this->assertDoesNotMatchRegularExpression('/schema rollback is a separate, deliberate `migrate:rollback/', $source, $relative);
        }
    }
}
