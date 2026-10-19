<?php

namespace App\Domain\Students\Application;

/**
 * ORG.7D-2 (ADR 0076 §38, D2-7): the ENTIRE identity a purpose lookup gives a
 * consuming module -- no date of birth, Guardian, contact, Section, profile
 * or government identifier. Anything more needs a privacy review.
 */
final class StudentLookupCandidate
{
    public function __construct(
        public readonly string $id,
        public readonly string $displayName,
        public readonly ?string $studentNumber,
    ) {}

    /** @return array{id: string, displayName: string, studentNumber: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'displayName' => $this->displayName, 'studentNumber' => $this->studentNumber];
    }
}
