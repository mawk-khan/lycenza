<?php

namespace App\Domain\Admissions\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A pre-Student identity, owned entirely by Admissions
 * (docs/modules/ADMISSIONS.md §2/§3/§4) -- never represented as a
 * `Student` merely because an application exists. Mirrors `Student`'s
 * own identity field shape exactly (name/date_of_birth only); these
 * are the literal facts a future conversion checkpoint copies verbatim
 * into `Student`. No contact fields -- ADMISSIONS.md §9 (hardened at
 * 1D.0A) explains why Admissions stores no guardian/applicant contact
 * data of any kind in v1.
 *
 * This is a Phase 1D.1 schema-foundation model only -- no mutation
 * service, search, or lifecycle behavior exists yet.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property Carbon $date_of_birth
 */
class Applicant extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'applicants';

    protected $fillable = [
        'school_id', 'first_name', 'middle_name', 'last_name', 'date_of_birth',
    ];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    protected static function newFactory(): ApplicantFactory
    {
        return ApplicantFactory::new();
    }

    /**
     * @return HasMany<AdmissionApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class);
    }
}
