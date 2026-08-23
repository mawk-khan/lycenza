<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Privacy\ContactLookupHasher;
use App\Support\Privacy\EmailNormalizer;
use App\Support\Privacy\PhoneNormalizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for GuardianContact -- never create/
 * update a row directly, following the exact pattern
 * App\Domain\AcademicStructure\Application\AcademicYearService
 * established: validate -> normalize/hash -> write state -> audit,
 * inside one transaction, with School context enforced via
 * TenantContext::withSchool() rather than assumed ambient.
 *
 * This is what guarantees every future create/update/lookup/duplicate-
 * detection call site shares exactly one normalization and hashing
 * implementation -- the critical property the accepted brief requires.
 *
 * Audit metadata is identity-safe by construction: only guardian_id,
 * contact type, label, and boolean flags are ever recorded -- never the
 * decrypted value, the ciphertext, or the lookup hash.
 */
class GuardianContactService
{
    public function __construct(
        private readonly EmailNormalizer $emailNormalizer,
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly ContactLookupHasher $hasher,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Inserts exactly what is asked -- deliberately does NOT auto-
     * demote an existing primary contact of the same type, mirroring
     * AcademicYearService's own create()/activate() split (create()
     * never demotes a previously-active year; only the separate
     * activate() does). A caller asking to create a second active
     * primary contact for the same Guardian/type is rejected by the
     * database's partial unique index, not silently "fixed" here --
     * use setPrimary() to safely promote an existing contact.
     *
     * @param  array<string, mixed>  $attributes  Optional label/is_primary/is_active overrides.
     */
    public function create(Guardian $guardian, ContactType $type, string $rawValue, array $attributes = [], ?User $actor = null): GuardianContact
    {
        $normalized = $this->normalize($type, $rawValue);

        return $this->context->withSchool($guardian->school, fn () => DB::transaction(function () use ($guardian, $type, $normalized, $attributes, $actor) {
            $contact = GuardianContact::query()->create(array_merge([
                'school_id' => $guardian->school_id,
                'guardian_id' => $guardian->id,
                'type' => $type,
                'encrypted_value' => $normalized,
                'lookup_hash' => $this->hasher->hash($guardian->school_id, $type->value, $normalized),
                'lookup_key_version' => $this->hasher->keyVersion(),
                'is_primary' => false,
                'is_active' => true,
            ], $attributes));

            $this->audit->school($guardian->school, 'guardian_contact.added', actor: $actor, subject: $contact, metadata: [
                'guardianId' => $guardian->id,
                'type' => $type->value,
                'isPrimary' => $contact->is_primary,
            ]);

            return $contact;
        }));
    }

    /**
     * Demotes any other active primary contact of the same type on the
     * same Guardian in the SAME transaction as the promotion -- the
     * database's partial unique index
     * (guardian_contacts_one_active_primary_per_type) is the actual
     * concurrency guarantee, matching AcademicYearService::activate()'s
     * "demote then conditionally promote" pattern.
     */
    public function setPrimary(GuardianContact $contact, ?User $actor = null): GuardianContact
    {
        return $this->context->withSchool($contact->school, fn () => DB::transaction(function () use ($contact, $actor) {
            $this->demotePrimaries($contact->guardian, $contact->type, exceptId: $contact->id);
            $contact->update(['is_primary' => true]);

            $this->audit->school($contact->school, 'guardian_contact.primary_changed', actor: $actor, subject: $contact, metadata: [
                'guardianId' => $contact->guardian_id,
                'type' => $contact->type->value,
            ]);

            return $contact->refresh();
        }));
    }

    public function deactivate(GuardianContact $contact, ?User $actor = null): GuardianContact
    {
        return $this->context->withSchool($contact->school, function () use ($contact, $actor) {
            $contact->update(['is_active' => false, 'is_primary' => false]);

            $this->audit->school($contact->school, 'guardian_contact.deactivated', actor: $actor, subject: $contact, metadata: [
                'guardianId' => $contact->guardian_id,
                'type' => $contact->type->value,
            ]);

            return $contact->refresh();
        });
    }

    /**
     * Exact-match candidate lookup, same School only -- never queries
     * across Schools, never decrypts every Guardian's contacts to
     * search (searches the indexed lookup_hash column directly). May
     * legitimately return multiple Guardians (household-shared
     * contact). This is candidate detection only: it never auto-merges
     * or blocks Guardian creation.
     *
     * @return Collection<int, Guardian>
     */
    public function findCandidatesBySchool(School $school, ContactType $type, string $rawValue): Collection
    {
        $normalized = $this->normalize($type, $rawValue);
        $hash = $this->hasher->hash($school->id, $type->value, $normalized);

        return $this->context->withSchool($school, fn () => Guardian::query()
            ->whereHas('contacts', fn ($query) => $query->where('type', $type)->where('lookup_hash', $hash))
            ->get());
    }

    private function normalize(ContactType $type, string $rawValue): string
    {
        return match ($type) {
            ContactType::Email => $this->emailNormalizer->normalize($rawValue),
            ContactType::Mobile => $this->phoneNormalizer->normalize($rawValue),
        };
    }

    private function demotePrimaries(Guardian $guardian, ContactType $type, ?string $exceptId = null): void
    {
        GuardianContact::query()
            ->where('guardian_id', $guardian->id)
            ->where('type', $type)
            ->where('is_primary', true)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_primary' => false]);
    }
}
