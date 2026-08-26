<?php

namespace App\Domain\Documents\Application;

/**
 * Phase 0E.3 -- the safe result of `DocumentReadService::content()`.
 * Carries only what an eventual transport layer needs: display
 * metadata plus an open PHP stream resource (`Storage::readStream()`)
 * -- never a storage client object, never `storage_disk`/
 * `storage_path`, never a public/signed URL (none exists in this
 * checkpoint).
 *
 * Stream ownership: the CALLER is responsible for closing `$stream`
 * once done consuming it (e.g. `fclose($content->stream)`), exactly
 * like any other PHP stream resource this application hands back --
 * `DocumentReadService` itself never closes it, since closing before
 * the caller has read from it would make the result useless.
 */
final class DocumentContent
{
    /**
     * @param  resource  $stream
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public $stream,
    ) {}
}
