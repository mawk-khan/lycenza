<?php

namespace App\Domain\Admissions\Application\Retention;

use App\Domain\Documents\Application\Retention\DocumentParentRetention;
use App\Support\Retention\ObjectDeletion;
use Illuminate\Support\Facades\DB;

/**
 * SIS 11.5: pre-admission documents in an Admissions retention unit. A record
 * is bound to its application (it cascades with it); its Document is the
 * Applicant's until conversion moves it to the Student. So whenever a
 * retention unit deletes applications, the Documents still owned by their
 * Applicant and collected through those applications go too, bytes after
 * commit -- whether or not the Applicant itself leaves.
 */
final class ApplicantDocumentRetention
{
    /** The tables a unit handles itself (never a dependency of the Applicant or its applications). */
    public const array HANDLED = ['applicant_documents', 'documents'];

    /**
     * Inside the unit's transaction, before the applications are deleted.
     *
     * @param  list<string>  $applicationIds
     */
    public static function purgeForApplications(string $schoolId, array $applicationIds): void
    {
        if ($applicationIds === []) {
            return;
        }
        $rows = DB::table('applicant_documents')->where('school_id', $schoolId)->whereIn('admission_application_id', $applicationIds)
            ->whereNull('moved_to_student_at')->orderBy('id')->get(['applicant_id', 'document_id'])->groupBy('applicant_id');
        foreach ($rows as $applicantId => $records) {
            ObjectDeletion::afterCommit(app(DocumentParentRetention::class)->purgeOwnedBy('applicant', (string) $applicantId, $records->pluck('document_id')->map(fn ($id) => (string) $id)->all()));
        }
    }
}
