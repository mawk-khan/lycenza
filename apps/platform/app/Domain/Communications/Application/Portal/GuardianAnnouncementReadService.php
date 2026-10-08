<?php

namespace App\Domain\Communications\Application\Portal;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Domain\Identity\Application\Portal\ActingGuardian;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Portal\PortalAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POR.1 (ADR 0070 §10.1): the Guardian's own announcements, read-only.
 * Communications owns the data; the portal only reads it through here.
 *
 * Visibility = published announcements whose immutable audience snapshot
 * (`communication_announcement_recipients`) names THIS Guardian persona in
 * THIS School -- never a dual-role User's staff mail, never another
 * Guardian's, never another School's (RLS + explicit school_id). Any other
 * id -- unknown, someone else's, another School's -- is the same 404.
 *
 * The caller has already passed `portal-development-only`,
 * `capability:portal.communications.view` and ActingGuardianResolver; every
 * method re-asserts PortalAvailability (defence in depth). Opening an
 * announcement marks only that recipient's own in-app delivery read
 * (recipient-owned state, never School content). No reply, compose,
 * forward or export exists here.
 */
final class GuardianAnnouncementReadService
{
    private const LIMIT = 50;

    private const OVERFETCH = 200;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AnnouncementService $announcements,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return list<array{id: string, title: string, preview: string, sender: ?string, publishedAt: ?string, unread: bool, priority: string, hasAttachments: bool}>
     */
    public function inbox(School $school, ActingGuardian $guardian, bool $unreadOnly = false): array
    {
        PortalAvailability::assertAvailable();

        return $this->context->withSchool($school, function () use ($school, $guardian, $unreadOnly): array {
            $announcements = $this->visible($school, $guardian)
                ->withCount('attachments')
                ->with('createdBy:id,name')
                ->orderByDesc('published_at')
                ->limit($unreadOnly ? self::OVERFETCH : self::LIMIT)
                ->get();

            $unreadMessageIds = $this->unreadMessageIds($guardian, $announcements->pluck('message_id')->filter()->values()->all());

            $items = $announcements->map(fn (CommunicationAnnouncement $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'preview' => Str::limit((string) $a->body, 120),
                'sender' => $a->createdBy?->name,
                'publishedAt' => $a->published_at?->toIso8601String(),
                'unread' => $a->message_id !== null && in_array($a->message_id, $unreadMessageIds, true),
                'priority' => (string) $a->priority,
                'hasAttachments' => $a->attachments_count > 0,
            ]);

            if ($unreadOnly) {
                $items = $items->filter(fn (array $i): bool => $i['unread']);
            }

            return $items->take(self::LIMIT)->values()->all();
        });
    }

    /**
     * @return array{id: string, title: string, body: string, sender: ?string, publishedAt: ?string, priority: string, attachments: list<array{id: string, name: string, mimeType: string, sizeBytes: int}>}
     */
    public function show(School $school, ActingGuardian $guardian, User $actor, string $announcementId): array
    {
        PortalAvailability::assertAvailable();
        $this->assertSelf($guardian, $actor);

        return $this->context->withSchool($school, function () use ($school, $guardian, $actor, $announcementId): array {
            $announcement = $this->visible($school, $guardian)->whereKey($announcementId)
                ->with(['createdBy:id,name', 'attachments'])->first()
                ?? throw (new ModelNotFoundException)->setModel(CommunicationAnnouncement::class);

            // Recipient-owned state: only this User's own in-app delivery.
            $this->announcements->markRead($announcement, $actor);

            return [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => (string) $announcement->body,
                'sender' => $announcement->createdBy?->name,
                'publishedAt' => $announcement->published_at?->toIso8601String(),
                'priority' => (string) $announcement->priority,
                'attachments' => $announcement->attachments->map(fn (CommunicationAttachment $at): array => [
                    'id' => $at->id,
                    'name' => $at->safe_display_name,
                    'mimeType' => $at->mime_type,
                    'sizeBytes' => (int) $at->size_bytes,
                ])->values()->all(),
            ];
        });
    }

    /** An attachment of an announcement visible to this Guardian, download audited; otherwise the same 404. */
    public function attachmentForDownload(School $school, ActingGuardian $guardian, User $actor, string $announcementId, string $attachmentId): CommunicationAttachment
    {
        PortalAvailability::assertAvailable();
        $this->assertSelf($guardian, $actor);

        return $this->context->withSchool($school, function () use ($school, $guardian, $actor, $announcementId, $attachmentId): CommunicationAttachment {
            $announcement = $this->visible($school, $guardian)->whereKey($announcementId)->first()
                ?? throw (new ModelNotFoundException)->setModel(CommunicationAnnouncement::class);

            $attachment = CommunicationAttachment::query()
                ->where('school_id', $school->id)
                ->where('communication_announcement_id', $announcement->id)
                ->whereKey($attachmentId)
                ->first()
                ?? throw (new ModelNotFoundException)->setModel(CommunicationAttachment::class);

            $this->audit->school($school, 'communication_attachment.downloaded', actor: $actor, subject: $attachment, metadata: [
                'announcementId' => $announcement->id,
                'threadId' => null,
                'surface' => 'guardian_portal',
                'guardianId' => $guardian->guardianId,
            ]);

            return $attachment;
        });
    }

    /** @return Builder<CommunicationAnnouncement> */
    private function visible(School $school, ActingGuardian $guardian): Builder
    {
        return CommunicationAnnouncement::query()
            ->where('school_id', $school->id)
            ->where('status', 'published')
            ->whereIn('id', fn ($q) => $q->select('announcement_id')
                ->from('communication_announcement_recipients')
                ->where('school_id', $school->id)
                ->where('guardian_id', $guardian->guardianId));
    }

    /**
     * @param  list<string>  $messageIds
     * @return list<string>
     */
    private function unreadMessageIds(ActingGuardian $guardian, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        return DB::table('communication_recipients as cr')
            ->join('communication_deliveries as cd', 'cd.recipient_id', '=', 'cr.id')
            ->whereIn('cr.message_id', $messageIds)
            ->where('cr.recipient_user_id', $guardian->userId)
            ->where('cd.channel', 'in_app')
            ->whereNull('cd.read_at')
            ->distinct()
            ->pluck('cr.message_id')
            ->all();
    }

    private function assertSelf(ActingGuardian $guardian, User $actor): void
    {
        if ($guardian->userId !== $actor->id) {
            throw (new ModelNotFoundException)->setModel(CommunicationAnnouncement::class);
        }
    }
}
