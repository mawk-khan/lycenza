<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncementChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationAnnouncementChannel> */
class CommunicationAnnouncementChannelFactory extends Factory
{
    protected $model = CommunicationAnnouncementChannel::class;

    public function definition(): array
    {
        return [
            'channel' => 'in_app',
        ];
    }
}
