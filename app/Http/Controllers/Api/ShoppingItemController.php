<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\ShoppingItem;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShoppingItemController extends Controller
{
    /**
     * List all shopping items in the active workspace.
     */
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        $items = $workspace->shoppingItems()
            ->with(['inventoryItem', 'stockCategory'])
            ->orderBy('is_checked', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $items,
        ]);
    }

    /**
     * Add an item to the shopping list.
     */
    public function store(Request $request): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'is_custom_item' => ['nullable', 'boolean'],
            'stock_category_id' => ['nullable', 'integer', 'exists:stock_categories,id'],
            'brand' => ['nullable', 'string', 'max:255'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'category_color' => ['nullable', 'string', 'max:20'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit' => ['nullable', 'string', 'max:20'],
            'min_quantity' => ['nullable', 'numeric', 'min:0'],
            'estimated_price' => ['nullable', 'numeric', 'min:0'],
            'actual_price' => ['nullable', 'numeric', 'min:0'],
            'expiration_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // If inventory item is provided and already exists in list, just increment quantity
        if (! empty($validated['inventory_item_id'])) {
            $existing = $workspace->shoppingItems()
                ->where('inventory_item_id', $validated['inventory_item_id'])
                ->first();

            if ($existing) {
                $existing->increment('quantity', $validated['quantity']);
                if (isset($validated['notes']) && $validated['notes']) {
                    $existing->update(['notes' => $validated['notes']]);
                }
                $existing->load(['inventoryItem', 'stockCategory']);

                return response()->json([
                    'data' => $existing,
                    'message' => 'Quantidade incrementada na lista de compras.',
                ], 200);
            }
        }

        $item = $workspace->shoppingItems()->create([
            'inventory_item_id' => $validated['inventory_item_id'] ?? null,
            'is_custom_item' => $validated['is_custom_item'] ?? false,
            'stock_category_id' => $validated['stock_category_id'] ?? null,
            'name' => $validated['name'],
            'brand' => $validated['brand'] ?? null,
            'category_name' => $validated['category_name'] ?? 'Geral',
            'category_color' => $validated['category_color'] ?? '#10b981',
            'quantity' => $validated['quantity'],
            'unit' => $validated['unit'] ?? 'un',
            'min_quantity' => $validated['min_quantity'] ?? 1,
            'estimated_price' => $validated['estimated_price'] ?? 0,
            'actual_price' => $validated['actual_price'] ?? null,
            'expiration_date' => $validated['expiration_date'] ?? null,
            'is_checked' => false,
            'notes' => $validated['notes'] ?? null,
            'added_by_user_id' => $request->user()->id,
        ]);

        $item->load(['inventoryItem', 'stockCategory']);

        return response()->json([
            'data' => $item,
            'message' => 'Item adicionado à lista de compras.',
        ], 201);
    }

    /**
     * Update an item in the shopping list (toggle checked, actual price, quantity, etc.).
     */
    public function update(Request $request, ShoppingItem $shoppingItem): JsonResponse
    {
        $workspace = $this->getWorkspace($request);
        $this->ensureItemBelongsToWorkspace($shoppingItem, $workspace);

        $validated = $request->validate([
            'quantity' => ['sometimes', 'numeric', 'min:0.01'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'actual_price' => ['nullable', 'numeric', 'min:0'],
            'estimated_price' => ['sometimes', 'numeric', 'min:0'],
            'is_checked' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'name' => ['sometimes', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'stock_category_id' => ['nullable', 'integer', 'exists:stock_categories,id'],
            'expiration_date' => ['nullable', 'date'],
        ]);

        $shoppingItem->update($validated);
        $shoppingItem->load(['inventoryItem', 'stockCategory']);

        return response()->json([
            'data' => $shoppingItem,
            'message' => 'Item atualizado.',
        ]);
    }

    /**
     * Remove an item from the shopping list.
     */
    public function destroy(Request $request, ShoppingItem $shoppingItem): JsonResponse
    {
        $workspace = $this->getWorkspace($request);
        $this->ensureItemBelongsToWorkspace($shoppingItem, $workspace);

        $shoppingItem->delete();

        return response()->json([
            'message' => 'Item removido da lista de compras.',
        ]);
    }

    /**
     * Clear all checked items from the shopping list.
     */
    public function clearChecked(Request $request): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        $count = $workspace->shoppingItems()
            ->where('is_checked', true)
            ->delete();

        return response()->json([
            'message' => "{$count} itens concluídos foram removidos da lista.",
            'deleted_count' => $count,
        ]);
    }

    /**
     * Finalize shopping: record purchases in inventory, create custom items, and remove checked items.
     */
    public function finish(Request $request): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        $validated = $request->validate([
            'store_name' => ['nullable', 'string', 'max:255'],
        ]);

        $storeName = $validated['store_name'] ?? null;
        $today = now()->toDateString();

        $checkedItems = $workspace->shoppingItems()
            ->where('is_checked', true)
            ->get();

        if ($checkedItems->isEmpty()) {
            return response()->json([
                'message' => 'Nenhum item marcado no carrinho para finalizar.',
                'restocked_count' => 0,
                'total_spent' => 0,
            ]);
        }

        $result = DB::transaction(function () use ($workspace, $checkedItems, $storeName, $today): array {
            $restockedNames = [];
            $totalSpent = 0;

            foreach ($checkedItems as $item) {
                $unitPrice = $item->actual_price !== null ? (float) $item->actual_price : (float) $item->estimated_price;
                $lineTotal = (float) $item->quantity * $unitPrice;
                $totalSpent += $lineTotal;

                if ($item->inventory_item_id) {
                    $invItem = InventoryItem::where('workspace_id', $workspace->id)
                        ->find($item->inventory_item_id);

                    if ($invItem) {
                        $invItem->recordPurchase([
                            'purchased_at' => $today,
                            'quantity' => $item->quantity,
                            'unit_price' => $unitPrice,
                            'store_name' => $storeName,
                            'notes' => $storeName ? "Compra em {$storeName}" : 'Compra via Lista de Mercado PWA',
                        ]);
                        $restockedNames[] = $invItem->name;
                    }
                } elseif ($item->is_custom_item) {
                    // Create newly bought item in inventory
                    $invItem = InventoryItem::create([
                        'workspace_id' => $workspace->id,
                        'stock_category_id' => $item->stock_category_id,
                        'name' => $item->name,
                        'brand' => $item->brand,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit ?: 'un',
                        'min_quantity' => $item->min_quantity ?? 1,
                        'last_price' => $unitPrice,
                        'average_price' => $unitPrice,
                        'expiration_date' => $item->expiration_date,
                        'is_regular_expense' => true,
                        'notes' => $item->notes ?: ($storeName ? "Criado via lista em {$storeName}" : 'Criado via lista de compras'),
                    ]);

                    $invItem->purchases()->create([
                        'workspace_id' => $workspace->id,
                        'purchased_at' => $today,
                        'quantity' => $item->quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $lineTotal,
                        'store_name' => $storeName,
                        'notes' => $storeName ? "Compra em {$storeName}" : 'Compra inicial via Lista de Mercado PWA',
                    ]);

                    $restockedNames[] = $invItem->name;
                }

                // Delete checked item from shopping list
                $item->delete();
            }

            return [
                'restocked_count' => count($restockedNames),
                'restocked_names' => $restockedNames,
                'total_spent' => $totalSpent,
                'store_name' => $storeName,
            ];
        });

        return response()->json([
            'message' => 'Compras finalizadas com sucesso!',
            'data' => $result,
        ]);
    }

    private function getWorkspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        if (! $workspace instanceof Workspace) {
            abort(403, 'Espaço não encontrado no contexto da requisição.');
        }

        return $workspace;
    }

    private function ensureItemBelongsToWorkspace(ShoppingItem $item, Workspace $workspace): void
    {
        if ($item->workspace_id !== $workspace->id) {
            abort(403, 'Este item não pertence ao espaço ativo.');
        }
    }
}
