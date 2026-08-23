<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Central/platform catalog. Primary key is the capability's string
 * `key` (e.g. "school.settings.manage") -- the one deliberate exception
 * to the UUIDv7 convention, see
 * docs/architecture/adr/0019-identifier-strategy.md.
 *
 * @property string $key
 * @property string $namespace
 */
class Capability extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'label', 'description', 'namespace'];
}
