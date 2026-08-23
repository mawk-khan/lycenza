<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationAnnouncementRecipient> */
class CommunicationAnnouncementRecipientFactory extends Factory
{
    protected $model = CommunicationAnnouncementRecipient::class;

    public function definition(): array
    {
        return [];
    }
}
