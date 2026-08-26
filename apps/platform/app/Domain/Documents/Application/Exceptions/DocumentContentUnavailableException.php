<?php

namespace App\Domain\Documents\Application\Exceptions;

/**
 * Phase 0E.3 -- thrown when an already-authorized Document's physical
 * object cannot be read: the object is missing (operational failure,
 * manual storage corruption, an external lifecycle mistake), the
 * configured disk is unreachable, or the disk itself is unknown/
 * misconfigured. Deliberately a single, safe, generic failure for all
 * of these causes -- never the raw provider exception (no S3 XML, no
 * MinIO internal error, no filesystem path) and never the persisted
 * `storage_path`/`storage_disk`, which this exception's message never
 * includes.
 *
 * Phase 0E.5: maps to HTTP 503. Always thrown BEFORE
 * `DocumentReadService::content()` returns a `DocumentContent` result
 * -- the HTTP content route therefore never sends a 200 with a broken
 * stream; this renders as a clean JSON error like any other exception
 * (docs/modules/DOCUMENTS.md "Storage failure at the HTTP layer").
 */
class DocumentContentUnavailableException extends DocumentException
{
    public function __construct()
    {
        parent::__construct(503, 'DOCUMENT_CONTENT_UNAVAILABLE', 'This document\'s content could not be read. Please try again.');
    }
}
