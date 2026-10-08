<?php

namespace Database\Factories;

use App\Models\Friend;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Friend>
 */
class FriendFactory extends Factory
{
    protected $model = Friend::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->name(),
            'phone' => fake()->numerify('119########'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
