<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned data. Campus is a sub-tenant dimension, NOT a separate
 * isolation boundary (ADR 0004) -- but it is still RLS-protected like
 * any other tenant-owned table so it can never be read/written outside
 * its own School's context.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $name
 * @property string $code
 * @property string $status
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 */
class Campus extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $fillable = ['school_id', 'name', 'code', 'status', 'phone', 'email', 'address'];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
