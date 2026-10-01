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
 * This closed map records, per owner type, which retention checkpoint owns
 * the decision. Until that checkpoint ships, `mayPurge()` answers false, so
 * no age, archive status or generic command can remove a Document early.
 * An archived Document is retained exactly like an active one: archive is
 * operational lifecycle only.
 *
 * Communications attachments are NOT Documents. They live in
 * `communication_attachments` and are purged with their communication
 * (E21.2C).
 */
final class DocumentRetentionEligibility
{
    /** owner type => the retention checkpoint that will decide it (none implemented yet). */
    public const OWNER_RETENTION = [
        'employee' => 'E21-D9 HR (E21.2E)',
        'student' => 'E21-D7 Student/academic (E21.2D)',
        'guardian' => 'E21-D7 Student/academic (E21.2D)',
        'learning_content' => 'LMS parent: E21-D7 (E21.2D) with the E21-D6 owner/audience minimum',
        'assignment' => 'LMS parent: E21-D7 (E21.2D) with the E21-D6 owner/audience minimum',
    ];

    /**
     * E21.2C: every owner type is deferred (OWNER_RETENTION), so no Document
     * is purge-eligible. A later checkpoint answers here for its own owner
     * type by asking that owner's domain, never by an age.
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
