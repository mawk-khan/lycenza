<?php

namespace App\Domain\Documents\Application;

use App\Domain\Documents\Infrastructure\Document;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * HR.R1-C10 (HRC-3): what the owner of an Employee's records requires of a write
 * through the generic Documents API. HR already depends on Documents, so Documents
 * states what it needs here and HR implements it (rule 4), the same inversion as
 * `EmploymentEndParticipant`. Documents never names an HR class for this.
 */
interface EmployeeDocumentPolicy
{
    /** Before an upload touches anything. A code is spent only on a real, permitted target. */
    public function beforeUpload(Request $request, School $school, string $employeeId, string $classificationTier): void;

    /** Before an archive of an Employee's Document. */
    public function beforeArchive(Request $request, School $school, Document $document): void;

    /** Inside the writing transaction. */
    public function refuseOwnRecord(User $actor, string $employeeId): void;

    /** Archives the Document through its register record, if it has one. False leaves it to the generic path. */
    public function archiveIfRegistered(School $school, Document $document, User $actor): bool;
}
