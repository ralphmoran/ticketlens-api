<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAiProvider>
 */
class UserAiProviderFactory extends Factory
{
    protected $model = UserAiProvider::class;

    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'provider'        => $this->faker->randomElement(['groq', 'anthropic', 'openai']),
            'api_key'         => 'sk-test-' . $this->faker->regexify('[a-zA-Z0-9]{32}'),
            'priority'        => 1,
            'timeout_seconds' => 5,
            'enabled'         => true,
        ];
    }
}
