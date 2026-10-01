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
 * - the Employee (E21.2E, D9 employment evidence).
 *
 * The caller runs it inside its locked purge transaction, after proving the
 * parent eligible. Rows go immediately, active and archived alike (archive
 * is not retention). The caller deletes the returned bytes after commit
 * (ObjectDeletion).
 */
final class DocumentParentRetention
{
    /** Owner types with a decided parent purge => their owner column. */
    private const OWNER_COLUMNS = ['student' => 'student_id', 'employee' => 'employee_id'];

    /** @return list<string> ids of the Documents the owner's purge removes */
    public function idsOwnedBy(string $owner, string $ownerId): array
    {
        return DB::table('documents')->where($this->column($owner), $ownerId)->orderBy('id')->pluck('id')->all();
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
