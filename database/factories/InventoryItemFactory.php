<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\StockCategory;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
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
            'stock_category_id' => StockCategory::factory(),
            'name' => fake()->randomElement(['Shampoo Anticaspa', 'Detergente Neutro', 'Sabonete Líquido', 'Arroz 1kg', 'Café em Grãos']),
            'brand' => fake()->randomElement(['Head & Shoulders', 'Ypê', 'Dove', 'Camil', 'Pilão']),
            'quantity' => fake()->numberBetween(1, 10),
            'unit' => 'un',
            'min_quantity' => 2,
            'last_price' => fake()->randomFloat(2, 5, 50),
            'average_price' => fake()->randomFloat(2, 5, 50),
            'expiration_date' => fake()->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d'),
            'duration_days' => fake()->randomElement([15, 30, 45, 60]),
            'last_purchased_at' => fake()->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'is_regular_expense' => true,
            'notes' => fake()->sentence(),
        ];
    }
}
