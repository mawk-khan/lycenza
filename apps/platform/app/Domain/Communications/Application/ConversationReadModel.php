<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.7 §18/§21/§23/§56 -- the bounded, N+1-safe read strategy
 * behind the conversation index's latest-activity preview and unread
 * derivation, and the Hub nav's total unread count. Every method here
 * issues a small, FIXED number of queries regardless of how many
 * threads are involved (never one query per thread) -- see each
 * method's docblock for the exact count.
 *
 * Unread derivation follows the brief §21 rule exactly: a thread is
 * unread for a participant when its latest message was created after
 * that participant's `last_read_at` (or `last_read_at` is null) AND
 * that latest message was not authored by the participant themselves.
 * There is no per-message read-receipt row -- see the phase doc's
 * "Read/unread semantics" section for why that is a deliberate scope
 * boundary (brief §24), not an oversight.
 *
 * Wraps its own queries in TenantContext::withSchool() (root CLAUDE.md
 * rule 5) exactly like every other Application service in this module
 * -- never relies on an ambient request-scoped context alone, so a
 * direct call (a test, a future console command) is just as safe as a
 * controller call.
 */
class ConversationReadModel
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * One query for latest-message-per-thread (Postgres `DISTINCT ON`,
     * ADR 0024's Postgres-only stance makes this portable enough for
     * this codebase) plus one query for the caller's own read cursors
     * -- exactly 2 queries regardless of `$threadIds` count.
     *
     * @param  array<int, string>  $threadIds
     * @return Collection<string, ConversationThreadSummary>
     */
    public function summarize(School $school, array $threadIds, string $actorUserId): Collection
    {
        if ($threadIds === []) {
            return collect();
        }

        // Only the two queries need RLS context -- the withSchool()
        // closure returns a plain array (never a Collection with this
        // method's own return shape) specifically so PHPStan never has
        // to unify TenantContext::withSchool()'s templated return type
        // against Collection's (deliberately invariant, not covariant)
        // TValue; see https://phpstan.org/blog/whats-up-with-template-covariant.
        [$lastReadByThread, $latestRows] = $this->context->withSchool($school, function () use ($threadIds, $actorUserId) {
            $lastReadByThread = CommunicationThreadParticipant::query()
                ->where('user_id', $actorUserId)
                ->whereIn('thread_id', $threadIds)
                ->pluck('last_read_at', 'thread_id')
                ->all();

            $latestRows = DB::table('communication_messages')
                ->selectRaw('DISTINCT ON (thread_id) thread_id, id, body, sender_user_id, created_at, '.
                    'exists(select 1 from communication_attachments ca where ca.communication_message_id = communication_messages.id) as has_attachment')
                ->whereIn('thread_id', $threadIds)
                ->orderBy('thread_id')
                ->orderByDesc('created_at')
                ->get()
                ->keyBy('thread_id')
                ->all();

            return [$lastReadByThread, $latestRows];
        });

        $result = [];

        foreach ($threadIds as $threadId) {
            $latest = $latestRows[$threadId] ?? null;
            $lastReadAt = $lastReadByThread[$threadId] ?? null;
            $lastReadAt = $lastReadAt === null ? null : Carbon::parse($lastReadAt);
            $latestAt = $latest === null ? null : Carbon::parse($latest->created_at);

            $unread = $latest !== null
                && $latest->sender_user_id !== $actorUserId
                && ($lastReadAt === null || $latestAt->gt($lastReadAt));

            $result[$threadId] = new ConversationThreadSummary(
                lastReadAt: $lastReadAt,
                latestMessageId: $latest === null ? null : (string) $latest->id,
                latestMessageBody: $latest === null ? null : (string) $latest->body,
                latestMessageSenderId: $latest === null ? null : (string) $latest->sender_user_id,
                latestMessageAt: $latestAt,
                hasAttachment: (bool) ($latest->has_attachment ?? false),
                unread: $unread,
            );
        }

        return collect($result);
    }

    /**
     * Total unread-thread count across every ACTIVE, non-archived
     * thread the actor participates in -- for the Hub nav badge.
     * Bounded by the actor's own total thread-participation count
     * (never a systemic N+1): one query to resolve that thread-id set,
     * then the same fixed 2 queries summarize() always issues.
     */
    public function totalUnreadCount(School $school, string $actorUserId): int
    {
        return $this->context->withSchool($school, function () use ($school, $actorUserId) {
            $threadIds = CommunicationThreadParticipant::query()
                ->where('user_id', $actorUserId)
                ->whereNull('left_at')
                ->where('archived', false)
                ->pluck('thread_id')
                ->all();

            return $this->summarize($school, $threadIds, $actorUserId)
                ->filter(fn (ConversationThreadSummary $s) => $s->unread)
                ->count();
        });
    }
}
