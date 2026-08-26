<?php

namespace Database\Factories;

use App\Models\AiProviderPool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderPool>
 *
 * No GroupFactory exists in this codebase (Group rows are created directly via
 * Group::create() in tests, per the established convention — see
 * RecallControllerTest::makeManager()) — group_id must be passed explicitly
 * when persisting; the default here is only safe for ->make() (no DB round-trip).
 */
class AiProviderPoolFactory extends Factory
{
    protected $model = AiProviderPool::class;

    public function definition(): array
    {
        return [
            'group_id'      => 1,
            'created_by'    => User::factory(),
            'title'         => $this->faker->words(2, true),
            'provider_type' => 'openai_compatible',
            'api_key'       => 'sk-test-' . $this->faker->regexify('[a-zA-Z0-9]{32}'),
            'endpoint'      => 'https://api.example.com/v1/chat/completions',
            'model'         => 'test-model',
            'notes'         => null,
            'enabled'       => true,
        ];
    }

    public function anthropic(): self
    {
        return $this->state([
            'provider_type' => 'anthropic',
            'endpoint'      => null,
            'model'         => 'claude-sonnet-5',
        ]);
    }
}
