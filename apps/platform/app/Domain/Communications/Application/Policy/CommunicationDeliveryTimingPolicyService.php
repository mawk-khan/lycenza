<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryTimingPolicy;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Phase 5A.9 -- the ONE authoritative decision path for "may this
 * already-ALLOWed secondary-channel delivery transport now, or must it
 * wait." Reused by exactly two callers, never a third parallel
 * calculation:
 *
 *  - App\Domain\Communications\Application\AnnouncementService::publish()
 *    (first-attempt eligibility, evaluated once per requested channel
 *    per publish() call -- never once per recipient, brief §60).
 *  - App\Jobs\ProcessCommunicationDeliveryJob::scheduleRetry() (a
 *    computed retry backoff timestamp that itself falls inside quiet
 *    hours is pushed out to the next permitted instant, brief §33).
 *
 * This is evaluated strictly AFTER
 * App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService
 * has already returned ALLOW (brief §31/§19) -- there is no delivery
 * to time if channel policy already suppressed it. IN_APP is never
 * evaluated at all (brief §5): the canonical Communication Hub record
 * is never delayed by this engine, so callers only ever invoke this
 * for CommunicationChannel::Email today, and the method itself is
 * defensively closed for every other channel too.
 *
 * Priority (NORMAL/IMPORTANT/URGENT/CRITICAL) and Requirement
 * (OPTIONAL/REQUIRED) are never consulted here -- brief §6: neither
 * concept means "bypass quiet hours" in this checkpoint. A future
 * explicit, separately-authorized emergency-bypass concept would slot
 * in as an additional, clearly-named precedence step -- not implemented
 * here.
 *
 * Resolved fresh per request/command run (never a singleton), with a
 * private in-memory cache for the lifetime of that one instance --
 * same performance shape as CommunicationChannelPolicyService.
 */
class CommunicationDeliveryTimingPolicyService
{
    /** @var array<string, CommunicationDeliveryTimingPolicy|null> "schoolId:channel" => policy row, or null for "no row" */
    private array $policyCache = [];

    public function __construct(private readonly TenantContext $context) {}

    public function evaluate(School $school, CommunicationChannel $channel, Carbon $at): CommunicationTimingDecision
    {
        if ($channel !== CommunicationChannel::Email) {
            return CommunicationTimingDecision::sendNow();
        }

        $policy = $this->policyFor($school, $channel);

        if ($policy === null || ! $policy->enabled || $policy->quiet_hours_start === null || $policy->quiet_hours_end === null) {
            return CommunicationTimingDecision::sendNow();
        }

        $timezone = SchoolTimezone::resolve($school);
        $local = $at->copy()->setTimezone($timezone);

        $startSeconds = $this->timeOfDaySeconds($policy->quiet_hours_start);
        $endSeconds = $this->timeOfDaySeconds($policy->quiet_hours_end);

        if ($startSeconds === $endSeconds) {
            // Brief §13: an equal start/end is rejected at write time
            // (App\Domain\Communications\Http\Controllers\CommunicationDeliveryTimingPolicyController::update()) --
            // this is a defensive fallback for a row written before
            // that validation existed, never blocking delivery.
            return CommunicationTimingDecision::sendNow();
        }

        $nowSeconds = $this->timeOfDaySeconds($local->format('H:i:s'));

        if (! $this->isWithinQuietWindow($nowSeconds, $startSeconds, $endSeconds)) {
            return CommunicationTimingDecision::sendNow();
        }

        $availableAtLocal = $local->copy()->setTimeFromTimeString($policy->quiet_hours_end);

        // Cross-midnight window (start > end): if `now` falls in the
        // evening portion (>= start), the next permitted instant is
        // tomorrow's end time -- `addDay()` performs timezone-aware
        // calendar-day arithmetic (brief §18: correct across a DST
        // transition), never a fixed +24h offset.
        if ($startSeconds > $endSeconds && $nowSeconds >= $startSeconds) {
            $availableAtLocal = $availableAtLocal->addDay();
        }

        return CommunicationTimingDecision::deferUntil($availableAtLocal->utc());
    }

    private function policyFor(School $school, CommunicationChannel $channel): ?CommunicationDeliveryTimingPolicy
    {
        $key = "{$school->id}:{$channel->value}";

        if (! array_key_exists($key, $this->policyCache)) {
            $this->policyCache[$key] = $this->context->withSchool(
                $school,
                fn () => CommunicationDeliveryTimingPolicy::query()
                    ->where('school_id', $school->id)
                    ->where('channel', $channel->value)
                    ->first(),
            );
        }

        return $this->policyCache[$key];
    }

    /**
     * Brief §14/§15: quiet begins INCLUSIVE at start, ends EXCLUSIVE
     * at end -- exactly at the end boundary is SEND_NOW. Handles both
     * a same-day window (start < end, e.g. 13:00-15:00) and a
     * cross-midnight window (start > end, e.g. 20:00-07:00) with the
     * same comparison.
     */
    private function isWithinQuietWindow(int $nowSeconds, int $startSeconds, int $endSeconds): bool
    {
        if ($startSeconds < $endSeconds) {
            return $nowSeconds >= $startSeconds && $nowSeconds < $endSeconds;
        }

        return $nowSeconds >= $startSeconds || $nowSeconds < $endSeconds;
    }

    private function timeOfDaySeconds(string $time): int
    {
        [$hours, $minutes, $seconds] = array_pad(explode(':', $time), 3, '0');

        return ((int) $hours * 3600) + ((int) $minutes * 60) + (int) $seconds;
    }
}
