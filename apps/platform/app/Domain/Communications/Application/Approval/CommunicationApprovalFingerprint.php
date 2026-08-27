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
 *
 * Phase 5B.1 §22/§44: `domainAudienceIds` (canonicalized Student ids
 * for `student`/`guardians_of_students`, Guardian ids for `guardian`)
 * is approval-sensitive for the SAME reason `individualMemberIds`
 * already is -- changing WHO a message targets changes what was
 * approved. Deliberately EXCLUDES any GuardianContact/destination
 * value -- contact-destination resolution happens at PUBLISH time
 * (docs/communication-hub/
 * PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md §"Approval
 * fingerprint semantics": approval binds to WHO is targeted, not to
 * where a message happens to be deliverable right now -- editing a
 * Guardian's email after approval never invalidates it, matching
 * brief §44's documented choice), and never hashes a decrypted
 * contact value (brief §22/§32).
 *
 * Phase 5B.3 §31/§32, extended by Phase 5C.1 closure-blocker fix:
 * `academicCohort` hashes the cohort DEFINITION only -- cohortType,
 * academicYearId, gradeLevelId/sectionId/subjectOfferingId,
 * recipientKind -- the same fields
 * App\Domain\Communications\Application\AnnouncementService::syncAcademicCohort()
 * validates and persists. It deliberately NEVER includes any resolved
 * Student/Guardian id (those are re-resolved fresh at publish time,
 * exactly like `domainAudienceIds` excludes GuardianContact values
 * above) and never includes an account-link/reachability state.
 * Consequence (brief §32, mirroring §44's "enrollment changes never
 * invalidate approval"): a Student transferring into or out of the
 * approved Grade/Section/SubjectOffering AFTER approval does not
 * change this fingerprint and therefore never invalidates the
 * approval -- only changing WHICH Grade/Section/SubjectOffering/
 * AcademicYear/recipient-kind was approved does.
 *
 * Phase 5 closure-blocker fix: `$isAcademicCohortAudience` originally
 * checked only `grade`/`section`, omitting `subject_offering` (Phase
 * 5C.1) -- so a SubjectOffering-audience announcement's fingerprint
 * always computed `academicCohort => null`, and switching the
 * targeted SubjectOffering after approval never invalidated it. Fixed
 * to include `subject_offering`, and `subjectOfferingId` was added to
 * the captured field set (it was never present even for the
 * gradeLevelId/sectionId-shaped payload) -- omitting it would have
 * left two different SubjectOfferings of the same recipient kind
 * producing an identical fingerprint despite the type-check fix.
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
        $announcement = $announcement->fresh(['requestedChannels', 'audienceMembers', 'domainAudienceMembers', 'attachments', 'academicCohort']);

        $isDomainAudience = in_array($announcement->audience_type, ['student', 'guardian', 'guardians_of_students'], true);
        $isAcademicCohortAudience = in_array($announcement->audience_type, ['grade', 'section', 'subject_offering'], true);

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
            // Phase 5B.1: canonicalized -- student_id for
            // student/guardians_of_students, guardian_id for guardian.
            'domainAudienceIds' => $isDomainAudience
                ? $this->sortedUnique($announcement->domainAudienceMembers
                    ->pluck($announcement->audience_type === 'guardian' ? 'guardian_id' : 'student_id')
                    ->all())
                : [],
            // Phase 5B.3 §31/§32, extended by Phase 5C.1 closure-blocker
            // fix: cohort DEFINITION only -- see class docblock.
            // Deliberately not canonicalized/sorted like the id lists
            // above: this is a single scalar tuple, not a set.
            'academicCohort' => $isAcademicCohortAudience && $announcement->academicCohort !== null
                ? [
                    'cohortType' => $announcement->academicCohort->cohort_type,
                    'academicYearId' => $announcement->academicCohort->academic_year_id,
                    'gradeLevelId' => $announcement->academicCohort->grade_level_id,
                    'sectionId' => $announcement->academicCohort->section_id,
                    'subjectOfferingId' => $announcement->academicCohort->subject_offering_id,
                    'recipientKind' => $announcement->academicCohort->recipient_kind,
                ]
                : null,
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
