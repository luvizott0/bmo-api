<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RecordInventoryPurchaseRequest;
use App\Http\Requests\Api\StoreInventoryItemRequest;
use App\Http\Requests\Api\UpdateInventoryItemRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryItemController extends Controller
{
    /**
     * Display a listing of inventory items for the active workspace with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $workspace = $request->workspace()->effectiveStockWorkspace();
        $today = Carbon::today()->toDateString();
        $in30Days = Carbon::today()->addDays(30)->toDateString();

        $query = $workspace->inventoryItems()
            ->with(['category']);

        // Search by name, brand or notes
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('stock_category_id', $request->input('category_id'));
        }

        // Filter by status
        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'low_stock') {
                $query->where(function ($q) {
                    $q->whereRaw('quantity <= min_quantity')
                        ->orWhere('quantity', '<=', 0);
                });
            } elseif ($status === 'expired') {
                $query->whereNotNull('expiration_date')
                    ->where('expiration_date', '<', $today);
            } elseif ($status === 'expiring_soon') {
                $query->whereNotNull('expiration_date')
                    ->whereBetween('expiration_date', [$today, $in30Days]);
            } elseif ($status === 'in_stock') {
                $query->where('quantity', '>', 0);
            }
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'name_asc');
        match ($sortBy) {
            'name_desc' => $query->orderBy('name', 'desc'),
            'expiration_date' => $query->orderByRaw('expiration_date IS NULL, expiration_date ASC'),
            'price_desc' => $query->orderBy('last_price', 'desc'),
            'duration' => $query->orderByRaw('duration_days IS NULL, duration_days DESC'),
            'recent' => $query->orderBy('id', 'desc'),
            default => $query->orderBy('name', 'asc'),
        };

        // Summary calculations
        $allItems = $workspace->inventoryItems()->get();
        $totalItems = $allItems->count();
        $lowStockCount = $allItems->filter(function (InventoryItem $item) {
            return ($item->min_quantity !== null && (float) $item->quantity <= (float) $item->min_quantity)
                || (float) $item->quantity <= 0;
        })->count();

        $expiredCount = $allItems->filter(function (InventoryItem $item) use ($today) {
            return $item->expiration_date && $item->expiration_date->toDateString() < $today;
        })->count();

        $expiringSoonCount = $allItems->filter(function (InventoryItem $item) use ($today, $in30Days) {
            if (! $item->expiration_date) {
                return false;
            }
            $d = $item->expiration_date->toDateString();

            return $d >= $today && $d <= $in30Days;
        })->count();

        $totalEstimatedValue = $allItems->reduce(function ($carry, InventoryItem $item) {
            $price = (float) ($item->last_price ?? $item->average_price ?? 0);

            return $carry + ((float) $item->quantity * $price);
        }, 0.0);

        $perPage = (int) $request->input('per_page', 24);
        $items = $query->paginate($perPage);

        return response()->json([
            'data' => InventoryItemResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'summary' => [
                    'total_items' => $totalItems,
                    'low_stock_count' => $lowStockCount,
                    'expired_count' => $expiredCount,
                    'expiring_soon_count' => $expiringSoonCount,
                    'total_estimated_value' => round($totalEstimatedValue, 2),
                ],
            ],
        ]);
    }

    /**
     * Store a newly created inventory item.
     */
    public function store(StoreInventoryItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $workspace = $request->workspace()->effectiveStockWorkspace();

        if (! empty($data['stock_category_id'])) {
            $valid = $workspace->stockCategories()->where('id', $data['stock_category_id'])->exists();
            if (! $valid) {
                abort(422, 'The specified category does not belong to the active workspace.');
            }
        }

        if (isset($data['last_price']) && ! isset($data['average_price'])) {
            $data['average_price'] = $data['last_price'];
        }

        $data['workspace_id'] = $workspace->id;

        $item = $workspace->inventoryItems()->create($data);

        // Record initial purchase entry if last_price and quantity were supplied
        if (! empty($data['last_price'])) {
            $qty = isset($data['quantity']) && (float) $data['quantity'] > 0 ? (float) $data['quantity'] : 1;
            $unitPrice = (float) $data['last_price'];
            $item->purchases()->create([
                'workspace_id' => $workspace->id,
                'purchased_at' => $data['last_purchased_at'] ?? Carbon::today()->toDateString(),
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'total_price' => round($qty * $unitPrice, 2),
                'duration_days' => $data['duration_days'] ?? null,
                'notes' => 'Cadastro inicial do item',
            ]);
        }

        $item->load(['category', 'purchases']);

        return (new InventoryItemResource($item))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified inventory item with purchase history.
     */
    public function show(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        $this->ensureWorkspaceItem($request, $inventoryItem);

        $inventoryItem->load(['category', 'purchases']);

        return (new InventoryItemResource($inventoryItem))->response();
    }

    /**
     * Update the specified inventory item.
     */
    public function update(UpdateInventoryItemRequest $request, InventoryItem $inventoryItem): JsonResponse
    {
        $this->ensureWorkspaceItem($request, $inventoryItem);
        $data = $request->validated();
        $workspace = $request->workspace()->effectiveStockWorkspace();

        if (! empty($data['stock_category_id'])) {
            $valid = $workspace->stockCategories()->where('id', $data['stock_category_id'])->exists();
            if (! $valid) {
                abort(422, 'The specified category does not belong to the active workspace.');
            }
        }

        $inventoryItem->update($data);
        $inventoryItem->load(['category', 'purchases']);

        return (new InventoryItemResource($inventoryItem))->response();
    }

    /**
     * Remove the specified inventory item.
     */
    public function destroy(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        $this->ensureWorkspaceItem($request, $inventoryItem);

        $inventoryItem->delete();

        return response()->json([
            'message' => 'Inventory item deleted successfully.',
        ]);
    }

    /**
     * Consume / decrement quantity of an item (e.g. finished 1 unit).
     */
    public function consume(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        $this->ensureWorkspaceItem($request, $inventoryItem);

        $amount = (float) $request->input('quantity', 1);
        $newQuantity = max(0, (float) $inventoryItem->quantity - $amount);
        $inventoryItem->update(['quantity' => $newQuantity]);

        $inventoryItem->load(['category', 'purchases']);

        return response()->json([
            'data' => new InventoryItemResource($inventoryItem),
            'message' => 'Item consumido com sucesso.',
        ]);
    }

    /**
     * Record a new purchase / restock of an item.
     */
    public function recordPurchase(RecordInventoryPurchaseRequest $request, InventoryItem $inventoryItem): JsonResponse
    {
        $this->ensureWorkspaceItem($request, $inventoryItem);
        $workspace = $request->workspace()->effectiveStockWorkspace();
        $validated = $request->validated();

        $qty = (float) $validated['quantity'];
        $unitPrice = (float) $validated['unit_price'];
        $totalPrice = round($qty * $unitPrice, 2);

        // Find the latest purchase that occurred before or on the current purchase date
        $previousPurchase = $inventoryItem->purchases()
            ->where('purchased_at', '<', $validated['purchased_at'])
            ->latest('purchased_at')
            ->first();

        $previousDate = $previousPurchase?->purchased_at?->format('Y-m-d')
            ?? ($inventoryItem->last_purchased_at && $inventoryItem->last_purchased_at->format('Y-m-d') < $validated['purchased_at']
                ? $inventoryItem->last_purchased_at->format('Y-m-d')
                : null);

        $autoDuration = null;
        if ($previousDate) {
            $prev = Carbon::parse($previousDate)->startOfDay();
            $curr = Carbon::parse($validated['purchased_at'])->startOfDay();
            $diff = (int) $prev->diffInDays($curr, false);
            if ($diff > 0) {
                $autoDuration = $diff;
            }
        }

        // If a previous purchase concluded with this new purchase, update its cycle duration
        if ($previousPurchase && $autoDuration) {
            $previousPurchase->update(['duration_days' => $autoDuration]);
            $durationDays = $validated['duration_days'] ?? null;
        } else {
            $durationDays = $validated['duration_days'] ?? $autoDuration;
        }

        $inventoryItem->purchases()->create([
            'workspace_id' => $workspace->id,
            'purchased_at' => $validated['purchased_at'],
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'duration_days' => $durationDays,
            'notes' => $validated['notes'] ?? null,
        ]);

        // Increment current stock quantity
        $inventoryItem->quantity = (float) $inventoryItem->quantity + $qty;
        $inventoryItem->save();

        // Recalculate metrics (average_price, last_price, last_purchased_at, duration_days)
        $inventoryItem->recalculateMetrics();

        $inventoryItem->load(['category', 'purchases']);

        return (new InventoryItemResource($inventoryItem))
            ->response()
            ->setStatusCode(201);
    }

    private function ensureWorkspaceItem(Request $request, InventoryItem $inventoryItem): void
    {
        if ($inventoryItem->workspace_id !== $request->workspace()->effectiveStockWorkspace()->id) {
            abort(404, 'Inventory item not found.');
        }
    }
}
