<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationConversationPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationConversationPolicy> */
class CommunicationConversationPolicyFactory extends Factory
{
    protected $model = CommunicationConversationPolicy::class;

    public function definition(): array
    {
        return [
            'allow_guardian_conversations' => true,
            'allow_student_conversations' => false,
        ];
    }
}
