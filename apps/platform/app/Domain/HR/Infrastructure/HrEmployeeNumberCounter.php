<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Backing store for App\Domain\HR\Application\EmployeeNumberAllocator
 * only -- never queried/updated anywhere else. See
 * docs/modules/HR.md ("Employee identifier strategy").
 *
 * @property string $id
 * @property string $school_id
 * @property int $next_value
 */
class HrEmployeeNumberCounter extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'hr_employee_number_counters';

    protected $fillable = ['school_id', 'next_value'];

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }
}
