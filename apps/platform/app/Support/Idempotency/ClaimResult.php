<?php

namespace App\Support\Idempotency;

use App\Models\ApiIdempotencyKey;

/**
 * The result of App\Support\Idempotency\IdempotencyGuard::claim().
 * `record` is null for Conflict/InProgress (there is nothing safe to
 * hand back to the caller for those outcomes).
 */
final class ClaimResult
{
    private function __construct(
        public readonly IdempotencyOutcome $outcome,
        public readonly ?ApiIdempotencyKey $record,
    ) {}

    public static function claimed(ApiIdempotencyKey $record): self
    {
        return new self(IdempotencyOutcome::New, $record);
    }

    public static function replay(ApiIdempotencyKey $record): self
    {
        return new self(IdempotencyOutcome::Replay, $record);
    }

    public static function conflict(): self
    {
        return new self(IdempotencyOutcome::Conflict, null);
    }

    public static function inProgress(): self
    {
        return new self(IdempotencyOutcome::InProgress, null);
    }
}
