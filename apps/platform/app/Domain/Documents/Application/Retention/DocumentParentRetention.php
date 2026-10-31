<?php

namespace App\Domain\Documents\Application\Retention;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * E21-D5 (docs/security/E21-RETENTION-DETERMINATION.md): a Document goes
 * only with its owner, inside the owner domain's own retention purge. It
 * never goes on an age of its own (DocumentRetentionEligibility).
 *
 * This is the Documents side of that purge, for the owner types whose
 * domain has a decided purge (a closed map):
 * - the Student (E21.2D, the D7 core record);
 * - the Employee (E21.2E, D9 employment evidence);
 * - the Guardian (E21.3C, G1 Guardian personal data);
 * - LMS Learning Content and Assignments (E21.3D, A1 year-bound academic
 *   operations).
 *
 * The caller runs it inside its locked purge transaction, after proving the
 * parent eligible. Rows go immediately, active and archived alike (archive
 * is not retention). The caller deletes the returned bytes after commit
 * (ObjectDeletion).
 */
final class DocumentParentRetention
{
    /** Owner types with a decided parent purge => their owner column. */
    // SIS 11.5: an Applicant's pre-admission documents go with their application or Applicant (Admissions retention, E21.3B/E21.3C).
    private const OWNER_COLUMNS = ['student' => 'student_id', 'employee' => 'employee_id', 'guardian' => 'guardian_id', 'learning_content' => 'learning_content_id', 'assignment' => 'assignment_id', 'applicant' => 'applicant_id'];

    /** @return list<string> ids of the Documents the owner's purge removes */
    public function idsOwnedBy(string $owner, string $ownerId): array
    {
        return DB::table('documents')->where($this->column($owner), $ownerId)->orderBy('id')->pluck('id')->all();
    }

    /**
     * SIS 11.5: part of one owner's Documents -- the named ones, still owned
     * by that owner -- inside the parent purge transaction (an application's
     * pre-admission documents leave with the application while its Applicant
     * stays).
     *
     * @param  list<string>  $documentIds
     * @return list<object{storage_disk: string, storage_path: string}>
     */
    public function purgeOwnedBy(string $owner, string $ownerId, array $documentIds): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A Document is purged only inside its parent purge transaction.');
        }
        if ($documentIds === []) {
            return [];
        }

        $query = fn () => DB::table('documents')->where($this->column($owner), $ownerId)->whereIn('id', $documentIds);
        $objects = $query()->orderBy('id')->get(['storage_disk', 'storage_path'])->all();
        $query()->delete();

        return $objects;
    }

    /** @return list<object{storage_disk: string, storage_path: string}> */
    public function purgeWithOwner(string $owner, string $ownerId): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A Document is purged only inside its parent purge transaction.');
        }

        $column = $this->column($owner);
        $objects = DB::table('documents')->where($column, $ownerId)->orderBy('id')->get(['storage_disk', 'storage_path'])->all();
        DB::table('documents')->where($column, $ownerId)->delete();

        return $objects;
    }

    private function column(string $owner): string
    {
        return self::OWNER_COLUMNS[$owner] ?? throw new InvalidArgumentException("No decided parent purge for Document owner: {$owner}");
    }
}
