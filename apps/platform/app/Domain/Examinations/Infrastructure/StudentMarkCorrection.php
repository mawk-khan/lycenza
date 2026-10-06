<?php

namespace App\Domain\Examinations\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * RES.3 (ADR 0068 §7.2-§7.3, §21): one post-lock correction request -- the
 * mark and the version it corrects, the previous and proposed status and
 * value, a closed reason code, the requester and the authorization qualifying
 * at request time; then one terminal decision by a different person
 * (database CHECK). Workflow evidence only: `student_mark_revisions` stays the
 * authoritative value history. Written only by StudentMarkCorrectionService.
 *
 * @property string $id
 * @property string $student_mark_id
 * @property string $examination_paper_id
 * @property string $student_id
 * @property int $base_version
 * @property string $previous_status
 * @property string|null $previous_value
 * @property string $proposed_status
 * @property string|null $proposed_value
 * @property string $reason_code
 * @property string $status
 * @property string $requested_by_user_id
 * @property string $request_processing_authorization_id
 * @property string|null $decided_by_user_id
 * @property string|null $decision_processing_authorization_id
 */
class StudentMarkCorrection extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** The closed reason catalogue (ADR 0068 §21.3): what kind of recording error is corrected -- never a free-text remark. */
    public const REASON_ENTRY_ERROR = 'entry_error';

    public const REASON_TOTALLING_ERROR = 'totalling_error';

    public const REASON_STATUS_ERROR = 'status_error';

    public const REASONS = [self::REASON_ENTRY_ERROR, self::REASON_TOTALLING_ERROR, self::REASON_STATUS_ERROR];

    protected $table = 'student_mark_corrections';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'base_version' => 'integer',
            'previous_value' => 'decimal:2',
            'proposed_value' => 'decimal:2',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
