<?php

namespace App\Domain\Students\Application\Documents;

/**
 * SIS 11.5: the evidence-quality decision on a Student or Applicant document
 * (never Student lifecycle approval). NULL on a Student document means it was
 * uploaded before verification existed ("not reviewed") -- never invented.
 */
enum DocumentVerificationStatus: string
{
    case PENDING = 'pending';
    case VERIFIED = 'verified';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending verification',
            self::VERIFIED => 'Verified',
            self::REJECTED => 'Rejected',
        };
    }
}
