<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(10),
            'owner_id' => null, // Usually set from tests
            'project_path' => $this->faker->unique()->slug(3, false) . '/' . $this->faker->unique()->slug(2, false),
            'is_public' => $this->faker->boolean(30),
        ];
    }
}
