<?php

use App\Enums\WorkspaceRole;
use App\Models\InventoryItem;
use App\Models\StockCategory;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'owner_id' => $this->user->id,
        'is_personal' => true,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => WorkspaceRole::Owner->value]);
    $this->category = StockCategory::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Higiene Pessoal',
        'color_hex' => '#8B5CF6',
    ]);
});

test('user can list stock categories of workspace', function () {
    StockCategory::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Limpeza',
        'color_hex' => '#0284C7',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/stock-categories');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Higiene Pessoal');
});

test('user can create a custom stock category', function () {
    $payload = [
        'name' => 'Bebidas & Adega',
        'color_hex' => '#9333EA',
        'icon' => 'wine',
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/stock-categories', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Bebidas & Adega')
        ->assertJsonPath('data.color_hex', '#9333EA');

    $this->assertDatabaseHas('stock_categories', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Bebidas & Adega',
    ]);
});

test('user can list inventory items and filter by search and category', function () {
    $item1 = InventoryItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'stock_category_id' => $this->category->id,
        'name' => 'Shampoo Anticaspa',
        'brand' => 'Head & Shoulders',
    ]);

    $otherCategory = StockCategory::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Limpeza']);
    InventoryItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'stock_category_id' => $otherCategory->id,
        'name' => 'Detergente Neutro',
        'brand' => 'Ypê',
    ]);

    // Filter by search query
    $searchResponse = $this->actingAs($this->user)
        ->getJson('/api/inventory-items?search=Shampoo');

    $searchResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Shampoo Anticaspa');

    // Filter by category_id
    $catResponse = $this->actingAs($this->user)
        ->getJson("/api/inventory-items?category_id={$this->category->id}");

    $catResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $item1->id);
});

test('user can create an inventory item and initial purchase is recorded', function () {
    $payload = [
        'stock_category_id' => $this->category->id,
        'name' => 'Shampoo Nutritivo',
        'brand' => 'Pantene',
        'quantity' => 2,
        'unit' => 'un',
        'min_quantity' => 1,
        'last_price' => 22.50,
        'duration_days' => 45,
        'expiration_date' => '2027-06-30',
        'notes' => 'Frasco de 400ml',
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/inventory-items', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Shampoo Nutritivo')
        ->assertJsonPath('data.brand', 'Pantene')
        ->assertJsonPath('data.last_price', 22.5)
        ->assertJsonPath('data.average_price', 22.5)
        ->assertJsonPath('data.duration_days', 45)
        ->assertJsonPath('data.category.id', $this->category->id);

    $this->assertDatabaseHas('inventory_items', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Shampoo Nutritivo',
        'quantity' => 2,
    ]);

    $this->assertDatabaseHas('inventory_purchases', [
        'workspace_id' => $this->workspace->id,
        'unit_price' => 22.50,
    ]);
});

test('user can view item details with purchase history', function () {
    $item = InventoryItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'stock_category_id' => $this->category->id,
        'name' => 'Sabonete em Barra',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/inventory-items/{$item->id}");

    $response->assertOk()
        ->assertJsonPath('data.name', 'Sabonete em Barra')
        ->assertJsonPath('data.category.name', 'Higiene Pessoal');
});

test('user can update an inventory item', function () {
    $item = InventoryItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'stock_category_id' => $this->category->id,
        'name' => 'Creme Dental',
        'quantity' => 1,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson("/api/inventory-items/{$item->id}", [
            'name' => 'Creme Dental Total 12',
            'quantity' => 3,
            'duration_days' => 30,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Creme Dental Total 12')
        ->assertJsonPath('data.quantity', 3)
        ->assertJsonPath('data.duration_days', 30);

    $this->assertDatabaseHas('inventory_items', [
        'id' => $item->id,
        'name' => 'Creme Dental Total 12',
        'quantity' => 3,
    ]);
});

test('user can delete an inventory item', function () {
    $item = InventoryItem::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/inventory-items/{$item->id}");

    $response->assertOk()
        ->assertJsonPath('message', 'Inventory item deleted successfully.');

    $this->assertDatabaseMissing('inventory_items', [
        'id' => $item->id,
    ]);
});

test('user can consume an inventory item and quantity decreases', function () {
    $item = InventoryItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'quantity' => 3,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/inventory-items/{$item->id}/consume", ['quantity' => 1]);

    $response->assertOk()
        ->assertJsonPath('data.quantity', 2);

    expect((float) $item->fresh()->quantity)->toBe(2.0);
});

test('user can record a purchase and metrics are recalculated', function () {
    $item = InventoryItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'quantity' => 1,
        'last_price' => 20.00,
        'average_price' => 20.00,
    ]);

    // Initial purchase log (1 unit @ 20.00)
    $item->purchases()->create([
        'workspace_id' => $this->workspace->id,
        'purchased_at' => '2026-08-01',
        'quantity' => 1,
        'unit_price' => 20.00,
        'total_price' => 20.00,
        'duration_days' => 30,
    ]);

    // Record new purchase (2 units @ 26.00) without duration_days -> auto calculated (31 days from 2026-08-01 to 2026-09-01)
    $response = $this->actingAs($this->user)
        ->postJson("/api/inventory-items/{$item->id}/purchases", [
            'purchased_at' => '2026-09-01',
            'quantity' => 2,
            'unit_price' => 26.00,
            'notes' => 'Supermercado Pão de Açúcar',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.quantity', 3)
        ->assertJsonPath('data.last_price', 26)
        ->assertJsonPath('data.average_price', 23) // avg(20, 26) = 23.00
        ->assertJsonPath('data.duration_days', 31); // 31 days between 2026-08-01 and 2026-09-01

    expect((float) $item->fresh()->quantity)->toBe(3.0)
        ->and((float) $item->fresh()->last_price)->toBe(26.0)
        ->and((float) $item->fresh()->average_price)->toBe(23.0)
        ->and((int) $item->fresh()->duration_days)->toBe(31);
});

test('cycle duration is automatically calculated based on previous purchase date', function () {
    $item = InventoryItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'quantity' => 1,
        'last_price' => 30.00,
        'average_price' => 30.00,
        'last_purchased_at' => '2026-07-15',
    ]);

    $item->purchases()->create([
        'workspace_id' => $this->workspace->id,
        'purchased_at' => '2026-07-15',
        'quantity' => 1,
        'unit_price' => 30.00,
        'total_price' => 30.00,
    ]);

    // Next purchase on 2026-08-20 (36 days later)
    $this->actingAs($this->user)
        ->postJson("/api/inventory-items/{$item->id}/purchases", [
            'purchased_at' => '2026-08-20',
            'quantity' => 1,
            'unit_price' => 32.00,
        ])
        ->assertCreated()
        ->assertJsonPath('data.duration_days', 36);

    // Third purchase on 2026-09-19 (30 days later)
    $response = $this->actingAs($this->user)
        ->postJson("/api/inventory-items/{$item->id}/purchases", [
            'purchased_at' => '2026-09-19',
            'quantity' => 1,
            'unit_price' => 28.00,
        ]);

    // Average duration across cycles: (36 + 30) / 2 = 33 days
    $response->assertCreated()
        ->assertJsonPath('data.duration_days', 33);
});

test('user cannot view or access items from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $foreignItem = InventoryItem::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/inventory-items/{$foreignItem->id}");

    $response->assertNotFound();
});
