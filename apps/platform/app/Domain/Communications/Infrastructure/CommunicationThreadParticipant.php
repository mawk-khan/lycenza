<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Communications\Domain\CommunicationParticipantKind;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationThreadParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @use HasFactory<CommunicationThreadParticipantFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $thread_id
 * @property string $user_id
 * @property Carbon $joined_at
 * @property Carbon|null $left_at
 * @property Carbon|null $last_read_at
 * @property bool $muted
 * @property bool $archived
 * @property CommunicationParticipantKind $participant_kind
 * @property string|null $guardian_id
 * @property string|null $student_id
 */
class CommunicationThreadParticipant extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    /**
     * Phase 5A.7 §21: microsecond precision, paired with
     * CommunicationMessage's -- see that model's docblock for why.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'school_id', 'thread_id', 'user_id', 'joined_at', 'left_at',
        'last_read_at', 'muted', 'archived',
        'participant_kind', 'guardian_id', 'student_id',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_read_at' => 'datetime',
            'muted' => 'boolean',
            'archived' => 'boolean',
            'participant_kind' => CommunicationParticipantKind::class,
        ];
    }

    protected static function newFactory(): CommunicationThreadParticipantFactory
    {
        return CommunicationThreadParticipantFactory::new();
    }

    /** @return BelongsTo<CommunicationThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommunicationThread::class, 'thread_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    /**
     * Phase 5D.1 §8/§21 -- true whenever this authenticated
     * SchoolMembership-backed participant joined in a Guardian/Student
     * domain capacity rather than as a plain staff/member participant.
     * Never, by itself, a broader authorization grant (brief §21) --
     * purely provenance for display/audit.
     */
    public function hasDomainProvenance(): bool
    {
        return $this->participant_kind !== CommunicationParticipantKind::Membership;
    }
}
