<?php

namespace App\Domain\Documents\Application\Retention;

use App\Domain\Documents\Infrastructure\Document;

/**
 * E21-D5 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): a Document has NO retention period of its
 * own. It inherits its one owner's (`documents_exactly_one_owner_check`).
 * Its metadata and bytes go only when that owner's domain purges the
 * owner, through that domain's own retention operation.
 *
 * Documents encodes no duration and decides no other domain's retention.
 * This closed map records, per owner type, which retention decision governs
 * it. `mayPurge()` answers false for every Document, so no age, archive
 * status or generic command can remove one on its own.
 * An archived Document is retained exactly like an active one: archive is
 * operational lifecycle only.
 *
 * Communications attachments are NOT Documents. They live in
 * `communication_attachments` and are purged with their communication
 * (E21.2C).
 */
final class DocumentRetentionEligibility
{
    /** owner type => the retention decision that governs it. */
    public const OWNER_RETENTION = [
        'employee' => 'E21-D9 HR evidence (E21.2E): purged only with its Employee, by platform:employee-retention-prune',
        'student' => 'E21-D7 core (E21.2D): purged only with its Student, by platform:student-retention-prune',
        'guardian' => 'Guardian personal data: no adopted period (D10, E21.2F/E21.2G); kept',
        'learning_content' => 'LMS School content, not Student-rooted: no adopted period (E21.2G); kept, with the E21-D6 owner/audience minimum',
        'assignment' => 'LMS School content, not Student-rooted: no adopted period (E21.2G); kept, with the E21-D6 owner/audience minimum',
    ];

    /**
     * No Document is ever purged on its own, by age or by archive status:
     * this answers false for every owner type. A Student's or Employee's
     * Documents go only inside that owner's own purge (DocumentParentRetention:
     * D7 core, E21.2D; D9 evidence, E21.2E). Every other owner type has no
     * decided purge yet (OWNER_RETENTION).
     */
    public function mayPurge(Document $document): bool
    {
        return false;
    }

    public function decidedBy(Document $document): string
    {
        return self::OWNER_RETENTION[$document->owner_type] ?? 'unknown owner type (retained)';
    }
}
