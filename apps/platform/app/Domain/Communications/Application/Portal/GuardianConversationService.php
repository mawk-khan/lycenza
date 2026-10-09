<?php

namespace App\Domain\Communications\Application\Portal;

use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\ConversationReadModel;
use App\Domain\Communications\Application\ConversationThreadSummary;
use App\Domain\Communications\Domain\CommunicationParticipantKind;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use App\Domain\Guardians\Application\GuardianStudentScope;
use App\Domain\Identity\Application\Portal\ActingGuardian;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Domain\Identity\Application\Portal\GuardianPortalAccessDeniedException;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Portal\PortalAvailability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POR.4 (ADR 0070 §27): a Guardian's existing School conversations, and
 * replies to them. Communications owns the data; the portal reads and writes
 * it only through here, and every message is written by the one authoritative
 * writer, CommunicationMessageService::send().
 *
 * A thread is the Guardian's (`visible()`) only when ALL hold:
 * - this User's own participant row joined it AS this Guardian persona
 *   (`participant_kind = guardian`, this `guardian_id`) and has not left. A
 *   dual-role User's staff participation (`membership`) never counts;
 * - no other Guardian persona takes part (Guardian-to-Guardian visibility is
 *   withheld pending POR-L1 Q11-Q12; fail closed);
 * - every Student participant is in this Guardian's live GuardianStudentScope
 *   (a Student-involving thread is Student-scoped; fail closed).
 * Anything else -- unknown, another Guardian's, staff-only, another School's
 * -- is the same 404.
 *
 * Authority is never the participant row alone: every method needs
 * PortalAvailability, this User's own live ActingGuardian and the portal
 * capability (`portal.communications.view`; replies also
 * `portal.communications.reply`), re-checked here, not only by the routes.
 * No thread creation, edit, delete, forward, priority or upload exists here.
 */
final class GuardianConversationService
{
    public const VIEW = 'portal.communications.view';

    public const REPLY = 'portal.communications.reply';

    /** The staff Hub's own message limit (CommunicationHubController::storeMessage). */
    public const MAX_BODY_LENGTH = 10000;

    public const SURFACE = 'guardian_portal';

    private const LIST_LIMIT = 50;

    private const PAGE_SIZE = 30;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ActingGuardianResolver $guardians,
        private readonly GuardianStudentScope $scope,
        private readonly CommunicationThreadService $threads,
        private readonly CommunicationMessageService $messages,
        private readonly ConversationReadModel $readModel,
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolOperationalGuard $operational,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return list<array{id: string, subject: ?string, open: bool, participants: list<string>, lastActivityAt: ?string, preview: ?string, latestFromMe: bool, unread: bool}>
     */
    public function conversations(School $school, ActingGuardian $guardian, User $actor): array
    {
        PortalAvailability::assertAvailable();
        $this->assertSelf($school, $guardian, $actor, [self::VIEW]);

        return $this->context->withSchool($school, function () use ($school, $guardian, $actor): array {
            $threads = $this->visible($school, $guardian)
                ->with(['participants' => fn ($q) => $q->whereNull('left_at')->with('user:id,name')])
                ->orderByDesc('last_activity_at')
                ->limit(self::LIST_LIMIT)
                ->get();

            $summaries = $this->readModel->summarize($school, $threads->pluck('id')->all(), $actor->id);

            return $threads->map(function (CommunicationThread $t) use ($actor, $summaries): array {
                /** @var ConversationThreadSummary $summary */
                $summary = $summaries->get($t->id);

                return [
                    'id' => $t->id,
                    'subject' => $t->subject,
                    'open' => $t->isOpen(),
                    'participants' => $this->otherNames($t, $actor),
                    'lastActivityAt' => $t->last_activity_at?->toIso8601String(),
                    'preview' => $summary->latestMessageBody === null ? null : Str::limit($summary->latestMessageBody, 120),
                    'latestFromMe' => $summary->latestMessageSenderId === $actor->id,
                    'unread' => $summary->unread,
                ];
            })->values()->all();
        });
    }

