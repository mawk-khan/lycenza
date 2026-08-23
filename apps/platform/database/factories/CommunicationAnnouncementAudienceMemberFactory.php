<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncementAudienceMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationAnnouncementAudienceMember> */
class CommunicationAnnouncementAudienceMemberFactory extends Factory
{
    protected $model = CommunicationAnnouncementAudienceMember::class;

    public function definition(): array
    {
        return [];
    }
}
