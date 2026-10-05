<?php

namespace App\Domain\Hostel\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * OPF.2 (ADR 0067 §15, D7): which FEE fee head a Hostel accommodation tier
 * bills through -- a Hostel's default (`hostel_room_id` NULL) or a per-room
 * override. Configuration only -- it never holds an amount; FEE's structure
 * instalments own every Hostel fee amount. Written only by
 * HostelFeeSelectionService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $hostel_id
 * @property string|null $hostel_room_id
 * @property string $fee_head_id
 */
class HostelFeeHead extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'hostel_fee_heads';

    protected $fillable = ['school_id', 'hostel_id', 'hostel_room_id', 'fee_head_id'];
}
