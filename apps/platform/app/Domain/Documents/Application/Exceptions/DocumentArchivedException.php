<?php

namespace App\Domain\Documents\Application\Exceptions;

/**
 * SIS.HARDEN-1 (SIS.EXP-R1 finding R-7): the Student-owned Document was
 * found under the same School and Student the caller already authorized,
 * but another request archived it first (an archive is final). A conflict,
 * not NOT FOUND: the caller may see this Document, archived, in its list.
 * Another School's or another Student's id is still NOT FOUND (the query is
 * scoped to both). No id or name appears in the message.
 */
class DocumentArchivedException extends DocumentException
{
    public function __construct()
    {
        parent::__construct(409, 'DOCUMENT_ARCHIVED', 'This document has already been archived.');
    }
}
