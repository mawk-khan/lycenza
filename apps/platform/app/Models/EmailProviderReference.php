<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 0O.9A (ADR 0055 section 11.3, implementation amendment): the
 * platform routing index from a provider's message id to the message and
 * its School. Ids only; written by the worker that recorded the provider's
 * acceptance. It is how a provider event -- which arrives with no School
 * context -- finds the School it belongs to from STORED data, without an
 * RLS bypass and without trusting anything in the payload.
 *
 * @property string $provider
 * @property string $provider_message_id
 * @property string $email_message_id
 * @property string|null $school_id (null for identity-level email, ADR 0056)
 */
class EmailProviderReference extends Model
{
    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $primaryKey = 'provider_message_id';

    protected $keyType = 'string';

    protected $guarded = [];
}
