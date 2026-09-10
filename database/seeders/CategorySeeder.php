<?php

namespace Database\Seeders;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Default standard categories.
     *
     * @return array<int, array{name: string, type: CategoryType, color_hex: string, icon: string}>
     */
    public static function defaultCategories(): array
    {
        return [
            ['name' => 'Food', 'type' => CategoryType::Expense, 'color_hex' => '#EF4444', 'icon' => 'utensils'],
            ['name' => 'Housing', 'type' => CategoryType::Expense, 'color_hex' => '#F59E0B', 'icon' => 'home'],
            ['name' => 'Transport', 'type' => CategoryType::Expense, 'color_hex' => '#3B82F6', 'icon' => 'car'],
            ['name' => 'Leisure', 'type' => CategoryType::Expense, 'color_hex' => '#8B5CF6', 'icon' => 'smile'],
            ['name' => 'Health', 'type' => CategoryType::Expense, 'color_hex' => '#EC4899', 'icon' => 'heart-pulse'],
            ['name' => 'Education', 'type' => CategoryType::Expense, 'color_hex' => '#6366F1', 'icon' => 'book'],
            ['name' => 'Subscriptions & Services', 'type' => CategoryType::Expense, 'color_hex' => '#14B8A6', 'icon' => 'tv'],
            ['name' => 'Other Expenses', 'type' => CategoryType::Expense, 'color_hex' => '#6B7280', 'icon' => 'more-horizontal'],
            ['name' => 'Salary', 'type' => CategoryType::Income, 'color_hex' => '#10B981', 'icon' => 'briefcase'],
            ['name' => 'Investments', 'type' => CategoryType::Income, 'color_hex' => '#059669', 'icon' => 'trending-up'],
            ['name' => 'Freelance', 'type' => CategoryType::Income, 'color_hex' => '#34D399', 'icon' => 'laptop'],
            ['name' => 'Other Income', 'type' => CategoryType::Income, 'color_hex' => '#6EE7B7', 'icon' => 'plus-circle'],
        ];
    }

    /**
     * Seed categories for a specific workspace.
     */
    public static function seedForWorkspace(Workspace $workspace): void
    {
        foreach (self::defaultCategories() as $cat) {
            Category::firstOrCreate([
                'workspace_id' => $workspace->id,
                'name' => $cat['name'],
            ], [
                'type' => $cat['type'],
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
