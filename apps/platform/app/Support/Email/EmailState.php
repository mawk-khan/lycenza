<?php

namespace App\Support\Email;

/**
 * ADR 0055 section 9.2, as implemented in Phase 0O.9A. An explicit graph,
 * never an ordering: the SAME graph is enforced by the database
 * (`email_messages_transition_allowed()`), so a direct write cannot move a
 * message backward either.
 *
 *   pending    -> submitting | suppressed | cancelled | failed
 *   submitting -> submitted | pending (retry scheduled) | failed
 *   submitted  -> deferred | delivered | bounced | complained | failed (provider rejected)
 *   deferred   -> delivered | bounced | complained | failed (provider rejected)
 *   delivered  -> bounced | complained
 *   bounced, complained, failed, suppressed, cancelled: final
 *
 * "Final" depends on the concern (brief step 8):
 * - submission: only `pending`/`submitting` are ever (re)submitted;
 * - content: sealed content exists only while `pending`/`submitting`
 *   (the trigger purges it on any other state, and at expiry);
 * - observation: provider events still apply to `submitted`, `deferred`
 *   and `delivered` (a bounce or complaint may follow a delivery).
 */
enum EmailState: string
{
    case Pending = 'pending';
    case Submitting = 'submitting';
    case Submitted = 'submitted';
    case Deferred = 'deferred';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Cancelled = 'cancelled';

    private const EDGES = [
        'pending' => ['submitting', 'suppressed', 'cancelled', 'failed'],
        'submitting' => ['submitted', 'pending', 'failed'],
        'submitted' => ['deferred', 'delivered', 'bounced', 'complained', 'failed'],
        'deferred' => ['delivered', 'bounced', 'complained', 'failed'],
        'delivered' => ['bounced', 'complained'],
    ];

    public function canTransitionTo(self $next): bool
    {
        return $next === $this || in_array($next->value, self::EDGES[$this->value] ?? [], true);
    }

    /** @return list<array{0: string, 1: string}> every permitted change (guard-tested against the database) */
    public static function edges(): array
    {
        $edges = [];
        foreach (self::EDGES as $from => $targets) {
            foreach ($targets as $to) {
                $edges[] = [$from, $to];
            }
        }

        return $edges;
    }

    /** Only these states are ever (re)submitted. */
    public function awaitsSubmission(): bool
    {
        return $this === self::Pending || $this === self::Submitting;
    }

    /** Provider events can still change these. */
    public function observesProviderEvents(): bool
    {
        return in_array($this, [self::Submitted, self::Deferred, self::Delivered], true);
    }

    public function isFinal(): bool
    {
        return ! $this->awaitsSubmission() && ! $this->observesProviderEvents();
    }
}
