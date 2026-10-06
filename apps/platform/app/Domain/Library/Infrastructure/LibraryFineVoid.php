<?php

namespace App\Domain\Library\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * OPF.4 (ADR 0067 D4): the void of an erroneously assessed Library fine --
 * one per fine, insert-only, recorded in the same transaction as the
 * cancellation of its (unpaid) charge. Never a refund. Written only by
 * LibraryFineService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $library_fine_id
 * @property string $reason
 * @property string|null $voided_by_user_id
 * @property Carbon|null $created_at
 */
class LibraryFineVoid extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'library_fine_voids';

    protected $fillable = ['school_id', 'library_fine_id', 'reason', 'voided_by_user_id'];
}
