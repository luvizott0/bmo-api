<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCreditCardRequest;
use App\Http\Requests\Api\UpdateCreditCardRequest;
use App\Http\Resources\CreditCardResource;
use App\Models\CreditCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CreditCardController extends Controller
{
    /**
     * Display a listing of credit cards for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $cards = $request->workspace()
            ->creditCards()
            ->with(['bankAccount', 'user'])
            ->where(function ($query) use ($user): void {
                $query->where('is_shared', true)
                    ->orWhere('user_id', $user->id)
                    ->orWhereNull('user_id');
            })
            ->orderBy('name')
            ->get();

        return CreditCardResource::collection($cards);
    }

    /**
     * Store a newly created credit card.
     */
    public function store(StoreCreditCardRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->input('user_id') ?? $request->user()->id;
        $data['is_shared'] = $request->boolean('is_shared', true);

        $card = $request->workspace()
            ->creditCards()
            ->create($data);

        $card->load(['bankAccount', 'user']);

        return (new CreditCardResource($card))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified credit card.
     */
    public function show(Request $request, CreditCard $creditCard): JsonResponse
    {
        $this->ensureWorkspaceCard($request, $creditCard);

        $creditCard->load(['bankAccount', 'user']);

        return (new CreditCardResource($creditCard))->response();
    }

    /**
     * Update the specified credit card.
     */
    public function update(UpdateCreditCardRequest $request, CreditCard $creditCard): JsonResponse
    {
        $this->ensureWorkspaceCard($request, $creditCard);

        $data = $request->validated();
        if ($request->has('is_shared')) {
            $data['is_shared'] = $request->boolean('is_shared');
        }

        $creditCard->update($data);
        $creditCard->load(['bankAccount', 'user']);

        return (new CreditCardResource($creditCard))->response();
    }

    /**
     * Get monthly limit evolution and projected invoice amounts.
     */
    public function monthlyLimits(Request $request, CreditCard $creditCard): JsonResponse
    {
        $this->ensureWorkspaceCard($request, $creditCard);

        $months = (int) $request->input('months', 12);
        $projection = $creditCard->calculateMonthlyLimits($months);

        return response()->json([
            'data' => $projection,
            'card' => [
                'id' => $creditCard->id,
                'name' => $creditCard->name,
                'total_limit' => (float) $creditCard->total_limit,
                'available_limit' => (float) $creditCard->available_limit,
                'closing_day' => $creditCard->closing_day,
                'due_day' => $creditCard->due_day,
            ],
        ]);
    }

    /**
     * Remove the specified credit card.
     */
    public function destroy(Request $request, CreditCard $creditCard): JsonResponse
    {
        $this->ensureWorkspaceCard($request, $creditCard);

        $creditCard->delete();

        return response()->json([
            'message' => 'Credit card removed successfully.',
        ]);
    }

    private function ensureWorkspaceCard(Request $request, CreditCard $creditCard): void
    {
        if ($creditCard->workspace_id !== $request->workspace()->id) {
            abort(404, 'Credit card not found.');
        }
    }
}
