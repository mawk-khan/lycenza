<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Communications\Domain\CommunicationConsentStatus;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationDomainConsentEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 5D.2 §13/§14 -- one append-only fact: a consent/withdrawal
 * decision recorded for a Guardian/Student domain identity's channel.
 * Never updated or deleted (database-enforced,
 * App\Support\Tenancy\TenantRls::makeAppendOnly -- see the creating
 * migration's docblock). "Current" status is derived, never stored,
 * by CommunicationConsentService's read methods (latest
 * `recorded_at`, ties broken by `id`).
 *
 * @use HasFactory<CommunicationDomainConsentEventFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $guardian_id
 * @property string|null $student_id
 * @property string $channel
 * @property CommunicationConsentStatus $status
 * @property Carbon $recorded_at
 * @property string $recorded_by_user_id
 * @property string|null $source
 * @property string|null $note
 */
class CommunicationDomainConsentEvent extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id', 'guardian_id', 'student_id', 'channel', 'status',
        'recorded_at', 'recorded_by_user_id', 'source', 'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommunicationConsentStatus::class,
            'recorded_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CommunicationDomainConsentEventFactory
    {
        return CommunicationDomainConsentEventFactory::new();
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

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function isGranted(): bool
    {
        return $this->status === CommunicationConsentStatus::Granted;
    }
}
