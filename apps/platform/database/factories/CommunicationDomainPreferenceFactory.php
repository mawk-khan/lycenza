<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationDomainPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationDomainPreference> */
class CommunicationDomainPreferenceFactory extends Factory
{
    protected $model = CommunicationDomainPreference::class;

    public function definition(): array
    {
        return [
            'channel' => 'email',
            'preference' => 'enabled',
        ];
    }
}
