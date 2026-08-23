<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationPreference> */
class CommunicationPreferenceFactory extends Factory
{
    protected $model = CommunicationPreference::class;

    public function definition(): array
    {
        return [
            'channel' => 'email',
            'preference' => 'enabled',
        ];
    }
}
