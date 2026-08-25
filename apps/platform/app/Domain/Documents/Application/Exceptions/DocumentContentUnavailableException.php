<?php

namespace App\Domain\Documents\Application\Exceptions;

use RuntimeException;

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
 */
class DocumentContentUnavailableException extends RuntimeException
{
    public readonly string $failureCode;

    public function __construct()
    {
        $this->failureCode = 'document_content_unavailable';

        parent::__construct('This document\'s content could not be read. Please try again.');
    }
}
