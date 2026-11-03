<?php

namespace App\Domain\HR\Application\Profile;

/**
 * HR.2A (S-12): the closed catalogue of HR document record categories
 * (`employee_documents_category_check`). CLOSED, code-owned, because the
 * category decides access tier and retention handling, which a School must
 * not be able to redefine -- the SIS rule 120 discipline. Gated families are
 * deliberately absent:
 * - bank (HR-L5), family / nominee (HR-L6), statutory identifier values
 *   (HR-L7), medical / health (HRX-L1), photographs (HR-L13), background
 *   checks, recruitment, disciplinary and appraisal records;
 * - `identity_document` is the pre-HR.2A `id_proof` carried forward
 *   (existing processing, not a new one) and is always Highly Sensitive
 *   (`employee_documents_identity_tier_check`); it stores a document copy,
 *   never a structured identifier value (that stays HR-L7);
 * - there is no `other`: an unclassified file cannot get its access and
 *   retention right.
 */
enum EmployeeDocumentCategory: string
{
    case IDENTITY_DOCUMENT = 'identity_document';
    case EMPLOYMENT_CONTRACT = 'employment_contract';
    case APPOINTMENT_LETTER = 'appointment_letter';
    case QUALIFICATION_EVIDENCE = 'qualification_evidence';
    case EXPERIENCE_EVIDENCE = 'experience_evidence';
    case CERTIFICATION_EVIDENCE = 'certification_evidence';

    public function label(): string
    {
        return match ($this) {
            self::IDENTITY_DOCUMENT => 'Identity document (copy)',
            self::EMPLOYMENT_CONTRACT => 'Employment contract',
            self::APPOINTMENT_LETTER => 'Appointment letter',
            self::QUALIFICATION_EVIDENCE => 'Qualification evidence',
            self::EXPERIENCE_EVIDENCE => 'Previous experience evidence',
            self::CERTIFICATION_EVIDENCE => 'Certification evidence',
        };
    }

    /** The tier the category requires, or null when the uploader chooses. */
    public function requiredTier(): ?string
    {
        return $this === self::IDENTITY_DOCUMENT ? 'highly_sensitive' : null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return list<array{value: string, label: string, requiredTier: ?string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label(), 'requiredTier' => $c->requiredTier()], self::cases());
    }

    public static function labelFor(string $value): string
    {
        return self::tryFrom($value)?->label() ?? ($value === 'id_proof' ? self::IDENTITY_DOCUMENT->label() : $value);
    }
}
