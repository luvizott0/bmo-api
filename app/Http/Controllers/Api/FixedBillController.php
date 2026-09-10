<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PayFixedBillRequest;
use App\Http\Requests\Api\StoreFixedBillRequest;
use App\Http\Requests\Api\UpdateFixedBillRequest;
use App\Http\Resources\FixedBillResource;
use App\Http\Resources\TransactionResource;
use App\Models\FixedBill;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FixedBillController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactionService
    ) {}

    /**
     * Display a listing of fixed bills for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->workspace()
            ->fixedBills()
            ->with(['category', 'preferredBankAccount'])
            ->orderBy('due_day');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        } elseif ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        return FixedBillResource::collection($query->get());
    }

    /**
     * Store a newly created fixed bill.
     */
    public function store(StoreFixedBillRequest $request): JsonResponse
    {
        $bill = $request->workspace()
            ->fixedBills()
            ->create($request->validated());

        $bill->load(['category', 'preferredBankAccount']);

        return (new FixedBillResource($bill))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified fixed bill.
     */
    public function show(Request $request, FixedBill $fixedBill): JsonResponse
    {
        $this->ensureWorkspaceFixedBill($request, $fixedBill);

        $fixedBill->load(['category', 'preferredBankAccount', 'transactions']);

        return (new FixedBillResource($fixedBill))->response();
    }

    /**
     * Update the specified fixed bill.
     */
    public function update(UpdateFixedBillRequest $request, FixedBill $fixedBill): JsonResponse
    {
        $this->ensureWorkspaceFixedBill($request, $fixedBill);

        $fixedBill->update($request->validated());
        $fixedBill->load(['category', 'preferredBankAccount']);

        return (new FixedBillResource($fixedBill))->response();
    }

    /**
     * Remove the specified fixed bill.
     */
    public function destroy(Request $request, FixedBill $fixedBill): JsonResponse
    {
        $this->ensureWorkspaceFixedBill($request, $fixedBill);

        $fixedBill->delete();

        return response()->json([
            'message' => 'Fixed bill deleted successfully.',
        ]);
    }

    /**
     * Liquidate/Pay this fixed bill and generate the corresponding transaction.
     */
    public function pay(PayFixedBillRequest $request, FixedBill $fixedBill): JsonResponse
    {
        $this->ensureWorkspaceFixedBill($request, $fixedBill);

        $amount = (float) $request->input('amount', $fixedBill->estimated_amount);
        $paymentDate = $request->input('payment_date', now()->toDateString());
        $bankAccountId = $request->input('bank_account_id', $fixedBill->preferred_bank_account_id);
        $creditCardId = $request->input('credit_card_id');

        $transaction = $this->transactionService->create([
            'workspace_id' => $fixedBill->workspace_id,
            'created_by_user_id' => $request->user()->id,
            'fixed_bill_id' => $fixedBill->id,
            'category_id' => $fixedBill->category_id,
            'type' => $fixedBill->type,
            'amount' => $amount,
            'occurred_at' => $paymentDate,
            'status' => TransactionStatus::Paid,
            'description' => 'Payment: '.$fixedBill->name,
            'bank_account_id' => $bankAccountId,
            'credit_card_id' => $creditCardId,
        ]);

        return response()->json([
            'message' => 'Fixed bill paid successfully.',
            'transaction' => new TransactionResource($transaction),
        ], 201);
    }

    private function ensureWorkspaceFixedBill(Request $request, FixedBill $fixedBill): void
    {
        if ($fixedBill->workspace_id !== $request->workspace()->id) {
            abort(404, 'Fixed bill not found.');
        }
    }
}
