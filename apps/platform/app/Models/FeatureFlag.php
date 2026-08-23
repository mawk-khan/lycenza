<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Central/platform catalog. Primary key is the flag's string `key`
 * (same pattern as Capability, ADR 0019's exception).
 *
 * @property string $key
 * @property string $label
 * @property bool $default_enabled
 */
class FeatureFlag extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_enabled' => 'boolean'];
    }
}
