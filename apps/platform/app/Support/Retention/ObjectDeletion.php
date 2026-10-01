<?php

namespace App\Support\Retention;

use App\Support\Observability\StorageMetrics;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * E21.2C/E21.2D: deletes the stored bytes of rows a retention purge has
 * already removed. It is called only AFTER the purge's database commit.
 *
 * A failed delete never rolls the purge back. It leaves an unreferenced
 * object, counted here and in the storage `delete` failure metric, which
 * `platform:storage-orphans-prune` removes once it is 30 days old (E21-D5).
 */
final class ObjectDeletion
{
    /**
     * @param  iterable<object{storage_disk: string, storage_path: string}>  $objects
     * @return int the number of objects that could not be deleted
     */
    public static function afterCommit(iterable $objects): int
    {
        $errors = 0;

        foreach ($objects as $object) {
            try {
                $ok = Storage::disk($object->storage_disk)->delete($object->storage_path);
            } catch (Throwable) {
                $ok = false;
            }

            if (! $ok) {
                StorageMetrics::failed('delete');
                $errors++;
            }
        }

        return $errors;
    }
}
