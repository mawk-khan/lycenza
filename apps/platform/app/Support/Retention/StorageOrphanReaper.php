<?php

namespace App\Support\Retention;

use App\Models\School;
use App\Support\Observability\StorageMetrics;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStoragePath;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;
use Throwable;

/**
 * E21-D5 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): orphaned objects are deleted after
 * STORAGE_ORPHAN_RETENTION_DAYS (adopted: 30).
 *
 * An orphan is POSITIVELY proven. All of these must hold:
 * - the object is inside a managed keyspace of an EXISTING School:
 *   `schools/{school_id}/documents/…` on the Documents disk, or
 *   `schools/{school_id}/communications/…` on the Communications attachment
 *   disk. Nothing else in a bucket is ever listed;
 * - its last-modified time is known and older than the period;
 * - no committed row in `documents`, `communication_attachments` or
 *   `employee_documents` names it (same disk, same path). This is checked
 *   inside the School's own context, and again right before deleting it.
 *
 * Orphans arise only from a failed compensation delete (an upload whose row
 * never committed) or a failed byte delete after a retention purge. Storage
 * keys are fresh UUIDv7 per upload and never reused, so an object older
 * than the period could gain a reference only from a transaction open for
 * longer than the period. Archived Documents are rows, never orphans.
 *
 * A held School (RETENTION_HOLD_SCHOOL_IDS) is listed but nothing of it is
 * deleted (its orphans count as held). Objects under no recognisable School
 * are never touched. Listing is bounded per School (`$limit` objects).
 */
final class StorageOrphanReaper
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RetentionHolds $holds,
    ) {}

    /** @return list<array{0: string, 1: string}> [disk, fragment] */
    public static function keyspaces(): array
    {
        return [
            [(string) config('documents.disk'), 'documents'],
            [(string) config('communications.attachments.disk'), 'communications'],
        ];
    }

    /** @return array{inspected: int, eligible: int, deleted: int, held: int, errors: int} */
    public function forSchool(School $school, CarbonInterface $cutoff, int $limit, bool $dryRun): array
    {
        $held = $this->holds->isHeld($school->id);
        $result = ['inspected' => 0, 'eligible' => 0, 'deleted' => 0, 'held' => 0, 'errors' => 0];

        foreach (self::keyspaces() as [$disk, $fragment]) {
            foreach (Storage::disk($disk)->listContents(TenantStoragePath::for($school, $fragment), true) as $item) {
                /** @var StorageAttributes $item */
                if (! $item->isFile()) {
                    continue;
                }

                if (++$result['inspected'] > $limit) {
                    $result['inspected']--;

                    break 2;
                }

                $modified = $item->lastModified();
                if ($modified === null || $modified >= $cutoff->getTimestamp() || $this->referenced($school, $disk, $item->path())) {
                    continue;
                }

                $result['eligible']++;

                if ($held) {
                    $result['held']++;

                    continue;
                }

                if ($dryRun || $this->referenced($school, $disk, $item->path())) {
                    continue;
                }

                try {
                    $ok = Storage::disk($disk)->delete($item->path());
                } catch (Throwable) {
                    $ok = false;
                }

                if ($ok) {
                    $result['deleted']++;
                } else {
                    StorageMetrics::failed('delete');
                    $result['errors']++;
                }
            }
        }

        return $result;
    }

    /** @phpstan-impure the answer can change between calls (a concurrent commit) */
    private function referenced(School $school, string $disk, string $path): bool
    {
        return $this->context->withSchool($school, fn (): bool => DB::table('documents')->where('school_id', $school->id)->where('storage_disk', $disk)->where('storage_path', $path)->exists()
            || DB::table('communication_attachments')->where('school_id', $school->id)->where('storage_disk', $disk)->where('storage_path', $path)->exists()
            || DB::table('employee_documents')->where('school_id', $school->id)->where('storage_disk', $disk)->where('storage_path', $path)->exists());
    }
}
