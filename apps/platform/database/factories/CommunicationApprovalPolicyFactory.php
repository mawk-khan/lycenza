<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationApprovalPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationApprovalPolicy> */
class CommunicationApprovalPolicyFactory extends Factory
{
    protected $model = CommunicationApprovalPolicy::class;

    public function definition(): array
    {
        return [
            'require_school_wide_approval' => false,
            'require_required_communication_approval' => false,
            'require_non_privileged_sender_approval' => false,
        ];
    }
}
