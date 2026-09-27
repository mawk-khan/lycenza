<?php

namespace App\Support\Email;

use App\Models\EmailMessage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ADR 0055 section 15: fairness, applied when a message is CLAIMED (never
 * one queue per School). Every check here runs inside the submission
 * claim's transaction, under the global claim lock, so counts are exact.
 *
 * - In flight: at most `school_max_in_flight` submissions per School and
 *   `global_max_in_flight` overall; `critical_reserved_in_flight` of those
 *   slots can only be used by critical mail, so a School's announcement
 *   burst can never occupy the capacity its invitations (and, later, O14
 *   recovery mail) need.
 * - Rate: separate per-School minute/day buckets for critical and standard
 *   mail, and separate global per-minute buckets.
 *
 * Over budget means DEFERRED (a later `next_attempt_at`), never failed.
 */
final class EmailThroughput
{
    public function __construct(private readonly Repository $config) {}

    public function inFlightAllows(EmailMessage $message): bool
    {
        $reserve = $message->kind === EmailKind::Critical ? 0 : (int) $this->config->get('email.budgets.critical_reserved_in_flight');

        $school = EmailMessage::query()
            ->where('status', EmailState::Submitting->value)
            ->where('processing_lease_expires_at', '>', now())
            ->count();

        if ($school >= (int) $this->config->get('email.budgets.school_max_in_flight') - $reserve) {
            return false;
        }

        // Every School's in-flight email, without a School context: the
        // platform backlog mirror (no tenant data).
        $global = DB::table('operational_work_backlog')
            ->where('source', 'like', 'email:%')
            ->where('state', EmailState::Submitting->value)
            ->where('lease_expires_at', '>', now())
            ->count();

        return $global < (int) $this->config->get('email.budgets.global_max_in_flight') - $reserve;
    }

    /**
     * 0 when the message may be submitted now (its budgets are consumed);
     * otherwise the seconds until every bucket has room again.
     */
    public function takeRate(EmailMessage $message): int
    {
        $kind = $message->kind->value;
        $budgets = (array) $this->config->get('email.budgets');

        $buckets = [
            ["email-budget:school:{$message->school_id}:{$kind}:m", (int) $budgets["school_{$kind}_per_minute"], 60],
            ["email-budget:school:{$message->school_id}:{$kind}:d", (int) $budgets["school_{$kind}_per_day"], 86400],
            ["email-budget:global:{$kind}:m", (int) $budgets["global_{$kind}_per_minute"], 60],
        ];

        $wait = 0;
        foreach ($buckets as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, max(1, $max))) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        if ($wait > 0) {
            return $wait;
        }

        foreach ($buckets as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return 0;
    }
}
