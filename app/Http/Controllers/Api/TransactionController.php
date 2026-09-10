<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTransactionRequest;
use App\Http\Requests\Api\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\CreditCard;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactionService
    ) {}

    /**
     * Display a listing of transactions for the active workspace with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->workspace()
            ->transactions()
            ->with(['bankAccount', 'creditCard', 'category'])
            ->orderBy('occurred_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', $request->input('bank_account_id'));
        }

        if ($request->filled('credit_card_id')) {
            $query->where('credit_card_id', $request->input('credit_card_id'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('month_year')) {
            // format: YYYY-MM
            [$year, $month] = explode('-', $request->input('month_year'));
            $query->whereYear('occurred_at', $year)->whereMonth('occurred_at', $month);
        } elseif ($request->filled('year')) {
            $query->whereYear('occurred_at', $request->input('year'));
            if ($request->filled('month')) {
                $query->whereMonth('occurred_at', $request->input('month'));
            }
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('occurred_at', [$request->input('start_date'), $request->input('end_date')]);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('installments_only')) {
            $query->whereNotNull('installment_group_id');
        }

        // Summary calculations on the filtered dataset
        $totalIncome = (clone $query)->where('type', TransactionType::Income)->sum('amount');
        $totalExpenses = (clone $query)->where('type', TransactionType::Expense)->sum('amount');

        $perPage = (int) $request->input('per_page', 25);
        $transactions = $query->paginate($perPage);

        return response()->json([
            'data' => TransactionResource::collection($transactions),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'summary' => [
                    'total_income' => (float) $totalIncome,
                    'total_expenses' => (float) $totalExpenses,
                    'period_balance' => round((float) $totalIncome - (float) $totalExpenses, 2),
                ],
            ],
        ]);
    }

    /**
     * Store a newly created transaction.
     */
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $this->ensureWorkspaceRelations($request, $request->validated());

        $data = array_merge($request->validated(), [
            'workspace_id' => $request->workspace()->id,
            'created_by_user_id' => $request->user()->id,
        ]);

        if ($request->boolean('is_installment') && (int) $request->input('installments_count', 1) > 1 && $request->filled('credit_card_id')) {
            $transactions = $this->transactionService->createInstallments(
                $data,
                (int) $request->input('installments_count')
            );
            $transactions->load(['bankAccount', 'creditCard', 'category']);

            return response()->json([
                'data' => TransactionResource::collection($transactions),
                'message' => 'Compra parcelada registrada com sucesso.',
            ], 201);
        }

        if ($request->filled('credit_card_id') && ! $request->boolean('is_installment')) {
            $card = CreditCard::find($data['credit_card_id']);
            if ($card && ($card->type ?? 'credit') === 'credit') {
                $data['status'] = TransactionStatus::Pending->value;
            }
        }

        $transaction = $this->transactionService->create($data);
        $transaction->load(['bankAccount', 'creditCard', 'category']);

        return (new TransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified transaction.
     */
    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->ensureWorkspaceTransaction($request, $transaction);

        $transaction->load(['bankAccount', 'creditCard', 'category']);

        return (new TransactionResource($transaction))->response();
    }

    /**
     * Update the specified transaction.
     */
    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->ensureWorkspaceTransaction($request, $transaction);
        $this->ensureWorkspaceRelations($request, $request->validated());

        $updated = $this->transactionService->update($transaction, $request->validated());
        $updated->load(['bankAccount', 'creditCard', 'category']);

        return (new TransactionResource($updated))->response();
    }

    /**
     * Remove the specified transaction.
     */
    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $this->ensureWorkspaceTransaction($request, $transaction);

        $this->transactionService->delete($transaction);

        return response()->json([
            'message' => 'Transaction deleted successfully.',
        ]);
    }

    private function ensureWorkspaceTransaction(Request $request, Transaction $transaction): void
    {
        if ($transaction->workspace_id !== $request->workspace()->id) {
            abort(404, 'Transaction not found.');
        }
    }

    /**
     * Ensure related entities (account, card, category) belong to active workspace.
     *
     * @param  array<string, mixed>  $data
     */
    private function ensureWorkspaceRelations(Request $request, array $data): void
    {
        $workspace = $request->workspace();

        if (! empty($data['bank_account_id'])) {
            $valid = $workspace->bankAccounts()->where('id', $data['bank_account_id'])->exists();
            if (! $valid) {
                abort(422, 'The specified bank account does not belong to the active workspace.');
            }
        }

        if (! empty($data['credit_card_id'])) {
            $valid = $workspace->creditCards()->where('id', $data['credit_card_id'])->exists();
            if (! $valid) {
                abort(422, 'The specified credit card does not belong to the active workspace.');
            }
        }

        if (! empty($data['category_id'])) {
            $valid = $workspace->categories()->where('id', $data['category_id'])->exists();
            if (! $valid) {
                abort(422, 'The specified category does not belong to the active workspace.');
            }
        }
    }
}
