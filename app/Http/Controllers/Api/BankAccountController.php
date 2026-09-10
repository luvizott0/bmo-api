<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdjustBalanceRequest;
use App\Http\Requests\Api\StoreBankAccountRequest;
use App\Http\Requests\Api\UpdateBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BankAccountController extends Controller
{
    /**
     * Display a listing of bank accounts for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = $request->workspace()
            ->bankAccounts()
            ->orderBy('name')
            ->get();

        return BankAccountResource::collection($accounts);
    }

    /**
     * Store a newly created bank account.
     */
    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $account = $request->workspace()
            ->bankAccounts()
            ->create($request->validated());

        return (new BankAccountResource($account))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified bank account.
     */
    public function show(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->ensureWorkspaceAccount($request, $bankAccount);

        return (new BankAccountResource($bankAccount))->response();
    }

    /**
     * Update the specified bank account.
     */
    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): JsonResponse
    {
        $this->ensureWorkspaceAccount($request, $bankAccount);

        $bankAccount->update($request->validated());

        return (new BankAccountResource($bankAccount))->response();
    }

    /**
     * Remove the specified bank account.
     */
    public function destroy(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->ensureWorkspaceAccount($request, $bankAccount);

        $bankAccount->delete();

        return response()->json([
            'message' => 'Bank account deleted successfully.',
        ]);
    }

    /**
     * Manually adjust the current balance of the bank account.
     */
    public function adjustBalance(AdjustBalanceRequest $request, BankAccount $bankAccount): JsonResponse
    {
        $this->ensureWorkspaceAccount($request, $bankAccount);

        $bankAccount->update([
            'current_balance' => $request->input('current_balance'),
        ]);

        return (new BankAccountResource($bankAccount))->response();
    }

    private function ensureWorkspaceAccount(Request $request, BankAccount $bankAccount): void
    {
        if ($bankAccount->workspace_id !== $request->workspace()->id) {
            abort(404, 'Bank account not found.');
        }
    }
}