    /**
     * One visible thread, newest page first (shown oldest-first). Opening it
     * moves only this participant's own read cursor.
     *
     * @return array{id: string, subject: ?string, open: bool, canReply: bool, participants: list<string>, messages: list<array{id: string, sender: string, mine: bool, body: string, sentAt: ?string, attachments: list<array{id: string, name: string, mimeType: string, sizeBytes: int}>}>, hasOlder: bool, page: int}
     */
    public function thread(School $school, ActingGuardian $guardian, User $actor, string $threadId, int $page = 1): array
    {
        PortalAvailability::assertAvailable();
        $this->assertSelf($school, $guardian, $actor, [self::VIEW]);

        return $this->context->withSchool($school, function () use ($school, $guardian, $actor, $threadId, $page): array {
            $thread = $this->visible($school, $guardian)->whereKey($threadId)
                ->with(['participants' => fn ($q) => $q->whereNull('left_at')->with('user:id,name')])
                ->first()
                ?? throw (new ModelNotFoundException)->setModel(CommunicationThread::class);

            $this->threads->markRead($this->ownParticipant($thread, $guardian)->firstOrFail());

            $paginated = CommunicationMessage::query()
                ->where('school_id', $school->id)
                ->where('thread_id', $thread->id)
                ->with(['sender:id,name', 'attachments'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(self::PAGE_SIZE, page: max(1, $page));

            return [
                'id' => $thread->id,
                'subject' => $thread->subject,
                'open' => $thread->isOpen(),
                'canReply' => $thread->isOpen() && $this->capabilities->canInSchool($actor, self::REPLY, $school),
                'participants' => $this->otherNames($thread, $actor),
                'messages' => collect($paginated->items())->reverse()->values()->map(fn (CommunicationMessage $m): array => [
                    'id' => $m->id,
                    'sender' => $m->sender_user_id === $actor->id ? 'You' : (string) $m->sender?->name,
                    'mine' => $m->sender_user_id === $actor->id,
                    'body' => (string) $m->body,
                    'sentAt' => $m->created_at?->toIso8601String(),
                    'attachments' => $m->attachments->map(fn (CommunicationAttachment $a): array => [
                        'id' => $a->id,
                        'name' => $a->safe_display_name,
                        'mimeType' => $a->mime_type,
                        'sizeBytes' => (int) $a->size_bytes,
                    ])->values()->all(),
                ])->all(),
                'hasOlder' => $paginated->hasMorePages(),
                'page' => $paginated->currentPage(),
            ];
        });
    }

    /**
     * Posts a text reply as this Guardian. Idempotent on the server-issued
     * form key (ADR 0070 §27.5): the same User + same key + same thread +
     * same text answers the original message (`replayed`); any other reuse
     * of the key is refused. Authority is re-checked under locks before any
     * replay or write (rule 32).
     */
    public function reply(School $school, ActingGuardian $guardian, User $actor, string $threadId, string $body, string $idempotencyKey): GuardianReplyResult
    {
        PortalAvailability::assertAvailable();
        $this->assertSelf($school, $guardian, $actor, [self::VIEW, self::REPLY]);

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_BODY_LENGTH || str_contains($body, "\0") || ! mb_check_encoding($body, 'UTF-8')) {
            throw new GuardianReplyException(GuardianReplyException::INVALID_BODY);
        }
        if (! Str::isUuid($idempotencyKey)) {
            throw new GuardianReplyException(GuardianReplyException::KEY_CONFLICT);
        }
        // One spelling, so the advisory lock and the stored uuid agree.
        $idempotencyKey = strtolower($idempotencyKey);

        return $this->context->withSchool($school, function () use ($school, $guardian, $actor, $threadId, $body, $idempotencyKey): GuardianReplyResult {
            try {
                return DB::transaction(fn () => $this->replyLocked($school, $guardian, $actor, $threadId, $body, $idempotencyKey));
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'communication_messages_sender_idempotency_unique')) {
                    throw $e;
                }

                // Unreachable while the advisory lock serializes same-key
                // requests; kept because the unique index, not the lock, is
                // the authoritative claim (rule 30). One re-run: everything is
                // re-checked, then the committed message is found.
                return DB::transaction(fn () => $this->replyLocked($school, $guardian, $actor, $threadId, $body, $idempotencyKey));
            }
        });
    }

    /** An attachment of a message in a visible thread, download audited; otherwise the same 404. */
    public function attachmentForDownload(School $school, ActingGuardian $guardian, User $actor, string $threadId, string $attachmentId): CommunicationAttachment
    {
        PortalAvailability::assertAvailable();
        $this->assertSelf($school, $guardian, $actor, [self::VIEW]);

        return $this->context->withSchool($school, function () use ($school, $guardian, $actor, $threadId, $attachmentId): CommunicationAttachment {
            $thread = $this->visible($school, $guardian)->whereKey($threadId)->first()
                ?? throw (new ModelNotFoundException)->setModel(CommunicationAttachment::class);

            // Only an attachment already sent with a message of THIS thread --
            // never another participant's pending (unsent) upload.
            $attachment = CommunicationAttachment::query()
                ->where('school_id', $school->id)
                ->where('communication_thread_id', $thread->id)
                ->whereIn('communication_message_id', CommunicationMessage::query()->select('id')
                    ->where('school_id', $school->id)
                    ->where('thread_id', $thread->id))
                ->whereKey($attachmentId)
                ->first()
                ?? throw (new ModelNotFoundException)->setModel(CommunicationAttachment::class);

            $this->audit->school($school, 'communication_attachment.downloaded', actor: $actor, subject: $attachment, metadata: [
                'announcementId' => null,
                'threadId' => $thread->id,
                'surface' => self::SURFACE,
                'guardianId' => $guardian->guardianId,
            ]);

            return $attachment;
        });
    }

    /**
     * Lock order (ADR 0070 §27.6): School FOR SHARE (rule 86) -> account link
     * FOR SHARE -> membership FOR SHARE (ActingGuardianResolver::resolveLocked)
     * -> the reply-key advisory lock -> thread FOR NO KEY UPDATE (what its
     * `last_activity_at` update takes anyway, so two replies never upgrade a
     * shared lock into a deadlock) -> own participant row FOR SHARE.
     */
    private function replyLocked(School $school, ActingGuardian $guardian, User $actor, string $threadId, string $body, string $idempotencyKey): GuardianReplyResult
    {
        if (! $this->operational->holdOperational($school->id)) {
            throw new GuardianPortalAccessDeniedException;
        }

        $live = $this->guardians->resolveLocked($actor, $school);
        if ($live === null || $live->accountLinkId !== $guardian->accountLinkId || $live->guardianId !== $guardian->guardianId || $live->membershipId !== $guardian->membershipId) {
            throw new GuardianPortalAccessDeniedException;
        }

        // Fresh, not cached: a grant revoked by a committed unlink/off-boarding
        // is seen here (and one in flight is waiting on the link lock above).
        $this->capabilities->forgetCache($actor, $school);
        foreach ([self::VIEW, self::REPLY] as $capability) {
            if (! $this->capabilities->canInSchool($actor, $capability, $school)) {
                throw new GuardianPortalAccessDeniedException;
            }
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["communications.guardian_reply:{$school->id}:{$actor->id}:{$idempotencyKey}"]);

        $thread = $this->visible($school, $live)->whereKey($threadId)->lock('FOR NO KEY UPDATE')->first()
            ?? throw (new ModelNotFoundException)->setModel(CommunicationThread::class);

        $this->ownParticipant($thread, $live)->sharedLock()->first()
            ?? throw (new ModelNotFoundException)->setModel(CommunicationThread::class);

        $existing = CommunicationMessage::query()
            ->where('school_id', $school->id)
            ->where('sender_user_id', $actor->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->thread_id !== $thread->id || $existing->body !== $body) {
                throw new GuardianReplyException(GuardianReplyException::KEY_CONFLICT);
            }

            return new GuardianReplyResult($existing->id, replayed: true);
        }

        if (! $thread->isOpen()) {
            throw new GuardianReplyException(GuardianReplyException::NOT_OPEN);
        }

        $message = $this->messages->send($thread, $actor, $body, CommunicationPriority::Normal, [], $idempotencyKey);

        $studentIds = CommunicationThreadParticipant::query()
            ->where('thread_id', $thread->id)
            ->where('participant_kind', CommunicationParticipantKind::Student->value)
            ->pluck('student_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->audit->school($school, 'communication.guardian.replied', actor: $actor, subject: $message, metadata: array_filter([
            'guardianId' => $live->guardianId,
            'accountLinkId' => $live->accountLinkId,
            'threadId' => $thread->id,
            'messageId' => $message->id,
            'studentIds' => $studentIds === [] ? null : $studentIds,
            'surface' => self::SURFACE,
        ], fn ($value) => $value !== null));

        return new GuardianReplyResult($message->id, replayed: false);
    }

    /** @return Builder<CommunicationThread> */
    private function visible(School $school, ActingGuardian $guardian): Builder
    {
        return CommunicationThread::query()
            ->where('communication_threads.school_id', $school->id)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('communication_thread_participants as own')
                ->whereColumn('own.thread_id', 'communication_threads.id')
                ->where('own.school_id', $school->id)
                ->where('own.user_id', $guardian->userId)
                ->where('own.participant_kind', CommunicationParticipantKind::Guardian->value)
                ->where('own.guardian_id', $guardian->guardianId)
                ->whereNull('own.left_at'))
            // Another Guardian persona (or this persona through another
            // account), whether or not they have since left: withheld.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('communication_thread_participants as other_guardian')
                ->whereColumn('other_guardian.thread_id', 'communication_threads.id')
                ->where('other_guardian.participant_kind', CommunicationParticipantKind::Guardian->value)
                ->where(fn ($w) => $w->where('other_guardian.user_id', '!=', $guardian->userId)
                    ->orWhere('other_guardian.guardian_id', '!=', $guardian->guardianId)))
            // A Student participant outside this Guardian's live scope: withheld.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('communication_thread_participants as student')
                ->whereColumn('student.thread_id', 'communication_threads.id')
                ->where('student.participant_kind', CommunicationParticipantKind::Student->value)
                ->whereNotIn('student.student_id', $this->scope->eligibleStudentIdsQuery($school, $guardian->guardianId)));
    }

    /** @return Builder<CommunicationThreadParticipant> */
    private function ownParticipant(CommunicationThread $thread, ActingGuardian $guardian): Builder
    {
        return CommunicationThreadParticipant::query()
            ->where('thread_id', $thread->id)
            ->where('user_id', $guardian->userId)
            ->where('participant_kind', CommunicationParticipantKind::Guardian->value)
            ->where('guardian_id', $guardian->guardianId)
            ->whereNull('left_at');
    }

    /**
     * Display names of the thread's other current participants -- names only,
     * never ids, roles, capabilities or contact details.
     *
     * @return list<string>
     */
    private function otherNames(CommunicationThread $thread, User $actor): array
    {
        return $thread->participants
            ->filter(fn (CommunicationThreadParticipant $p) => $p->user_id !== $actor->id)
            ->map(fn (CommunicationThreadParticipant $p) => (string) $p->user?->name)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Defence in depth (rule 6): the ActingGuardian must be this User's in
     * this School, and the capabilities are re-checked here, not only by the
     * routes -- a future non-route caller cannot skip them.
     *
     * @param  list<string>  $capabilities
     */
    private function assertSelf(School $school, ActingGuardian $guardian, User $actor, array $capabilities): void
    {
        if ($guardian->userId !== $actor->id || $guardian->schoolId !== $school->id) {
            throw (new ModelNotFoundException)->setModel(CommunicationThread::class);
        }

        foreach ($capabilities as $capability) {
            if (! $this->capabilities->canInSchool($actor, $capability, $school)) {
                throw new GuardianPortalAccessDeniedException;
            }
        }
    }
}
