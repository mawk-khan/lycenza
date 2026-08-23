<?php

namespace App\Support\Identifiers;

use Symfony\Component\Uid\UuidV7;

/**
 * Every Phase 0B+ model uses this trait for its primary key.
 * See docs/architecture/adr/0019-identifier-strategy.md for why this is
 * a real RFC 9562 UUIDv7 (via symfony/uid), generated in PHP, and never
 * a database default or Laravel's non-standard Str::orderedUuid().
 */
trait GeneratesUuidV7
{
    public static function bootGeneratesUuidV7(): void
    {
        static::creating(function ($model): void {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) new UuidV7;
            }
        });
    }

    public function initializeGeneratesUuidV7(): void
    {
        $this->keyType = 'string';
        $this->incrementing = false;
    }
}
