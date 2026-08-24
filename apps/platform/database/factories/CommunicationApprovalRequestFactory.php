<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationApprovalRequest> */
class CommunicationApprovalRequestFactory extends Factory
{
    protected $model = CommunicationApprovalRequest::class;

    public function definition(): array
    {
        return [
            'requested_at' => now(),
            'fingerprint' => hash('sha256', fake()->uuid()),
            'snapshot' => [],
            'status' => 'pending',
        ];
    }
}
