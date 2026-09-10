<?php

namespace Database\Factories;

use App\Models\StockCategory;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCategory>
 */
class StockCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->randomElement(['Alimentos', 'Higiene', 'Limpeza', 'Farmácia', 'Pet', 'Outros']),
            'color_hex' => fake()->randomElement(['#10B981', '#8B5CF6', '#0284C7', '#E11D48', '#0D9488', '#6366F1']),
            'icon' => 'tag',
            'is_active' => true,
        ];
    }
}
