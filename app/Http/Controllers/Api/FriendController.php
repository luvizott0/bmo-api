<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFriendRequest;
use App\Http\Requests\Api\UpdateFriendRequest;
use App\Http\Resources\FriendResource;
use App\Models\Friend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FriendController extends Controller
{
    /**
     * Display a listing of friends for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $friends = $request->workspace()
            ->friends()
            ->orderBy('name')
            ->get();

        return FriendResource::collection($friends);
    }

    /**
     * Store a newly created friend.
     */
    public function store(StoreFriendRequest $request): JsonResponse
    {
        $friend = $request->workspace()
            ->friends()
            ->create($request->validated());

        return (new FriendResource($friend))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified friend.
     */
    public function show(Request $request, Friend $friend): JsonResponse
    {
        $this->ensureWorkspaceFriend($request, $friend);

        return (new FriendResource($friend))->response();
    }

    /**
     * Update the specified friend.
     */
    public function update(UpdateFriendRequest $request, Friend $friend): JsonResponse
    {
        $this->ensureWorkspaceFriend($request, $friend);

        $friend->update($request->validated());

        return (new FriendResource($friend))->response();
    }

    /**
     * Remove the specified friend.
     */
    public function destroy(Request $request, Friend $friend): JsonResponse
    {
        $this->ensureWorkspaceFriend($request, $friend);

        $friend->delete();

        return response()->json([
            'message' => 'Friend deleted successfully.',
        ]);
    }

    private function ensureWorkspaceFriend(Request $request, Friend $friend): void
    {
        if ($friend->workspace_id !== $request->workspace()->id) {
            abort(404, 'Friend not found.');
        }
    }
}
