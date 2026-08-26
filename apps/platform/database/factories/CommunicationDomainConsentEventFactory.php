<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationDomainConsentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationDomainConsentEvent> */
class CommunicationDomainConsentEventFactory extends Factory
{
    protected $model = CommunicationDomainConsentEvent::class;

    public function definition(): array
    {
        return [
            'channel' => 'email',
            'status' => 'granted',
            'recorded_at' => now(),
        ];
    }
}
