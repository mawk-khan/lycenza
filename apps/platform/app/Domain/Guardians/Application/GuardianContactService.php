<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Application\Exceptions\GuardianContactConflictException;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
     * SIS 2.1 (MC-4): correct an existing contact's value and label -- active
     * or inactive; its type, primary and active state never change here
     * (reactivation is its own explicit operation). The new value is
     * normalized and hashed exactly like create(), with the CURRENT lookup key
     * version, and the ciphertext, digest and version are written together in
     * one transaction under the contact's row lock, so the old value stops
     * matching the exact-match lookup at the same instant the new one starts.
     * A changed value clears `verified_at` (it verified the old value).
     *
     * Refused, nothing written: a form opened on an older version
     * (`$expectedVersion`, see version()), or a value this Guardian already holds of this
     * type in another row (active, or inactive -- reactivate that one). Another
     * Guardian sharing the value is fine (household). Audit: field names only.
     */
    public function correct(GuardianContact $contact, string $rawValue, ?string $label, string $expectedVersion, ?User $actor = null): GuardianContact
    {
        $normalized = $this->normalize($contact->type, $rawValue);
        $label = $label === null || trim($label) === '' ? null : trim($label);

        return $this->context->withSchool($contact->school, fn () => $this->translateDuplicate($contact, fn () => DB::transaction(function () use ($contact, $normalized, $label, $expectedVersion, $actor): GuardianContact {
            $locked = GuardianContact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals(self::version($locked), $expectedVersion)) {
                throw GuardianContactConflictException::changed();
            }

            $hash = $this->hasher->hash($locked->school_id, $locked->type->value, $normalized);
            $valueChanged = $normalized !== $locked->encrypted_value;
            $changed = array_keys(array_filter(['value' => $valueChanged, 'label' => $label !== $locked->label]));
            if ($changed === []) {
                return $locked;
            }
            $this->assertNoOtherRowWith($locked, $hash);

            $locked->update([
                'encrypted_value' => $normalized,
                'lookup_hash' => $hash,
                'lookup_key_version' => $this->hasher->keyVersion(),
                'label' => $label,
            ] + ($valueChanged ? ['verified_at' => null] : []));

            $this->audit->school($locked->school, 'guardian_contact.corrected', actor: $actor, subject: $locked, metadata: [
                'guardianId' => $locked->guardian_id,
                'type' => $locked->type->value,
                'changed' => $changed,
                'isActive' => $locked->is_active,
            ]);

            return $locked->refresh();
        })));
    }

    /**
     * SIS 2.1 (MC-4): bring back THIS deactivated contact -- the same row,
     * never a new one, and never "find any inactive contact with this value".
     * Every current rule is re-run under the row lock: the stored value must
     * still normalize under today's rules, it is re-hashed with the current
     * lookup key version (so the exact-match lookup finds it again), and no
     * other row of this Guardian may hold that value. It comes back as a
     * non-primary contact; making it primary stays setPrimary(). Refused,
     * nothing written, when it is already active (a concurrent reactivation).
     */
    public function reactivate(GuardianContact $contact, ?User $actor = null): GuardianContact
    {
        return $this->context->withSchool($contact->school, fn () => $this->translateDuplicate($contact, fn () => DB::transaction(function () use ($contact, $actor): GuardianContact {
            $locked = GuardianContact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_active) {
                throw GuardianContactConflictException::alreadyActive();
            }

            try {
                $normalized = $this->normalize($locked->type, $locked->encrypted_value);
            } catch (InvalidArgumentException) {
                throw GuardianContactConflictException::noLongerValid();
            }
            $hash = $this->hasher->hash($locked->school_id, $locked->type->value, $normalized);
            $this->assertNoOtherRowWith($locked, $hash);

            $locked->update([
                'is_active' => true,
                'encrypted_value' => $normalized,
                'lookup_hash' => $hash,
                'lookup_key_version' => $this->hasher->keyVersion(),
            ]);

            $this->audit->school($locked->school, 'guardian_contact.reactivated', actor: $actor, subject: $locked, metadata: [
                'guardianId' => $locked->guardian_id,
                'type' => $locked->type->value,
            ]);

            return $locked->refresh();
        })));
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

    /**
     * SIS 2.1: the opaque version a correction form carries -- a digest of the
     * row's state (value digest, label, active, primary, last write), so any
     * change since the form was opened is detected even within the same second
     * (`updated_at` alone has one-second precision). Reveals nothing.
     */
    public static function version(GuardianContact $contact): string
    {
        return hash('sha256', implode('|', [
            $contact->id, $contact->updated_at?->toIso8601String(), $contact->lookup_hash, $contact->label ?? '',
            $contact->is_active ? '1' : '0', $contact->is_primary ? '1' : '0',
        ]));
    }

    /** The one-row-per-value rule for this Guardian and type (the unique index is the backstop). */
    private function assertNoOtherRowWith(GuardianContact $contact, string $hash): void
    {
        $other = GuardianContact::query()
            ->where('guardian_id', $contact->guardian_id)
            ->where('type', $contact->type)
            ->where('lookup_hash', $hash)
            ->whereKeyNot($contact->id)
            ->first(['id', 'is_active']);

        if ($other !== null) {
            throw GuardianContactConflictException::duplicate($other->is_active, $contact->type->value);
        }
    }

    /**
     * A concurrent write that committed the same value first trips the unique
     * index; it is the same refusal as the pre-check, never a raw SQL error.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function translateDuplicate(GuardianContact $contact, callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw GuardianContactConflictException::duplicate(true, $contact->type->value);
        }
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
