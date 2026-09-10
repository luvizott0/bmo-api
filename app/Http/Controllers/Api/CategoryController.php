<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCategoryRequest;
use App\Http\Requests\Api\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = $request->workspace()
            ->categories()
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $request->workspace()->categories()->create($request->validated());

        return (new CategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified category.
     */
    public function show(Request $request, Category $category): JsonResponse
    {
        $this->ensureWorkspaceCategory($request, $category);

        return (new CategoryResource($category))->response();
    }

    /**
     * Update the specified category.
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->ensureWorkspaceCategory($request, $category);

        $category->update($request->validated());

        return (new CategoryResource($category))->response();
    }

    /**
     * Remove the specified category.
     */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->ensureWorkspaceCategory($request, $category);

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }

    private function ensureWorkspaceCategory(Request $request, Category $category): void
    {
        if ($category->workspace_id !== $request->workspace()->id) {
            abort(404, 'Category not found.');
        }
    }
}
