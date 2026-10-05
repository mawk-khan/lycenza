<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * E21-RH.4: a test class whose retention functions now run as the dedicated
 * retention identity, on its own connection (a separate session), cannot
 * build its fixtures inside an uncommitted test transaction: the retention
 * session would never see them. Using this trait makes the class COMMIT its
 * fixtures and clean every durable row up again, hermetically
 * (PurgesCommittedHrxFixtures, through Laravel's setUp<Trait> /
 * tearDown<Trait> hooks), asserting nothing is left behind.
 */
trait CommitsRetentionFixtures
{
    use PurgesCommittedHrxFixtures;

    /** @var array<int, string> no wrapping test transaction: the retention session must see the fixtures */
    protected $connectionsToTransact = [];

    protected function setUpCommitsRetentionFixtures(): void
    {
        $this->snapshotDurableFixtures();
    }

    protected function tearDownCommitsRetentionFixtures(): void
    {
        DB::purge('pgsql_retention');
        $this->purgeCommittedHrxSchools([]);
        $this->assertDurableFixturesRestored();
    }
}
