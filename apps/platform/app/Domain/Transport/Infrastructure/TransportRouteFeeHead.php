<?php

namespace App\Domain\Transport\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * OPF.1 (ADR 0067 §14, D7): which FEE fee head a Transport route (pricing
 * tier) bills through. Configuration only -- it never holds an amount; FEE's
 * structure instalments own every Transport fee amount. Written only by
 * TransportFeeSelectionService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $route_id
 * @property string $fee_head_id
 */
class TransportRouteFeeHead extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'transport_route_fee_heads';

    protected $fillable = ['school_id', 'route_id', 'fee_head_id'];
}
