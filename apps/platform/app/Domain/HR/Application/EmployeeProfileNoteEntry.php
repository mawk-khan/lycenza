<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A closure correction (item 5/2) -- one Employee Profile
 * Workspace note entry. Deliberately includes `author_display_name`
 * (resolved once, batch-hydrated exactly like Assignment's Department/
 * Position/Campus names) rather than a bare `author_user_id`, so the
 * UI never has to make a second round trip to show who wrote a note.
 */
final class EmployeeProfileNoteEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $body,
        public readonly string $classificationTier,
        public readonly ?string $authorDisplayName,
        public readonly string $createdAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'classification_tier' => $this->classificationTier,
            'author_display_name' => $this->authorDisplayName,
            'created_at' => $this->createdAt,
        ];
    }
}
