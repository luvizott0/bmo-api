<?php

namespace Database\Seeders;

use App\Models\StockCategory;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class StockCategorySeeder extends Seeder
{
    /**
     * Default stock categories with distinct colors.
     *
     * @return array<int, array{name: string, color_hex: string, icon: string}>
     */
    public static function defaultCategories(): array
    {
        return [
            ['name' => 'Alimentos & Bebidas', 'color_hex' => '#10B981', 'icon' => 'apple'],
            ['name' => 'Higiene Pessoal', 'color_hex' => '#8B5CF6', 'icon' => 'sparkles'],
            ['name' => 'Limpeza', 'color_hex' => '#0284C7', 'icon' => 'droplets'],
            ['name' => 'Farmácia & Saúde', 'color_hex' => '#E11D48', 'icon' => 'pill'],
            ['name' => 'Pet', 'color_hex' => '#0D9488', 'icon' => 'heart'],
            ['name' => 'Utilidades & Casa', 'color_hex' => '#6366F1', 'icon' => 'box'],
        ];
    }

    /**
     * Seed stock categories for a specific workspace.
     */
    public static function seedForWorkspace(Workspace $workspace): void
    {
        foreach (self::defaultCategories() as $cat) {
            StockCategory::firstOrCreate([
                'workspace_id' => $workspace->id,
                'name' => $cat['name'],
            ], [
                'color_hex' => $cat['color_hex'],
                'icon' => $cat['icon'],
                'is_active' => true,
            ]);
        }
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (Workspace::all() as $workspace) {
            self::seedForWorkspace($workspace);
        }
    }
}
