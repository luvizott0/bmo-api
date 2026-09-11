<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreStockCategoryRequest;
use App\Http\Requests\Api\UpdateStockCategoryRequest;
use App\Http\Resources\StockCategoryResource;
use App\Models\StockCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockCategoryController extends Controller
{
    /**
     * Display a listing of stock categories for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = $request->workspace()
            ->effectiveStockWorkspace()
            ->stockCategories()
            ->withCount('items')
            ->orderBy('name')
            ->get();

        return StockCategoryResource::collection($categories);
    }

    /**
     * Store a newly created stock category.
     */
    public function store(StoreStockCategoryRequest $request): JsonResponse
    {
        $category = $request->workspace()->effectiveStockWorkspace()->stockCategories()->create($request->validated());

        return (new StockCategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified stock category.
     */
    public function show(Request $request, StockCategory $stockCategory): JsonResponse
    {
        $this->ensureWorkspaceCategory($request, $stockCategory);

        return (new StockCategoryResource($stockCategory->loadCount('items')))->response();
    }

    /**
     * Update the specified stock category.
     */
    public function update(UpdateStockCategoryRequest $request, StockCategory $stockCategory): JsonResponse
    {
        $this->ensureWorkspaceCategory($request, $stockCategory);

        $stockCategory->update($request->validated());

        return (new StockCategoryResource($stockCategory->loadCount('items')))->response();
    }

    /**
     * Remove the specified stock category.
     */
    public function destroy(Request $request, StockCategory $stockCategory): JsonResponse
    {
        $this->ensureWorkspaceCategory($request, $stockCategory);

        $stockCategory->delete();

        return response()->json([
            'message' => 'Stock category deleted successfully.',
        ]);
    }

    private function ensureWorkspaceCategory(Request $request, StockCategory $stockCategory): void
    {
        if ($stockCategory->workspace_id !== $request->workspace()->effectiveStockWorkspace()->id) {
            abort(404, 'Stock category not found.');
        }
    }
}
