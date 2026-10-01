<?php

namespace App\Domain\Documents\Application\Retention;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * E21-D5 (docs/security/E21-RETENTION-DETERMINATION.md): a Document goes
 * only with its owner, inside the owner domain's own retention purge. It
 * never goes on an age of its own (DocumentRetentionEligibility).
 *
 * This is the Documents side of that purge, for the owner types whose
 * domain has a decided purge. Today that is the Student only (E21.2D, the
 * D7 core record). The caller runs it inside its locked purge transaction,
 * after proving the parent eligible. Rows go immediately, active and
 * archived alike (archive is not retention). The caller deletes the
 * returned bytes after commit (ObjectDeletion).
 */
final class DocumentParentRetention
{
    /** @return list<string> ids of the Documents a Student's core purge removes */
    public function idsOwnedByStudent(string $studentId): array
    {
        return DB::table('documents')->where('student_id', $studentId)->orderBy('id')->pluck('id')->all();
    }

    /** @return list<object{storage_disk: string, storage_path: string}> */
    public function purgeWithStudent(string $studentId): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A Document is purged only inside its parent purge transaction.');
        }

        $objects = DB::table('documents')->where('student_id', $studentId)->orderBy('id')->get(['storage_disk', 'storage_path'])->all();
        DB::table('documents')->where('student_id', $studentId)->delete();

        return $objects;
    }
}
