<?php

namespace Tests\Feature\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SWC.5 (ADR 0073 section 20.9): a committing test cleans up the rows IT
 * created -- never "every row created since this test started". With
 * deterministic CI shards (ADR 0072 section 31) a test's neighbours change
 * whenever the inventory changes; a time-window delete then sweeps up
 * another test's deliberate residue (a payroll run's preparer User) and
 * fails on its foreign key -- the SWC.3 StaffRoleGrantorRaceTest failure.
 * Use TestCase::ownedUserIds() / ownedRoleIds(), or ids the test recorded.
 */
class CommittingTestCleanupGuardTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function no_test_deletes_rows_by_a_creation_time_window(): void
    {
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('tests'))) as $file) {
            if ($file->getExtension() !== 'php' || $file->getPathname() === __FILE__) {
                continue;
            }
            // One statement at a time: a `created_at` lower bound anywhere in a
            // statement that ends in ->delete().
            foreach (explode(';', (string) file_get_contents($file->getPathname())) as $statement) {
                // The one exception: TestCase's outbox purge, which selects
                // the Schools to purge by creation time but deletes only
                // `domain_event_outbox` rows -- a table with no foreign key
                // (ADR 0025), so it can never fail on another test's residue.
                if (str_contains($statement, "table('domain_event_outbox')")) {
                    continue;
                }
                if (preg_match("/where\\(\\s*'created_at'\\s*,\\s*'>=?'/", $statement) === 1 && str_contains($statement, '->delete()')) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $hits, 'clean up by owned ids (TestCase::ownedUserIds()/ownedRoleIds()), not by a created_at window');
    }

    #[Test]
    public function owned_ids_record_exactly_what_this_test_created(): void
    {
        $this->assertSame([], $this->ownedUserIds());
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createUserWithCapabilities($school, ['students.view']);

        $this->assertContains($user->id, $this->ownedUserIds());
        $this->assertCount(2, $this->ownedUserIds());
        $this->assertCount(1, $this->ownedRoleIds(), 'createUserWithCapabilities() creates one fixture role');
    }
}
