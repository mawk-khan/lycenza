<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Communications\Application\Audience\FeeReminder\FeeReminderSelection;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ORG.9D-2 (ADR 0076 §74) -- the authored Finance selection of one fee
 * reminder (`audience_type = fee_reminder`): the population DEFINITION
 * only, never a Student id or an amount. Written once with the draft and
 * never changed (to remind a different population, start a new reminder);
 * bound by the approval fingerprint. Composite same-School keys to the
 * announcement, academic year, Campus, Grade and Section.
 *
 * @property string $id
 * @property string $school_id
 * @property string $announcement_id
 * @property string $balance_status
 * @property string|null $academic_year_id
 * @property string|null $campus_id
 * @property bool $campus_unattributed
 * @property string|null $grade_level_id
 * @property string|null $section_id
 */
class CommunicationAnnouncementFeeReminder extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'communication_announcement_fee_reminders';

    protected $fillable = [
        'school_id', 'announcement_id', 'balance_status', 'academic_year_id',
        'campus_id', 'campus_unattributed', 'grade_level_id', 'section_id',
    ];

    protected function casts(): array
    {
        return ['campus_unattributed' => 'boolean'];
    }

    /** @return BelongsTo<CommunicationAnnouncement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(CommunicationAnnouncement::class, 'announcement_id');
    }

    public function selection(): FeeReminderSelection
    {
        return new FeeReminderSelection(
            balanceStatus: $this->balance_status,
            academicYearId: $this->academic_year_id,
            campusId: $this->campus_id,
            campusUnattributed: $this->campus_unattributed,
            gradeLevelId: $this->grade_level_id,
            sectionId: $this->section_id,
        );
    }
}
