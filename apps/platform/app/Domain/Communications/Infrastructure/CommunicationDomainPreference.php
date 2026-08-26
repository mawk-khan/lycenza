<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationDomainPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5D.2 -- a Guardian/Student domain identity's current channel
 * preference for an external (non-IN_APP) channel. Row absence means
 * "inherit existing default behavior," never "opted out." See the
 * creating migration's docblock for the full rationale, including why
 * this is current-state-only (never IN_APP, never history -- that is
 * CommunicationDomainConsentEvent's job).
 *
 * @use HasFactory<CommunicationDomainPreferenceFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $guardian_id
 * @property string|null $student_id
 * @property string $channel
 * @property string $preference
 */
class CommunicationDomainPreference extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'guardian_id', 'student_id', 'channel', 'preference'];

    protected static function newFactory(): CommunicationDomainPreferenceFactory
    {
        return CommunicationDomainPreferenceFactory::new();
    }

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isEnabled(): bool
    {
        return $this->preference === 'enabled';
    }
}
