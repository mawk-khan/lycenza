<?php

namespace App\Domain\Communications\Application\Approval;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;

/**
 * Phase 5A.12 §4/§17-§26 -- computes the deterministic, server-side
 * canonical fingerprint an approval is bound to. Mirrors
 * App\Support\Idempotency\RequestFingerprint's exact shape (recursive
 * key-sorted JSON, SHA-256) -- the established canonical-hash pattern
 * in this codebase, reused rather than invented anew.
 *
 * Approval-sensitive fields (brief §4): title, body, audience
 * definition (type + canonicalized individual member ids where
 * applicable), requested channels, priority, requirement, dispatch
 * mode, attachment identities (checksums only, brief §25/§66 -- never
 * raw bytes). Deliberately EXCLUDES `scheduled_at` (brief §34:
 * "schedule time is operational timing, not message meaning") and
 * anything resolved only at publish time (the actual recipient
 * snapshot, brief §19/§53).
 */
class CommunicationApprovalFingerprint
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(CommunicationAnnouncement $announcement): array
    {
        // Always a FRESH read of these relations, never the caller's
        // possibly-stale cached collections -- a mutation applied via
        // a raw query builder (App\Domain\Communications\Application\AnnouncementService::syncChannels()/
        // syncAudienceMembers(), which delete+insert directly, bypassing
        // any already-loaded relation on this exact instance) must
        // always be reflected here. Fingerprint correctness is a
        // security boundary (brief §17/§32), not just a convenience
        // read.
        $announcement = $announcement->fresh(['requestedChannels', 'audienceMembers', 'attachments']);

        return [
            'title' => trim($announcement->title),
            'body' => trim($announcement->body),
            'priority' => $announcement->priority,
            'requirement' => $announcement->requirement,
            'dispatchMode' => $announcement->dispatch_mode,
            'audienceType' => $announcement->audience_type,
            // Brief §20: canonicalized (sorted, deduplicated) --
            // selection ORDER was never meaningful, only membership.
            'individualMemberIds' => $announcement->audience_type === 'individual'
                ? $this->sortedUnique($announcement->audienceMembers->pluck('school_membership_id')->all())
                : [],
            // Brief §21: canonicalized channel set.
            'channels' => $this->sortedUnique($announcement->requestedChannels->pluck('channel')->all()),
            // Brief §25/§66: identity only, never bytes/storage paths.
            'attachmentChecksums' => $this->sortedUnique($announcement->attachments->pluck('checksum_sha256')->all()),
        ];
    }

    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * Recursively sorts associative-array (JSON object) keys so key
     * insertion order never affects the hash -- identical to
     * App\Support\Idempotency\RequestFingerprint::canonicalize(). List
     * (numeric-indexed) order is left untouched, since every list value
     * here was already explicitly sorted by snapshot() itself.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(fn ($value) => is_array($value) ? $this->canonicalize($value) : $value, $data);
        }

        ksort($data);

        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) ? $this->canonicalize($value) : $value;
        }

        return $data;
    }
}
