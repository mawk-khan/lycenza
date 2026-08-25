<?php

namespace App\Domain\Documents\Application;

use Illuminate\Http\UploadedFile;

/**
 * Phase 0E.2 -- the typed input for DocumentService::create(), used
 * instead of an unbounded array so only fields this class actually
 * declares can ever reach the service (rule: no
 * `Document::create($request->all())` equivalent anywhere in this
 * checkpoint). `classificationTier` is deliberately required, with no
 * default anywhere in this class or the service -- see
 * docs/modules/DOCUMENTS.md's classification rule.
 */
final class CreateDocumentData
{
    public function __construct(
        public readonly DocumentOwner $owner,
        public readonly string $classificationTier,
        public readonly UploadedFile $file,
    ) {}
}
