<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationRecipient> */
class CommunicationRecipientFactory extends Factory
{
    protected $model = CommunicationRecipient::class;

    public function definition(): array
    {
        return [];
    }
}
