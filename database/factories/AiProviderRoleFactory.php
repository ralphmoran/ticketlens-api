<?php

namespace Database\Factories;

use App\Models\AiProviderRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderRole>
 */
class AiProviderRoleFactory extends Factory
{
    protected $model = AiProviderRole::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label'   => $this->faker->words(2, true),
            'kind'    => AiProviderRole::KIND_CUSTOM,
        ];
    }
}
