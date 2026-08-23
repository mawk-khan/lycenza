<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationRequirement;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.5 §55/§56: proves the two batching claims
 * App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService
 * makes directly, at the query-count level, rather than only asserting
 * end-to-end behavior:
 *
 * 1. School policy is loaded with exactly ONE query per School,
 *    reused for every subsequent evaluate() call regardless of how
 *    many recipients/channels are evaluated against it.
 * 2. Recipient preferences for a whole audience CHUNK are loaded with
 *    exactly ONE query, regardless of chunk size -- never one query
 *    per membership id (the classic N+1 shape).
 */
class AnnouncementPolicyScaleTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    #[Test]
    public function school_policy_is_loaded_once_per_school_regardless_of_evaluation_count(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $service = app(CommunicationChannelPolicyService::class);

        $service->policyFor($school, CommunicationChannel::Email);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // 20 more lookups against the SAME already-cached School.
        for ($i = 0; $i < 20; $i++) {
            $service->policyFor($school, CommunicationChannel::Email);
        }

        $this->assertSame(0, $queryCount, 'A second and later policyFor() call for the same School must never re-query.');
    }

    #[Test]
    public function preloading_preferences_for_a_whole_audience_chunk_issues_exactly_one_query(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $service = app(CommunicationChannelPolicyService::class);

        $membershipIds = [];
        for ($i = 0; $i < 30; $i++) {
            $membershipIds[] = $this->createMembership($this->createUser(), $school)->id;
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // One chunk of 30 membership ids. Exactly 3 queries regardless
        // of chunk size: TenantContext::withSchool()'s own set_config
        // before the callback and its restore/clear in a `finally`
        // afterward (root CLAUDE.md rule 20/21 -- unrelated to this
        // class), plus the ONE real preference SELECT itself -- never
        // one SELECT per membership id (that would be 30, not 3).
        $service->preloadPreferences($school, $membershipIds, CommunicationChannel::Email);

        $this->assertSame(3, $queryCount);

        // Evaluating every one of them afterward costs exactly 3 MORE
        // queries total for all 30 -- not 30 -- since evaluate() also
        // resolves School policy via policyFor() (§ above), which
        // cache-misses exactly once (the first evaluate() call) and
        // is then free for the remaining 29.
        $queryCount = 0;
        foreach ($membershipIds as $membershipId) {
            $service->evaluate($school, $membershipId, CommunicationChannel::Email, CommunicationRequirement::Optional);
        }
        $this->assertSame(3, $queryCount);
    }
}
