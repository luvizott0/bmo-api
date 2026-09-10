<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryPurchase>
 */
class InventoryPurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomFloat(2, 5, 40);

        return [
            'workspace_id' => Workspace::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'purchased_at' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'total_price' => round($qty * $unitPrice, 2),
            'duration_days' => 30,
            'notes' => fake()->sentence(),
        ];
    }
}
