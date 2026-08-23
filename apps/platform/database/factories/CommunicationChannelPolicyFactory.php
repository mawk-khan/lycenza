<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationChannelPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationChannelPolicy> */
class CommunicationChannelPolicyFactory extends Factory
{
    protected $model = CommunicationChannelPolicy::class;

    public function definition(): array
    {
        return [
            'channel' => 'email',
            'optional_allowed' => true,
            'required_allowed' => true,
            'recipient_can_opt_out' => true,
        ];
    }
}
